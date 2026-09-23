import Link from 'next/link';
import { ApiError, type Reservation } from '../../lib/api';
import { getAccessToken, getReservations } from '../../lib/server-api';
import ReservationCard from './ReservationCard';

export const dynamic = 'force-dynamic';

export default async function ReservationsPage() {
  let reservations: Reservation[];
  let token: string | null;
  try {
    [reservations, token] = await Promise.all([getReservations(), getAccessToken()]);
  } catch (error) {
    const message = error instanceof ApiError && error.isUnauthorized
      ? 'Sign in to view your reservation history.'
      : 'We could not load your reservations right now.';
    return <main className="page-shell page-shell--compact"><section className="error-state"><p className="eyebrow">Your stays</p><h1>Reservations unavailable</h1><p>{message}</p><Link href="/hotels">Browse hotels</Link></section></main>;
  }

  return (
    <main className="page-shell page-shell--compact">
      <header className="page-heading"><p className="eyebrow">Your stays</p><h1>Keep every arrival in view.</h1><p className="intro">Review reservation status, payment state, and cancellation options.</p></header>
      {reservations.length > 0 ? <section className="reservation-grid" aria-label="Reservation history">{reservations.map((reservation) => <ReservationCard key={reservation.reservationId} initialReservation={reservation} token={token} />)}</section> : <section className="empty-state"><h2>No reservations yet.</h2><p>Your confirmed stays will appear here.</p><Link href="/hotels">Find a hotel <span aria-hidden="true">-&gt;</span></Link></section>}
    </main>
  );
}
