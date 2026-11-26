import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ReportsPanel from '../../../components/admin/ReportsPanel';
import type { ReservationReport } from '../../../lib/api';

const { list, create, get, realtimeToken, socket } = vi.hoisted(() => ({
  list: vi.fn(), create: vi.fn(), get: vi.fn(), realtimeToken: vi.fn(),
  socket: { handlers: {} as Record<string, (event: never) => void>, connect: vi.fn(), disconnect: vi.fn(), getToken: undefined as (() => Promise<string>) | undefined },
}));
vi.mock('../../../lib/api', () => ({
  ApiError: class ApiError extends Error {},
  adminReportApi: { list, create, get, realtimeToken },
}));
vi.mock('centrifuge', () => ({
  Centrifuge: class {
    constructor(_url: string, options: { getToken: () => Promise<string> }) { socket.getToken = options.getToken; }
    on(name: string, handler: (event: never) => void) { socket.handlers[name] = handler; }
    connect() { socket.connect(); }
    disconnect() { socket.disconnect(); }
  },
}));

const completedReport: ReservationReport = {
  id: 'b6f1c0e2-0000-4000-8000-000000000001',
  from: '2026-01-01',
  to: '2026-01-31',
  status: 'done',
  error: null,
  createdAt: '2026-02-01T12:00:00+00:00',
  finishedAt: '2026-02-01T12:01:00+00:00',
};

beforeEach(() => {
  list.mockReset().mockResolvedValue([]);
  create.mockReset();
  get.mockReset();
  realtimeToken.mockReset().mockResolvedValue({ token: 'realtime-token' });
  socket.handlers = {};
  socket.connect.mockReset();
  socket.disconnect.mockReset();
  socket.getToken = undefined;
});
afterEach(() => vi.useRealTimers());

describe('ReportsPanel', () => {
  it('shows completed reports with a download link', async () => {
    list.mockResolvedValue([completedReport]);
    render(<ReportsPanel />);

    expect(await screen.findByRole('link', { name: 'Download PDF' }))
      .toHaveAttribute('href', `/api/admin/admin/reports/${completedReport.id}/download`);
    expect(screen.getByText('done')).toBeInTheDocument();
  });

  it('submits the selected period and adds the pending report', async () => {
    const pending = { ...completedReport, status: 'pending', finishedAt: null };
    create.mockResolvedValue(pending);
    list.mockResolvedValueOnce([]).mockResolvedValue([pending]);
    render(<ReportsPanel />);

    await userEvent.type(screen.getByLabelText('From'), '2026-01-01');
    await userEvent.type(screen.getByLabelText('To'), '2026-01-31');
    await userEvent.click(screen.getByRole('button', { name: 'Generate report' }));

    await waitFor(() => expect(create).toHaveBeenCalledWith('2026-01-01', '2026-01-31'));
    expect(await screen.findByText('pending')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Download PDF' })).not.toBeInTheDocument();
  });

  it('requires both dates before submitting', async () => {
    render(<ReportsPanel />);
    const form = screen.getByRole('button', { name: 'Generate report' }).closest('form');
    expect(form).not.toBeNull();
    expect(screen.getByLabelText('From')).toBeRequired();
    expect(screen.getByLabelText('To')).toBeRequired();
    expect((form as HTMLFormElement).checkValidity()).toBe(false);
    await userEvent.click(screen.getByRole('button', { name: 'Generate report' }));

    expect(create).not.toHaveBeenCalled();
  });

  it('reconciles periodically even when live updates are unavailable', async () => {
    vi.useFakeTimers();
    list
      .mockResolvedValueOnce([{ ...completedReport, status: 'processing', finishedAt: null }])
      .mockResolvedValueOnce([completedReport]);
    render(<ReportsPanel />);

    await act(async () => { await Promise.resolve(); });
    expect(list).toHaveBeenCalledTimes(1);

    await act(async () => { await vi.advanceTimersByTimeAsync(30000); });
    expect(list).toHaveBeenCalledTimes(2);
    expect(screen.getByRole('link', { name: 'Download PDF' })).toBeInTheDocument();

    await act(async () => { await vi.advanceTimersByTimeAsync(30000); });
    expect(list).toHaveBeenCalledTimes(3);
  });

  it('updates a report from a valid event and refreshes unknown reports on reconnect', async () => {
    const pending = { ...completedReport, status: 'pending', finishedAt: null };
    list.mockResolvedValue([pending]);
    get.mockResolvedValue(completedReport);
    const { unmount } = render(<ReportsPanel />);
    expect(await screen.findByText('pending')).toBeInTheDocument();

    await act(async () => {
      socket.handlers.publication({ channel: 'staff-reports', data: { version: 1, type: 'report.changed', reportId: completedReport.id } } as never);
    });
    expect(get).toHaveBeenCalledWith(completedReport.id);
    expect(screen.getByRole('link', { name: 'Download PDF' })).toBeInTheDocument();

    await act(async () => {
      socket.handlers.publication({ channel: 'staff-reports', data: { version: 1, type: 'report.changed', reportId: 'invalid' } } as never);
      socket.handlers.connected(undefined as never);
    });
    expect(get).toHaveBeenCalledTimes(1);
    expect(list).toHaveBeenCalledTimes(2);
    unmount();
    expect(socket.disconnect).toHaveBeenCalled();
  });

  it('refreshes again when an event arrives during an in-flight list request', async () => {
    let resolveList!: (reports: ReservationReport[]) => void;
    list.mockImplementationOnce(() => new Promise<ReservationReport[]>((resolve) => { resolveList = resolve; }))
      .mockResolvedValue([completedReport]);
    get.mockResolvedValue(completedReport);
    render(<ReportsPanel />);
    await act(async () => {
      socket.handlers.publication({ channel: 'staff-reports', data: { version: 1, type: 'report.changed', reportId: completedReport.id } } as never);
      resolveList([{ ...completedReport, status: 'pending', finishedAt: null }]);
    });
    expect(get).toHaveBeenCalledWith(completedReport.id);
    expect(screen.getByRole('link', { name: 'Download PDF' })).toBeInTheDocument();
  });

  it('refreshes unknown reports, retries tokens, and shows fallback status on disconnect', async () => {
    list.mockResolvedValueOnce([]).mockResolvedValue([completedReport]);
    render(<ReportsPanel />);
    expect(await screen.findByText('No reports yet.')).toBeInTheDocument();
    expect(await socket.getToken?.()).toBe('realtime-token');
    expect(await socket.getToken?.()).toBe('realtime-token');
    expect(realtimeToken).toHaveBeenCalledTimes(2);
    await act(async () => {
      socket.handlers.connecting(undefined as never);
      socket.handlers.publication({ channel: 'staff-reports', data: { version: 1, type: 'report.changed', reportId: completedReport.id } } as never);
    });
    expect(screen.getByText(/Live updates are unavailable/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Download PDF' })).toBeInTheDocument();
    await act(async () => { socket.handlers.connected(undefined as never); });
    expect(screen.queryByText(/Live updates are unavailable/)).not.toBeInTheDocument();
  });
});
