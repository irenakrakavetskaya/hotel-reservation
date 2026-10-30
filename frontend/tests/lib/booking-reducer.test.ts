import { describe, expect, it } from 'vitest';
import { bookingReducer, type BookingState } from '../../lib/booking-reducer';
import type { Availability, Reservation } from '../../lib/api';

const availability: Availability = {
  hotelID: 1, roomTypeID: 2, startDate: '2030-01-01', endDate: '2030-01-03', roomCount: 1,
  nights: 2, canBook: true, totalPrice: 20000, minAvailableRooms: 5, days: [],
};

const reservation: Reservation = {
  reservationId: 'r-1', hotelID: 1, roomTypeID: 2, startDate: '2030-01-01', endDate: '2030-01-03',
  roomCount: 1, status: 'pending', totalPrice: 20000, createdAt: '', updatedAt: '',
};

const initial: BookingState = {
  startDate: '2030-01-01', endDate: '2030-01-03', roomCount: 1,
  availability: null, reservation: null, pending: null, error: null, isAuthOpen: false,
};

describe('bookingReducer', () => {
  it('clears availability and error when the stay is edited', () => {
    const state = { ...initial, availability, error: 'x' };
    const next = bookingReducer(state, { type: 'editStay', changes: { roomCount: 3 } });
    expect(next).toMatchObject({ roomCount: 3, availability: null, error: null });
  });

  it('discards the reservation only when a new availability check starts', () => {
    const state = { ...initial, reservation };
    expect(bookingReducer(state, { type: 'request', pending: 'checking' }).reservation).toBeNull();
    const paying = bookingReducer(state, { type: 'request', pending: 'paying' });
    expect(paying).toMatchObject({ pending: 'paying', reservation });
  });

  it('stores availability with an optional error and ends the pending state', () => {
    const state = { ...initial, pending: 'checking' as const };
    const next = bookingReducer(state, { type: 'availabilityLoaded', availability, error: 'Only 1 room' });
    expect(next).toMatchObject({ pending: null, availability, error: 'Only 1 room' });
  });

  it('drops availability when the check fails', () => {
    const state = { ...initial, availability, pending: 'checking' as const };
    expect(bookingReducer(state, { type: 'availabilityFailed', error: 'boom' }))
      .toMatchObject({ pending: null, availability: null, error: 'boom' });
  });

  it('stores the reservation and ends the pending state', () => {
    const state = { ...initial, pending: 'booking' as const };
    expect(bookingReducer(state, { type: 'reservationUpdated', reservation }))
      .toMatchObject({ pending: null, reservation });
  });

  it('opens the auth modal only when a failure requires auth', () => {
    const state = { ...initial, pending: 'booking' as const };
    expect(bookingReducer(state, { type: 'failed', error: 'no' })).toMatchObject({ pending: null, error: 'no', isAuthOpen: false });
    expect(bookingReducer(state, { type: 'failed', error: 'no', requireAuth: true }).isAuthOpen).toBe(true);
  });

  it('keeps the auth modal open on a later non-auth failure', () => {
    const state = { ...initial, isAuthOpen: true };
    expect(bookingReducer(state, { type: 'failed', error: 'x' }).isAuthOpen).toBe(true);
  });

  it('reset clears reservation, availability and error but keeps the stay', () => {
    const state = { ...initial, reservation, availability, error: 'x' };
    expect(bookingReducer(state, { type: 'reset' })).toEqual(initial);
  });

  it('toggles the auth modal', () => {
    expect(bookingReducer(initial, { type: 'setAuthOpen', open: true }).isAuthOpen).toBe(true);
  });
});
