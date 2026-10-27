import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError, type Reservation } from '../lib/api';
import HomePage from './page';
import ReservationsPage from './reservations/page';

const server = vi.hoisted(() => ({ getHotels: vi.fn(), getReservations: vi.fn(), getAccessToken: vi.fn() }));

vi.mock('../lib/server-api', () => server);
vi.mock('next/navigation', () => ({ useRouter: () => ({ push: vi.fn(), refresh: vi.fn() }) }));
vi.mock('./reservations/ReservationCard', () => ({
  default: ({ initialReservation }: { initialReservation: Reservation }) => <article>{initialReservation.reservationId}</article>,
}));

beforeEach(() => {
  Object.values(server).forEach((mock) => mock.mockReset());
  server.getAccessToken.mockResolvedValue('jwt');
});

describe('HomePage', () => {
  it('renders the search form with hotels from the API', async () => {
    server.getHotels.mockResolvedValue([{ id: 1, name: 'Hotel Gamma', city: 'X' }]);
    render(await HomePage());
    expect(screen.getByRole('combobox')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Search stays/ })).toBeInTheDocument();
  });

  it('still renders when the hotels request fails', async () => {
    server.getHotels.mockRejectedValue(new Error('down'));
    render(await HomePage());
    expect(screen.getByRole('combobox')).toBeInTheDocument();
  });
});

describe('ReservationsPage', () => {
  it('asks signed-out users to sign in', async () => {
    server.getReservations.mockRejectedValue(new ApiError(401, 'JWT Token not found', null));
    render(await ReservationsPage());
    expect(screen.getByText('Sign in to view your reservation history.')).toBeInTheDocument();
  });

  it('shows a generic error for other failures', async () => {
    server.getReservations.mockRejectedValue(new ApiError(500, 'boom', null));
    render(await ReservationsPage());
    expect(screen.getByText('We could not load your reservations right now.')).toBeInTheDocument();
  });

  it('shows the empty state', async () => {
    server.getReservations.mockResolvedValue([]);
    render(await ReservationsPage());
    expect(screen.getByText('No reservations yet.')).toBeInTheDocument();
  });

  it('lists reservations', async () => {
    server.getReservations.mockResolvedValue([{ reservationId: 'a' }, { reservationId: 'b' }]);
    render(await ReservationsPage());
    expect(screen.getAllByRole('article')).toHaveLength(2);
  });
});
