import { describe, expect, it } from 'vitest';
import { reservationCardReducer, type ReservationCardState } from '../../lib/reservation-card-reducer';
import type { Reservation } from '../../lib/api';

const pending: Reservation = {
  reservationId: 'r-1', hotelID: 1, roomTypeID: 2, startDate: '2030-01-01', endDate: '2030-01-03',
  roomCount: 1, status: 'pending', totalPrice: 20000, createdAt: '', updatedAt: '',
};
const canceled: Reservation = { ...pending, status: 'canceled' };
const initial: ReservationCardState = { reservation: pending, isCanceling: false, error: null };

describe('reservationCardReducer', () => {
  it('starts canceling and clears a previous error', () => {
    const next = reservationCardReducer({ ...initial, error: 'old' }, { type: 'cancelStarted' });
    expect(next).toMatchObject({ isCanceling: true, error: null, reservation: pending });
  });

  it('replaces the reservation on success', () => {
    const next = reservationCardReducer({ ...initial, isCanceling: true }, { type: 'cancelSucceeded', reservation: canceled });
    expect(next).toMatchObject({ isCanceling: false, reservation: canceled });
  });

  it('keeps the reservation and stores the error on failure', () => {
    const next = reservationCardReducer({ ...initial, isCanceling: true }, { type: 'cancelFailed', error: 'nope' });
    expect(next).toMatchObject({ isCanceling: false, error: 'nope', reservation: pending });
  });
});
