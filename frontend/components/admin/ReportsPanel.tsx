'use client';

import { Centrifuge } from 'centrifuge';
import { type FormEvent, useEffect, useRef, useState } from 'react';
import { ApiError, adminReportApi, type ReservationReport } from '../../lib/api';

const RECONCILE_INTERVAL = 30000;
const REPORT_CHANNEL = 'staff-reports';
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

function reportIdFromEvent(data: unknown): string | null {
  if (typeof data !== 'object' || data === null || !('version' in data) || data.version !== 1
    || !('type' in data) || data.type !== 'report.changed'
    || !('reportId' in data) || typeof data.reportId !== 'string' || !UUID.test(data.reportId)) return null;
  return data.reportId;
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
  const [liveUnavailable, setLiveUnavailable] = useState(false);
  const reportsRef = useRef<ReservationReport[]>([]);
  const requestRefresh = useRef<() => void>(() => {});

  useEffect(() => {
    let active = true;
    let running = false;
    let listPending = false;
    const ids = new Set<string>();

    async function reconcile() {
      if (running) return;
      running = true;
      while (active && (listPending || ids.size > 0)) {
        const refreshList = listPending;
        const id = refreshList ? undefined : ids.values().next().value;
        if (refreshList) {
          listPending = false;
          ids.clear();
        } else if (id) {
          ids.delete(id);
        }
        try {
          const latest = refreshList || !id || !reportsRef.current.some((report) => report.id === id)
            ? await adminReportApi.list()
            : null;
          const report = !latest && id ? await adminReportApi.get(id) : null;
          if (!active) break;
          const next = latest ?? (report
            ? reportsRef.current.map((item) => item.id === report.id ? report : item)
            : reportsRef.current);
          reportsRef.current = next;
          setReports(next);
          setError(null);
        } catch (cause: unknown) {
          if (active) setError(errorMessage(cause));
        } finally {
          if (active) setLoading(false);
        }
      }
      running = false;
    }

    function queueList() {
      listPending = true;
      void reconcile();
    }
    requestRefresh.current = queueList;
    queueList();

    const scheme = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
    const client = new Centrifuge(`${scheme}//${window.location.host}/connection/websocket`, {
      getToken: async () => (await adminReportApi.realtimeToken()).token,
    });
    client.on('connected', () => {
      if (!active) return;
      setLiveUnavailable(false);
      queueList();
    });
    client.on('connecting', () => { if (active) setLiveUnavailable(true); });
    client.on('disconnected', () => { if (active) setLiveUnavailable(true); });
    client.on('publication', (context) => {
      if (!active || context.channel !== REPORT_CHANNEL) return;
      const id = reportIdFromEvent(context.data as unknown);
      if (!id) return;
      ids.add(id);
      void reconcile();
    });
    client.connect();
    const timer = window.setInterval(queueList, RECONCILE_INTERVAL);

    return () => {
      active = false;
      requestRefresh.current = () => {};
      window.clearInterval(timer);
      client.disconnect();
    };
  }, []);

  async function generate(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSubmitting(true);
    setError(null);
    try {
      const report = await adminReportApi.create(from, to);
      const next = [report, ...reportsRef.current.filter((item) => item.id !== report.id)];
      reportsRef.current = next;
      setReports(next);
      requestRefresh.current();
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
      {liveUnavailable && <p role="status">Live updates are unavailable; reports will refresh automatically.</p>}

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
