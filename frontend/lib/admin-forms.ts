import type { Hotel, Room, RoomType } from './api';
import type { FieldDef, FormValues } from '../components/admin/AdminForm';

const splitList = (value: string): string[] => value.split(',').map((item) => item.trim()).filter(Boolean);
const optionalText = (value: string): string | null => (value.trim() === '' ? null : value.trim());

export function dollarsToCents(value: string): number {
  return Math.round(Number.parseFloat(value) * 100);
}

export const hotelFields: FieldDef[] = [
  { name: 'name', label: 'Name', kind: 'text', required: true },
  { name: 'address', label: 'Address', kind: 'text', required: true },
  { name: 'city', label: 'City', kind: 'text', required: true },
  { name: 'country', label: 'Country', kind: 'text', required: true },
  { name: 'starRating', label: 'Star rating', kind: 'number', required: true, hint: 'A whole number from 1 to 5.', step: '1' },
  { name: 'description', label: 'Description', kind: 'textarea' },
  { name: 'amenities', label: 'Amenities', kind: 'text', hint: 'Comma-separated, e.g. Wi-Fi, Breakfast.' },
];

export const emptyHotel: FormValues = { name: '', address: '', city: '', country: '', starRating: '3', description: '', amenities: '' };

export function hotelToValues(hotel: Hotel): FormValues {
  return {
    name: hotel.name,
    address: hotel.address,
    city: hotel.city,
    country: hotel.country,
    starRating: String(hotel.starRating),
    description: hotel.description ?? '',
    amenities: hotel.amenities.join(', '),
  };
}

export function hotelBody(values: FormValues) {
  return {
    name: values.name.trim(),
    address: values.address.trim(),
    city: values.city.trim(),
    country: values.country.trim(),
    starRating: Number.parseInt(values.starRating, 10),
    description: optionalText(values.description),
    amenities: splitList(values.amenities),
  };
}

export const roomTypeFields: FieldDef[] = [
  { name: 'name', label: 'Name', kind: 'text', required: true },
  { name: 'description', label: 'Description', kind: 'textarea' },
  { name: 'maxOccupancy', label: 'Max occupancy', kind: 'number', required: true, step: '1' },
  { name: 'basePrice', label: 'Base price per night (USD)', kind: 'number', required: true, hint: 'Stored in cents; enter dollars, e.g. 150.00.', step: '0.01' },
  { name: 'amenities', label: 'Amenities', kind: 'text', hint: 'Comma-separated.' },
];

export const emptyRoomType: FormValues = { name: '', description: '', maxOccupancy: '2', basePrice: '', amenities: '' };

export function roomTypeToValues(roomType: RoomType): FormValues {
  return {
    name: roomType.name,
    description: roomType.description ?? '',
    maxOccupancy: String(roomType.maxOccupancy),
    basePrice: (roomType.basePrice / 100).toFixed(2),
    amenities: roomType.amenities.join(', '),
  };
}

export function roomTypeBody(values: FormValues) {
  return {
    name: values.name.trim(),
    description: optionalText(values.description),
    maxOccupancy: Number.parseInt(values.maxOccupancy, 10),
    basePrice: dollarsToCents(values.basePrice),
    amenities: splitList(values.amenities),
  };
}

export function roomFields(roomTypes: RoomType[]): FieldDef[] {
  return [
    { name: 'roomTypeId', label: 'Room type', kind: 'select', required: true, options: roomTypes.map((type) => ({ value: String(type.id), label: type.name })) },
    { name: 'roomNumber', label: 'Room number', kind: 'text', required: true },
    { name: 'floor', label: 'Floor', kind: 'number', step: '1' },
    {
      name: 'status',
      label: 'Status',
      kind: 'select',
      options: [
        { value: 'active', label: 'Active' },
        { value: 'maintenance', label: 'Maintenance' },
        { value: 'inactive', label: 'Inactive' },
      ],
    },
  ];
}

export function emptyRoom(roomTypes: RoomType[]): FormValues {
  return { roomTypeId: roomTypes[0] ? String(roomTypes[0].id) : '', roomNumber: '', floor: '', status: 'active' };
}

export function roomToValues(room: Room): FormValues {
  return { roomTypeId: String(room.roomTypeId), roomNumber: room.roomNumber, floor: room.floor === null ? '' : String(room.floor), status: room.status };
}

export function roomBody(values: FormValues) {
  return {
    roomTypeId: Number.parseInt(values.roomTypeId, 10),
    roomNumber: values.roomNumber.trim(),
    floor: values.floor.trim() === '' ? null : Number.parseInt(values.floor, 10),
    status: values.status,
  };
}
