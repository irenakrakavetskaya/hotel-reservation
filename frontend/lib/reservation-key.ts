// The reservation ID is the backend's idempotency key. It is kept in
// sessionStorage, keyed by the stay, so a reload after a lost response
// retries with the same ID instead of creating a second reservation.

type Stay = { hotelId: number; roomTypeId: number; startDate: string; endDate: string; roomCount: number };

function storageKey(stay: Stay): string {
  return `reservation-key:${stay.hotelId}:${stay.roomTypeId}:${stay.startDate}:${stay.endDate}:${stay.roomCount}`;
}

function storage(): Storage | null {
  try {
    return typeof window === 'undefined' ? null : window.sessionStorage;
  } catch {
    return null;
  }
}

export function getOrCreateReservationKey(stay: Stay): string {
  const store = storage();
  const key = storageKey(stay);
  const existing = store?.getItem(key);
  if (existing) return existing;

  const id = crypto.randomUUID();
  try {
    store?.setItem(key, id);
  } catch {
    // Storage full or blocked: fall back to a non-persistent ID.
  }
  return id;
}

export function clearReservationKey(stay: Stay): void {
  try {
    storage()?.removeItem(storageKey(stay));
  } catch {
    // Nothing to clear.
  }
}
