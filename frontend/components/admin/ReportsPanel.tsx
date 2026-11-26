'use client';

import { type FormEvent, useCallback, useEffect, useState } from 'react';
import { ApiError, adminReportApi, type ReservationReport } from '../../lib/api';

const POLL_INTERVAL = 2500;

function isActive(report: ReservationReport): boolean {
  return report.status === 'pending' || report.status === 'processing';
}

function errorMessage(error: unknown): string {
  if (error instanceof ApiError) return error.message;
  return 'The report service is unavailable. Please try again.';
}

export default function ReportsPanel() {
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [reports, setReports] = useState<ReservationReport[]>([]);
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const refresh = useCallback(async () => {
    const latest = await adminReportApi.list();
    setReports(latest);
    setError(null);
  }, []);

  useEffect(() => {
    let active = true;
    adminReportApi.list()
      .then((latest) => {
        if (active) setReports(latest);
      })
      .catch((cause: unknown) => {
        if (active) setError(errorMessage(cause));
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => { active = false; };
  }, []);

  useEffect(() => {
    if (!reports.some(isActive)) return;

    const timer = window.setTimeout(() => {
      refresh().catch((cause: unknown) => setError(errorMessage(cause)));
    }, POLL_INTERVAL);

    return () => window.clearTimeout(timer);
  }, [refresh, reports]);

  async function generate(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSubmitting(true);
    setError(null);
    try {
      const report = await adminReportApi.create(from, to);
      setReports((current) => [report, ...current.filter((item) => item.id !== report.id)]);
    } catch (cause) {
      setError(errorMessage(cause));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <section className="report-panel">
      <form className="report-form" onSubmit={generate}>
        <label>
          From
          <input type="date" value={from} onChange={(event) => setFrom(event.target.value)} required />
        </label>
        <label>
          To
          <input type="date" value={to} onChange={(event) => setTo(event.target.value)} required />
        </label>
        <button type="submit" disabled={submitting}>
          {submitting ? 'Requesting...' : 'Generate report'}
        </button>
      </form>

      {error && <p className="booking-error" role="alert">{error}</p>}

      <section aria-labelledby="recent-reports-heading">
        <h2 id="recent-reports-heading">Recent reports</h2>
        {loading ? <p role="status">Loading reports...</p> : reports.length === 0 ? (
          <p className="empty-state">No reports yet.</p>
        ) : (
          <div className="report-list">
            {reports.map((report) => (
              <article className="report-card" key={report.id}>
                <div>
                  <h3>{report.from} to {report.to}</h3>
                  <p>Requested {new Date(report.createdAt).toLocaleString()}</p>
                  {report.error && <p className="booking-error">{report.error}</p>}
                </div>
                <span className={`status-pill status-pill--${report.status}`} role="status">{report.status}</span>
                {report.status === 'done' && (
                  <a className="button-link" href={`/api/admin/admin/reports/${report.id}/download`}>Download PDF</a>
                )}
              </article>
            ))}
          </div>
        )}
      </section>
    </section>
  );
}
