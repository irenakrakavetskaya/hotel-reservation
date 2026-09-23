'use client';

import { useState } from 'react';
import { ApiError, hotelApi, type Availability, type RoomType, type Hotel, type Reservation } from '../../../../../lib/api';

type BookingFormProps = {
  hotel: Hotel;
  roomType: RoomType;
  token: string | null;
  initialStartDate?: string;
  initialEndDate?: string;
  initialRoomCount?: number;
};

function dateAfter(days: number): string {
  const date = new Date();
  date.setDate(date.getDate() + days);
  return date.toISOString().slice(0, 10);
}

function formatPrice(priceInCents: number | null): string {
  if (priceInCents === null) return 'Price unavailable';
  return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(priceInCents / 100);
}

export default function BookingForm({ hotel, roomType, token, initialStartDate, initialEndDate, initialRoomCount }: BookingFormProps) {
  const [startDate, setStartDate] = useState(initialStartDate ?? dateAfter(7));
  const [endDate, setEndDate] = useState(initialEndDate ?? dateAfter(9));
  const [roomCount, setRoomCount] = useState(initialRoomCount ?? 1);
  const [availability, setAvailability] = useState<Availability | null>(null);
  const [reservation, setReservation] = useState<Reservation | null>(null);
  const [reservationId, setReservationId] = useState<string | null>(null);
  const [isChecking, setIsChecking] = useState(false);
  const [isBooking, setIsBooking] = useState(false);
  const [isPaying, setIsPaying] = useState(false);
  const [isCanceling, setIsCanceling] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function checkAvailability(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError(null);
    setReservation(null);
    setReservationId(null);
    setIsChecking(true);

    try {
      const result = await hotelApi.availability(hotel.id, roomType.id, startDate, endDate, roomCount, token);
      setAvailability(result);
      if (!result.canBook) setError(`Only ${result.minAvailableRooms} room${result.minAvailableRooms === 1 ? '' : 's'} available for these dates.`);
      else setReservationId(crypto.randomUUID());
    } catch (requestError) {
      setAvailability(null);
      setError(messageForError(requestError));
    } finally {
      setIsChecking(false);
    }
  }

  async function createReservation() {
    if (!reservationId || !availability || !availability.canBook) return;
    setError(null);
    setIsBooking(true);

    try {
      const result = await hotelApi.createReservation({
        reservationID: reservationId,
        hotelID: hotel.id,
        roomTypeID: roomType.id,
        startDate,
        endDate,
        roomCount,
      }, token);
      setReservation(result);
    } catch (requestError) {
      setError(messageForError(requestError));
    } finally {
      setIsBooking(false);
    }
  }

  async function payReservation(approved: boolean) {
    if (!reservation) return;
    setError(null);
    setIsPaying(true);

    try {
      setReservation(await hotelApi.payReservation(reservation.reservationId, approved, token));
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
      setReservation(await hotelApi.cancelReservation(reservation.reservationId, token));
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
          <input type="date" value={startDate} min={dateAfter(1)} onChange={(event) => setStartDate(event.target.value)} required />
        </label>
        <label>
          Check-out
          <input type="date" value={endDate} min={startDate} onChange={(event) => setEndDate(event.target.value)} required />
        </label>
        <label>
          Rooms
          <input type="number" min="1" max={roomType.maxOccupancy} value={roomCount} onChange={(event) => setRoomCount(Number(event.target.value))} required />
        </label>
        <button type="submit" disabled={isChecking || isBooking}>{isChecking ? 'Checking availability...' : 'Re-check availability'}</button>
      </form>
      {availability?.canBook && !reservation && (
        <div className="booking-confirmation">
          <div><span className="booking-confirmation__label">Available for your stay</span><strong>{formatPrice(availability.totalPrice)}</strong><span>{availability.nights} nights · {roomCount} room{roomCount === 1 ? '' : 's'}</span></div>
          <button type="button" onClick={createReservation} disabled={isBooking || !reservationId}>{isBooking ? 'Booking...' : 'Book this stay'}</button>
        </div>
      )}
      {reservation && (
        <div className={`booking-status booking-status--${reservation.status}`} role={reservation.status === 'rejected' ? 'alert' : 'status'}>
          <strong>{statusTitle(reservation.status)}</strong>
          <span>Confirmation ID: {reservation.reservationId}</span>
          {reservation.status === 'pending' && <div className="booking-actions"><button type="button" onClick={() => payReservation(true)} disabled={isPaying || isCanceling}>{isPaying ? 'Processing payment...' : 'Pay now'}</button><button className="button-secondary" type="button" onClick={() => payReservation(false)} disabled={isPaying || isCanceling}>Decline test payment</button></div>}
          {['pending', 'paid'].includes(reservation.status) && <button className="button-secondary" type="button" onClick={cancelReservation} disabled={isPaying || isCanceling}>{isCanceling ? 'Canceling...' : 'Cancel reservation'}</button>}
        </div>
      )}
      {error && <p className="booking-error" role="alert">{error}</p>}
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
    if (error.isUnauthorized) return 'Please sign in before checking availability or booking a stay.';
    if (error.isConflict) return 'This room was just booked by someone else. Re-check availability and try again.';
    return error.message;
  }
  return 'We could not complete that request. Please try again.';
}
