import type { Reservation } from './api';

export type ReservationCardState = {
  reservation: Reservation;
  isCanceling: boolean;
  error: string | null;
};

export type ReservationCardAction =
  | { type: 'cancelStarted' }
  | { type: 'cancelSucceeded'; reservation: Reservation }
  | { type: 'cancelFailed'; error: string };

export function reservationCardReducer(state: ReservationCardState, action: ReservationCardAction): ReservationCardState {
  switch (action.type) {
    case 'cancelStarted':
      return { ...state, isCanceling: true, error: null };
    case 'cancelSucceeded':
      return { ...state, isCanceling: false, reservation: action.reservation };
    case 'cancelFailed':
      return { ...state, isCanceling: false, error: action.error };
  }
}
