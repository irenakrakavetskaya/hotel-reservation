import type { Availability, Reservation } from './api';

export type BookingPending = 'checking' | 'booking' | 'paying' | 'canceling' | null;

export type BookingState = {
  startDate: string;
  endDate: string;
  roomCount: number;
  availability: Availability | null;
  reservation: Reservation | null;
  pending: BookingPending;
  error: string | null;
  isAuthOpen: boolean;
};

export type BookingAction =
  | { type: 'editStay'; changes: Partial<Pick<BookingState, 'startDate' | 'endDate' | 'roomCount'>> }
  | { type: 'request'; pending: Exclude<BookingPending, null> }
  | { type: 'availabilityLoaded'; availability: Availability; error: string | null }
  | { type: 'availabilityFailed'; error: string }
  | { type: 'reservationUpdated'; reservation: Reservation }
  | { type: 'failed'; error: string; requireAuth?: boolean }
  | { type: 'reset' }
  | { type: 'setAuthOpen'; open: boolean };

export function bookingReducer(state: BookingState, action: BookingAction): BookingState {
  switch (action.type) {
    case 'editStay':
      return { ...state, ...action.changes, availability: null, error: null };
    case 'request':
      // A new availability check discards any previous reservation; other requests keep it.
      return { ...state, pending: action.pending, error: null, reservation: action.pending === 'checking' ? null : state.reservation };
    case 'availabilityLoaded':
      return { ...state, pending: null, availability: action.availability, error: action.error };
    case 'availabilityFailed':
      return { ...state, pending: null, availability: null, error: action.error };
    case 'reservationUpdated':
      return { ...state, pending: null, reservation: action.reservation };
    case 'failed':
      return { ...state, pending: null, error: action.error, isAuthOpen: action.requireAuth ? true : state.isAuthOpen };
    case 'reset':
      return { ...state, reservation: null, availability: null, error: null };
    case 'setAuthOpen':
      return { ...state, isAuthOpen: action.open };
  }
}
