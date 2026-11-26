package report

import (
	"os"
	"path/filepath"
	"testing"
	"time"
)

func TestValidateEnforcesReportRange(t *testing.T) {
	now := time.Date(2026, 11, 26, 12, 0, 0, 0, time.UTC)
	base := Job{
		Event:       "report.generate.v1",
		ReportID:    "b6f1c0e2-0000-4000-8000-000000000001",
		Type:        "reservations_summary",
		From:        "2025-01-01",
		To:          "2025-12-31",
		RequestedBy: 1,
		RequestedAt: "2025-12-31T12:00:00Z",
	}

	if err := Validate(base, now); err != nil {
		t.Fatalf("valid one-year report was rejected: %v", err)
	}

	base.To = "2026-01-01"
	if err := Validate(base, now); err == nil {
		t.Fatal("expected a period longer than one year to be rejected")
	}

	base.From = "2026-11-27"
	base.To = "2026-11-27"
	if err := Validate(base, now); err == nil {
		t.Fatal("expected a future report period to be rejected")
	}
}

func TestWritePDFCreatesDeterministicPDF(t *testing.T) {
	directory := t.TempDir()
	job := Job{ReportID: "b6f1c0e2-0000-4000-8000-000000000001", From: "2025-01-01", To: "2025-01-31"}
	result := Result{
		Counts: map[string]int64{
			"pending":  1,
			"paid":     3,
			"refunded": 0,
			"canceled": 1,
			"rejected": 0,
		},
		PaidTotalCents: 1234567,
		GeneratedAt:    time.Date(2026, 11, 26, 12, 0, 0, 0, time.UTC),
	}

	key, err := WritePDF(job, result, directory)
	if err != nil {
		t.Fatalf("WritePDF returned an error: %v", err)
	}
	if want := "report-" + job.ReportID + ".pdf"; key != want {
		t.Fatalf("unexpected artifact key: got %q, want %q", key, want)
	}
	content, err := os.ReadFile(filepath.Join(directory, key))
	if err != nil {
		t.Fatalf("read generated PDF: %v", err)
	}
	if len(content) < 5 || string(content[:5]) != "%PDF-" {
		t.Fatal("generated artifact is not a PDF")
	}
	info, err := os.Stat(filepath.Join(directory, key))
	if err != nil {
		t.Fatalf("stat generated PDF: %v", err)
	}
	if got, want := info.Mode().Perm(), os.FileMode(0o640); got != want {
		t.Fatalf("PDF permissions = %04o, want %04o", got, want)
	}
}

func TestFormatUSDUsesMinorUnitsAndGrouping(t *testing.T) {
	if got, want := formatUSD(1234567), "$12,345.67"; got != want {
		t.Fatalf("formatUSD() = %q, want %q", got, want)
	}
}
