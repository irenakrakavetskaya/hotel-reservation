'use client';

import { useReducer } from 'react';
import { ApiError, customerApi, type Reservation } from '../../lib/api';
import { reservationCardReducer } from '../../lib/reservation-card-reducer';

export default function ReservationCard({ initialReservation }: { initialReservation: Reservation }) {
  const [{ reservation, isCanceling, error }, dispatch] = useReducer(reservationCardReducer, {
    reservation: initialReservation,
    isCanceling: false,
    error: null,
  });

  async function cancel() {
    dispatch({ type: 'cancelStarted' });
    try {
      dispatch({ type: 'cancelSucceeded', reservation: await customerApi.cancelReservation(reservation.reservationId) });
    } catch (requestError) {
      dispatch({ type: 'cancelFailed', error: requestError instanceof ApiError ? requestError.message : 'Could not cancel this reservation.' });
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
