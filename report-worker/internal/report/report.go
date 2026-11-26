package report

//report logic: validation, database aggregation, and PDF output.

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/phpdave11/gofpdf"
)

var statuses = []string{"pending", "paid", "refunded", "canceled", "rejected"}
var uuidPattern = regexp.MustCompile(`^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$`)

// The queue message, JSON tags match the contract
type Job struct {
	Event       string `json:"event"`
	ReportID    string `json:"report_id"`
	Type        string `json:"type"`
	From        string `json:"from"`
	To          string `json:"to"`
	RequestedBy int64  `json:"requested_by"`
	RequestedAt string `json:"requested_at"`
}

// Calculation: count per status, paid total in cents, generation time.
type Result struct {
	Counts         map[string]int64
	PaidTotalCents int64
	GeneratedAt    time.Time
}

// Check a job before any work starts.
// Return nil if everything is fine, otherwise a descriptive error.
func Validate(job Job, now time.Time) error {
	if job.Event != "report.generate.v1" {
		return errors.New("unsupported report event")
	}
	if job.Type != "reservations_summary" {
		return errors.New("unsupported report type")
	}
	if !ValidReportID(job.ReportID) {
		return errors.New("invalid report id")
	}
	if job.RequestedBy < 1 {
		return errors.New("invalid report requester")
	}
	if _, err := time.Parse(time.RFC3339, job.RequestedAt); err != nil {
		return fmt.Errorf("invalid report request timestamp: %w", err)
	}
	from, err := time.Parse(time.DateOnly, job.From)
	if err != nil {
		return fmt.Errorf("invalid report start date: %w", err)
	}
	to, err := time.Parse(time.DateOnly, job.To)
	if err != nil {
		return fmt.Errorf("invalid report end date: %w", err)
	}
	if to.Before(from) {
		return errors.New("report end date is before the start date")
	}
	if to.AddDate(0, 0, 1).After(from.AddDate(1, 0, 0)) {
		return errors.New("report period exceeds one year")
	}
	if to.After(now.UTC().Truncate(24 * time.Hour)) {
		return errors.New("report end date is in the future")
	}

	return nil
}

// Must be a valid UUID
func ValidReportID(id string) bool {
	return uuidPattern.MatchString(id)
}

// Read the data and calculate the numbers.
func Aggregate(ctx context.Context, db *sql.DB, job Job) (Result, error) {
	//Open a read-only, repeatable-read transaction, so both queries see the same snapshot 
	// of data even if reservations change meanwhile.
	tx, err := db.BeginTx(ctx, &sql.TxOptions{Isolation: sql.LevelRepeatableRead, ReadOnly: true})
	if err != nil {
		return Result{}, fmt.Errorf("begin report read transaction: %w", err)
	}
	defer tx.Rollback()

	rows, err := tx.QueryContext(ctx, `
		SELECT status, COUNT(*)
		FROM reservation
		WHERE created_at >= ($1::date::timestamp AT TIME ZONE 'UTC')
		  AND created_at < (($2::date + 1)::timestamp AT TIME ZONE 'UTC')
		GROUP BY status
	`, job.From, job.To)
	if err != nil {
		return Result{}, fmt.Errorf("query reservation counts: %w", err)
	}

	counts := make(map[string]int64, len(statuses))
	for _, status := range statuses {
		counts[status] = 0
	}
	for rows.Next() {
		var status string
		var count int64
		if err := rows.Scan(&status, &count); err != nil {
			rows.Close()
			return Result{}, fmt.Errorf("read reservation count: %w", err)
		}
		if _, supported := counts[status]; supported {
			counts[status] = count
		}
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		return Result{}, fmt.Errorf("iterate reservation counts: %w", err)
	}
	if err := rows.Close(); err != nil {
		return Result{}, fmt.Errorf("close reservation count rows: %w", err)
	}

	var paidTotal int64
	err = tx.QueryRowContext(ctx, `
		SELECT COALESCE(SUM(total_price), 0)
		FROM reservation
		WHERE created_at >= ($1::date::timestamp AT TIME ZONE 'UTC')
		  AND created_at < (($2::date + 1)::timestamp AT TIME ZONE 'UTC')
		  AND status = 'paid'
	`, job.From, job.To).Scan(&paidTotal)
	if err != nil {
		return Result{}, fmt.Errorf("query paid reservation total: %w", err)
	}
	if err := tx.Commit(); err != nil {
		return Result{}, fmt.Errorf("commit report read transaction: %w", err)
	}

	return Result{
		Counts:         counts,
		PaidTotalCents: paidTotal,
		GeneratedAt:    time.Now().UTC(),
	}, nil
}

