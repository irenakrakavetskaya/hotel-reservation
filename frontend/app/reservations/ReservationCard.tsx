'use client';

import { useState } from 'react';
import { ApiError, hotelApi, type Reservation } from '../../lib/api';

export default function ReservationCard({ initialReservation, token }: { initialReservation: Reservation; token: string | null }) {
  const [reservation, setReservation] = useState(initialReservation);
  const [isCanceling, setIsCanceling] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function cancel() {
    setError(null);
    setIsCanceling(true);
    try {
      setReservation(await hotelApi.cancelReservation(reservation.reservationId, token));
    } catch (requestError) {
      setError(requestError instanceof ApiError ? requestError.message : 'Could not cancel this reservation.');
    } finally {
      setIsCanceling(false);
    }
  }

  const canCancel = ['pending', 'paid'].includes(reservation.status);
  return (
    <article className="reservation-card">
      <div className="reservation-card__header">
        <div><p className="eyebrow">Reservation</p><h2>{reservation.reservationId}</h2></div>
        <span className={`status-pill status-pill--${reservation.status}`}>{reservation.status}</span>
      </div>
      <dl className="reservation-details">
        <div><dt>Stay</dt><dd>{reservation.startDate} to {reservation.endDate}</dd></div>
        <div><dt>Rooms</dt><dd>{reservation.roomCount}</dd></div>
        <div><dt>Total</dt><dd>{formatPrice(reservation.totalPrice)}</dd></div>
      </dl>
      {canCancel && <button className="button-secondary" type="button" onClick={cancel} disabled={isCanceling}>{isCanceling ? 'Canceling...' : 'Cancel reservation'}</button>}
      {error && <p className="booking-error" role="alert">{error}</p>}
    </article>
  );
}

function formatPrice(priceInCents: number): string {
  return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(priceInCents / 100);
}
