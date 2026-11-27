import type { paths } from '../lib/openapi';

type Booking = paths['/v1/reservations']['post']['requestBody']['content']['application/json'];
type Reservation = paths['/v1/reservations']['post']['responses'][201]['content']['application/json'];
type Availability = paths['/v1/hotels/{hotelId}/room-types/{roomTypeId}/availability']['get']['responses'][200]['content']['application/json'];

const booking: Booking = {
  reservationID: '00000000-0000-4000-8000-000000000000',
  hotelID: 1,
  roomTypeID: 2,
  startDate: '2027-01-01',
  endDate: '2027-01-02',
  roomCount: 1,
};

function checkTypes(reservation: Reservation, availability: Availability): void {
  const id: string = reservation.reservationId;
  const price: number | null = availability.totalPrice;
  // @ts-expect-error The response uses reservationId, not reservationID.
  const incorrectId = reservation.reservationID;
  void [id, price, booking, incorrectId];
}

void checkTypes;
