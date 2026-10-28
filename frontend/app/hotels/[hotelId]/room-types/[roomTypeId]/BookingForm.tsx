'use client';

import Link from 'next/link';
import { useState } from 'react';
import AuthModal from '../../../../../components/AuthModal';
import { clearReservationKey, getOrCreateReservationKey } from '../../../../../lib/reservation-key';
import { ApiError, customerApi, hotelApi, type Availability, type RoomType, type Hotel, type Reservation } from '../../../../../lib/api';

type BookingFormProps = {
  hotel: Hotel;
  roomType: RoomType;
  isSignedIn: boolean;
  initialStartDate?: string;
  initialEndDate?: string;
  initialRoomCount?: number;
  initialAvailability?: Availability | null;
};

function dateAfter(days: number): string {
  const date = new Date();
  date.setDate(date.getDate() + days);
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${date.getFullYear()}-${month}-${day}`;
}

function formatPrice(priceInCents: number | null): string {
  if (priceInCents === null) return 'Price unavailable';
  return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(priceInCents / 100);
}

export default function BookingForm({ hotel, roomType, isSignedIn, initialStartDate, initialEndDate, initialRoomCount, initialAvailability }: BookingFormProps) {
  const [startDate, setStartDate] = useState(initialStartDate ?? dateAfter(7));
  const [endDate, setEndDate] = useState(initialEndDate ?? dateAfter(9));
  const [roomCount, setRoomCount] = useState(initialRoomCount ?? 1);
  const [availability, setAvailability] = useState<Availability | null>(initialAvailability ?? null);
  const [reservation, setReservation] = useState<Reservation | null>(null);
  const [isChecking, setIsChecking] = useState(false);
  const [isBooking, setIsBooking] = useState(false);
  const [isPaying, setIsPaying] = useState(false);
  const [isCanceling, setIsCanceling] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [isAuthOpen, setIsAuthOpen] = useState(false);

  async function checkAvailability(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError(null);
    setReservation(null);
    setIsChecking(true);

    try {
      const result = await hotelApi.availability(hotel.id, roomType.id, startDate, endDate, roomCount);
      setAvailability(result);
      if (!result.canBook) setError(`Only ${result.minAvailableRooms} room${result.minAvailableRooms === 1 ? '' : 's'} available for these dates.`);
    } catch (requestError) {
      setAvailability(null);
      setError(messageForError(requestError));
    } finally {
      setIsChecking(false);
    }
  }

  async function createReservation() {
    if (!availability || !availability.canBook) return;
    // One persisted ID per stay: a retry (even after a reload) reuses it, so the POST stays idempotent.
    const stay = { hotelId: hotel.id, roomTypeId: roomType.id, startDate, endDate, roomCount };
    const reservationId = getOrCreateReservationKey(stay);
    setError(null);
    setIsBooking(true);

    try {
      const result = await customerApi.createReservation({
        reservationID: reservationId,
        hotelID: hotel.id,
        roomTypeID: roomType.id,
        startDate,
        endDate,
        roomCount,
      });
      clearReservationKey(stay);
      setReservation(result);
    } catch (requestError) {
      if (requestError instanceof ApiError && requestError.isUnauthorized) setIsAuthOpen(true);
      if (requestError instanceof ApiError && requestError.isConflict) clearReservationKey(stay);
      setError(messageForError(requestError));
    } finally {
      setIsBooking(false);
    }
  }

  function editStay(change: () => void) {
    change();
    setAvailability(null);
    setError(null);
  }

  function bookAnother() {
    setReservation(null);
    setAvailability(null);
    setError(null);
  }

  async function payReservation(approved: boolean) {
    if (!reservation) return;
    setError(null);
    setIsPaying(true);

    try {
      setReservation(await customerApi.payReservation(reservation.reservationId, approved));
    } catch (requestError) {
      setError(messageForError(requestError));
    } finally {
      setIsPaying(false);
    }
  }

  async function cancelReservation() {
    if (!reservation || !['pending', 'paid'].includes(reservation.status)) return;
    setError(null);
    setIsCanceling(true);

    try {
      setReservation(await customerApi.cancelReservation(reservation.reservationId));
    } catch (requestError) {
      setError(messageForError(requestError));
    } finally {
      setIsCanceling(false);
    }
  }

  return (
    <section className="booking-panel booking-panel--flow" aria-labelledby="booking-title">
      <p className="eyebrow">Check and reserve</p>
      <h2 id="booking-title">Make this room yours.</h2>
      <form className="booking-form" onSubmit={checkAvailability}>
        <label>
          Check-in
          <input type="date" value={startDate} min={dateAfter(0)} onChange={(event) => editStay(() => setStartDate(event.target.value))} required />
        </label>
        <label>
          Check-out
          <input type="date" value={endDate} min={startDate} onChange={(event) => editStay(() => setEndDate(event.target.value))} required />
        </label>
        <label>
          Rooms
          <input type="number" min="1" value={roomCount} onChange={(event) => editStay(() => setRoomCount(Number(event.target.value)))} required />
        </label>
        <button type="submit" disabled={isChecking || isBooking}>{isChecking ? 'Checking availability...' : availability ? 'Re-check availability' : 'Check availability'}</button>
      </form>
      {availability?.canBook && !reservation && (
        <div className="booking-confirmation">
          <div>
            <span className="booking-confirmation__label">Available for your stay</span>
            <strong>{formatPrice(availability.totalPrice)}</strong>
            <span>{hotel.name} · {roomType.name}</span>
            <span>{startDate} → {endDate} · {availability.nights} night{availability.nights === 1 ? '' : 's'} · {roomCount} room{roomCount === 1 ? '' : 's'}</span>
          </div>
          {isSignedIn ? <button type="button" onClick={createReservation} disabled={isBooking}>{isBooking ? 'Booking...' : 'Book this stay'}</button> : <button className="button-link" type="button" onClick={() => setIsAuthOpen(true)}>Sign in to book <span aria-hidden="true">-&gt;</span></button>}
        </div>
      )}
      {reservation && (
        <div className={`booking-status booking-status--${reservation.status}`} role={reservation.status === 'rejected' ? 'alert' : 'status'}>
          <strong>{statusTitle(reservation.status)}</strong>
          <span>Confirmation ID: {reservation.reservationId}</span>
          {reservation.status === 'pending' && <div className="booking-actions"><button type="button" onClick={() => payReservation(true)} disabled={isPaying || isCanceling}>{isPaying ? 'Processing payment...' : 'Pay now'}</button><button className="button-secondary" type="button" onClick={() => payReservation(false)} disabled={isPaying || isCanceling}>Decline test payment</button></div>}
          {['pending', 'paid'].includes(reservation.status) && <Link href="/reservations">View my reservations</Link>}
          {['rejected', 'canceled'].includes(reservation.status) && <button type="button" onClick={bookAnother}>Book another stay</button>}
          {['pending', 'paid'].includes(reservation.status) && <button className="button-secondary" type="button" onClick={cancelReservation} disabled={isPaying || isCanceling}>{isCanceling ? 'Canceling...' : 'Cancel reservation'}</button>}
        </div>
      )}
      {error && <p className="booking-error" role="alert">{error}</p>}
      <AuthModal open={isAuthOpen} onClose={() => setIsAuthOpen(false)} />
    </section>
  );
}

function statusTitle(status: string): string {
  return {
    pending: 'Reservation confirmed. Payment is pending.',
    paid: 'Payment successful. Reservation confirmed.',
    rejected: 'Payment was declined.',
    canceled: 'Reservation canceled.',
  }[status] ?? 'Reservation updated.';
}

function messageForError(error: unknown): string {
  if (error instanceof ApiError) {
    if (error.isUnauthorized) return 'Your session has expired or you are signed out. Sign in to continue; your dates are kept.';
    if (error.isConflict) return 'These rooms just sold out. Re-check availability or pick other dates.';
    if (error.status === 422) return `Some booking details are not valid: ${error.message}`;
    if (error.status >= 500) return 'The booking service had a problem. Try again; retrying will not create a duplicate booking.';
    return error.message;
  }
  return 'Network problem: we could not reach the server. Try again; retrying will not create a duplicate booking.';
}
