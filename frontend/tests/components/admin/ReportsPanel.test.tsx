import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ReportsPanel from '../../../components/admin/ReportsPanel';
import type { ReservationReport } from '../../../lib/api';

const { list, create } = vi.hoisted(() => ({ list: vi.fn(), create: vi.fn() }));
vi.mock('../../../lib/api', () => ({
  ApiError: class ApiError extends Error {},
  adminReportApi: { list, create },
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
    create.mockResolvedValue({ ...completedReport, status: 'pending', finishedAt: null });
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

  it('polls active reports and stops polling after completion', async () => {
    vi.useFakeTimers();
    list
      .mockResolvedValueOnce([{ ...completedReport, status: 'processing', finishedAt: null }])
      .mockResolvedValueOnce([completedReport]);
    render(<ReportsPanel />);

    await act(async () => { await Promise.resolve(); });
    expect(list).toHaveBeenCalledTimes(1);

    await act(async () => { await vi.advanceTimersByTimeAsync(2500); });
    expect(list).toHaveBeenCalledTimes(2);
    expect(screen.getByRole('link', { name: 'Download PDF' })).toBeInTheDocument();

    await act(async () => { await vi.advanceTimersByTimeAsync(5000); });
    expect(list).toHaveBeenCalledTimes(2);
  });
});
