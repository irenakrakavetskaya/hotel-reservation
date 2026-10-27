import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError, type Availability, type Hotel, type Reservation, type RoomType } from '../../../../../lib/api';
import BookingForm from './BookingForm';

const api = vi.hoisted(() => ({ availability: vi.fn(), createReservation: vi.fn(), payReservation: vi.fn(), cancelReservation: vi.fn() }));

vi.mock('next/navigation', () => ({ useRouter: () => ({ push: vi.fn(), refresh: vi.fn() }) }));
vi.mock('../../../../../lib/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../../../../../lib/api')>()),
  hotelApi: api,
}));

const hotel = { id: 3, name: 'Northline House' } as Hotel;
const roomType = { id: 2, name: 'Deluxe King', maxOccupancy: 4 } as RoomType;
const available = { canBook: true, nights: 3, totalPrice: 48195, minAvailableRooms: 6 } as Availability;

function reservation(status: string, id = 'res-1'): Reservation {
  return { reservationId: id, status } as Reservation;
}

function renderForm(props: Partial<React.ComponentProps<typeof BookingForm>> = {}) {
  return render(
    <BookingForm hotel={hotel} roomType={roomType} token="jwt" initialStartDate="2026-12-01" initialEndDate="2026-12-04" initialRoomCount={1} initialAvailability={available} {...props} />,
  );
}

const book = () => screen.getByRole('button', { name: 'Book this stay' });

beforeEach(() => {
  Object.values(api).forEach((mock) => mock.mockReset());
});

describe('BookingForm', () => {
  it('enables "Book this stay" immediately for preloaded availability and shows the summary', () => {
    renderForm();
    expect(book()).toBeEnabled();
    expect(screen.getByText('$481.95')).toBeInTheDocument();
    expect(screen.getByText(/Northline House · Deluxe King/)).toBeInTheDocument();
    expect(screen.getByText(/2026-12-01 → 2026-12-04 · 3 nights · 1 room$/)).toBeInTheDocument();
  });

  it('allows today as the earliest check-in', () => {
    renderForm();
    const min = (screen.getByLabelText(/Check-in/) as HTMLInputElement).min;
    expect(min).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    expect(min).toBe(new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10));
  });

  it('posts the reservation and shows the pending state', async () => {
    api.createReservation.mockResolvedValue(reservation('pending'));
    renderForm();
    await userEvent.click(book());

    expect(await screen.findByText('Reservation confirmed. Payment is pending.')).toBeInTheDocument();
    const [input, token] = api.createReservation.mock.calls[0];
    expect(input).toMatchObject({ hotelID: 3, roomTypeID: 2, startDate: '2026-12-01', endDate: '2026-12-04', roomCount: 1 });
    expect(input.reservationID).toMatch(/^[0-9a-f-]{36}$/);
    expect(token).toBe('jwt');
    expect(screen.getByRole('link', { name: 'View my reservations' })).toHaveAttribute('href', '/reservations');
  });

  it('reuses the same reservationID when retrying after a network failure', async () => {
    api.createReservation.mockRejectedValueOnce(new TypeError('offline')).mockResolvedValueOnce(reservation('pending'));
    renderForm();
    await userEvent.click(book());
    expect(await screen.findByText(/Network problem/)).toBeInTheDocument();
    await userEvent.click(book());
    await screen.findByText('Reservation confirmed. Payment is pending.');

    const ids = api.createReservation.mock.calls.map(([input]) => input.reservationID);
    expect(ids[0]).toBe(ids[1]);
  });

  it('generates a new reservationID after the dates change and availability is re-checked', async () => {
    api.createReservation.mockRejectedValue(new TypeError('offline'));
    api.availability.mockResolvedValue(available);
    renderForm();
    await userEvent.click(book());
    await screen.findByText(/Network problem/);

    const end = screen.getByLabelText(/Check-out/);
    await userEvent.clear(end);
    await userEvent.type(end, '2026-12-05');
    expect(screen.queryByRole('button', { name: 'Book this stay' })).not.toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Check availability' }));
    await userEvent.click(await screen.findByRole('button', { name: 'Book this stay' }));

    const ids = api.createReservation.mock.calls.map(([input]) => input.reservationID);
    expect(ids).toHaveLength(2);
    expect(ids[0]).not.toBe(ids[1]);
  });

  it('shows a sold-out message on 409', async () => {
    api.createReservation.mockRejectedValue(new ApiError(409, 'Not enough inventory', null));
    renderForm();
    await userEvent.click(book());
    expect(await screen.findByText(/These rooms just sold out/)).toBeInTheDocument();
  });

  it('opens the sign-in modal on 401 and keeps the dates', async () => {
    api.createReservation.mockRejectedValue(new ApiError(401, 'JWT Token not found', null));
    renderForm();
    await userEvent.click(book());

    expect(await screen.findByText(/session has expired/)).toBeInTheDocument();
    expect(screen.getByRole('dialog')).toHaveAttribute('open');
    expect(screen.getByLabelText(/Check-in/)).toHaveValue('2026-12-01');
  });

  it.each([
    [422, /not valid: Bad dates/],
    [500, /booking service had a problem/],
  ])('maps status %i to a clear message', async (status, message) => {
    api.createReservation.mockRejectedValue(new ApiError(status, 'Bad dates', null));
    renderForm();
    await userEvent.click(book());
    expect(await screen.findByText(message)).toBeInTheDocument();
  });

  it('asks signed-out users to sign in instead of booking', async () => {
    renderForm({ token: null });
    expect(screen.queryByRole('button', { name: 'Book this stay' })).not.toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: /Sign in to book/ }));
    expect(screen.getByRole('dialog')).toHaveAttribute('open');
  });

  it('shows the sold-out count when availability says it cannot be booked', async () => {
    api.availability.mockResolvedValue({ canBook: false, minAvailableRooms: 1 } as Availability);
    renderForm({ initialAvailability: null });
    await userEvent.click(screen.getByRole('button', { name: 'Check availability' }));
    expect(await screen.findByText('Only 1 room available for these dates.')).toBeInTheDocument();
  });

  it('pays, then cancels a reservation', async () => {
    api.createReservation.mockResolvedValue(reservation('pending'));
    api.payReservation.mockResolvedValue(reservation('paid'));
    api.cancelReservation.mockResolvedValue(reservation('canceled'));
    renderForm();
    await userEvent.click(book());
    await userEvent.click(await screen.findByRole('button', { name: 'Pay now' }));
    expect(await screen.findByText('Payment successful. Reservation confirmed.')).toBeInTheDocument();
    expect(api.payReservation).toHaveBeenCalledWith('res-1', true, 'jwt');

    await userEvent.click(screen.getByRole('button', { name: 'Cancel reservation' }));
    expect(await screen.findByText('Reservation canceled.')).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'Book another stay' }));
    await waitFor(() => expect(screen.queryByText('Reservation canceled.')).not.toBeInTheDocument());
  });

  it('shows a declined payment as an alert with "Book another stay"', async () => {
    api.createReservation.mockResolvedValue(reservation('pending'));
    api.payReservation.mockResolvedValue(reservation('rejected'));
    renderForm();
    await userEvent.click(book());
    await userEvent.click(await screen.findByRole('button', { name: 'Decline test payment' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Payment was declined.');
    expect(screen.getByRole('button', { name: 'Book another stay' })).toBeInTheDocument();
  });
});