//Create the PDF file and return its file name
func WritePDF(job Job, result Result, directory string) (string, error) {
	if err := os.MkdirAll(directory, 0o750); err != nil {
		return "", fmt.Errorf("create report directory: %w", err)
	}

	tmp, err := os.CreateTemp(directory, ".report-*.tmp")
	if err != nil {
		return "", fmt.Errorf("create temporary report file: %w", err)
	}
	tmpPath := tmp.Name()
	if err := tmp.Close(); err != nil {
		os.Remove(tmpPath)
		return "", fmt.Errorf("close temporary report file: %w", err)
	}
	defer os.Remove(tmpPath)

	//Build the PDF with gofpdf (A4)
	pdf := gofpdf.New("P", "mm", "A4", "")
	pdf.SetTitle("Reservation Summary", false)
	pdf.SetMargins(15, 16, 15)
	pdf.AddPage()
	pdf.SetFont("Arial", "B", 18)
	pdf.CellFormat(180, 12, "Reservation Summary", "", 1, "L", false, 0, "")
	pdf.SetFont("Arial", "", 11)
	pdf.CellFormat(180, 8, fmt.Sprintf("Created: %s UTC", result.GeneratedAt.Format("2006-01-02 15:04")), "", 1, "L", false, 0, "")
	pdf.CellFormat(180, 8, fmt.Sprintf("Reservation creation dates: %s through %s (UTC)", job.From, job.To), "", 1, "L", false, 0, "")
	pdf.Ln(5)

	pdf.SetFont("Arial", "B", 12)
	pdf.CellFormat(100, 9, "Current reservation status", "1", 0, "L", false, 0, "")
	pdf.CellFormat(40, 9, "Count", "1", 1, "R", false, 0, "")
	pdf.SetFont("Arial", "", 11)
	for _, status := range statuses {
		pdf.CellFormat(100, 8, strings.ToUpper(status[:1])+status[1:], "1", 0, "L", false, 0, "")
		pdf.CellFormat(40, 8, strconv.FormatInt(result.Counts[status], 10), "1", 1, "R", false, 0, "")
	}
	pdf.SetFont("Arial", "B", 12)
	pdf.CellFormat(100, 10, "Current paid reservations total", "1", 0, "L", false, 0, "")
	pdf.CellFormat(40, 10, formatUSD(result.PaidTotalCents), "1", 1, "R", false, 0, "")

	finalPath := filepath.Join(directory, "report-"+job.ReportID+".pdf")
	if err := pdf.OutputFileAndClose(tmpPath); err != nil {
		return "", fmt.Errorf("render report PDF: %w", err)
	}
	file, err := os.OpenFile(tmpPath, os.O_RDONLY, 0)
	if err != nil {
		return "", fmt.Errorf("open rendered report: %w", err)
	}
	if err := file.Chmod(0o640); err != nil {
		file.Close()
		return "", fmt.Errorf("set rendered report permissions: %w", err)
	}
	if err := file.Sync(); err != nil {
		file.Close()
		return "", fmt.Errorf("sync rendered report: %w", err)
	}
	if err := file.Close(); err != nil {
		return "", fmt.Errorf("close rendered report: %w", err)
	}
	if err := os.Rename(tmpPath, finalPath); err != nil {
		return "", fmt.Errorf("publish rendered report: %w", err)
	}

	return filepath.Base(finalPath), nil
}

//Convert an integer amount in cents to a string like $1,234.56
func formatUSD(cents int64) string {
	negative := cents < 0
	if negative {
		cents = -cents
	}
	value := fmt.Sprintf("$%d.%02d", cents/100, cents%100)
	parts := strings.SplitN(value[1:], ".", 2)
	whole := parts[0]
	for i := len(whole) - 3; i > 0; i -= 3 {
		whole = whole[:i] + "," + whole[i:]
	}
	if negative {
		return "-$" + whole + "." + parts[1]
	}

	return "$" + whole + "." + parts[1]
}
