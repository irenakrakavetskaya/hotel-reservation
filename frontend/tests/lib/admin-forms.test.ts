import { describe, expect, it } from 'vitest';
import {
  dollarsToCents, hotelBody, hotelToValues, roomBody, roomToValues, roomTypeBody, roomTypeToValues,
} from '../../lib/admin-forms';
import type { Hotel, Room, RoomType } from '../../lib/api';

const stamps = { createdAt: '', updatedAt: '' };

describe('admin form mapping', () => {
  it('converts dollars to integer cents without float drift', () => {
    expect(dollarsToCents('150')).toBe(15000);
    expect(dollarsToCents('19.99')).toBe(1999);
    expect(dollarsToCents('0.29')).toBe(29);
  });

  it('builds a hotel payload with trimmed text and split amenities', () => {
    expect(hotelBody({ name: ' Inn ', address: 'A', city: 'C', country: 'X', starRating: '4', description: ' ', amenities: 'Wi-Fi, , Pool ' })).toEqual({
      name: 'Inn', address: 'A', city: 'C', country: 'X', starRating: 4, description: null, amenities: ['Wi-Fi', 'Pool'],
    });
  });

  it('round-trips a hotel', () => {
    const hotel: Hotel = { id: 1, name: 'Inn', address: 'A', city: 'C', country: 'X', starRating: 3, description: null, amenities: ['Wi-Fi', 'Pool'], ...stamps };
    expect(hotelBody(hotelToValues(hotel))).toMatchObject({ name: 'Inn', starRating: 3, description: null, amenities: ['Wi-Fi', 'Pool'] });
  });

  it('shows room type prices in dollars and submits cents', () => {
    const roomType: RoomType = { id: 2, hotelId: 1, name: 'Deluxe', description: 'View', maxOccupancy: 3, basePrice: 15050, amenities: [], ...stamps };
    const values = roomTypeToValues(roomType);
    expect(values.basePrice).toBe('150.50');
    expect(roomTypeBody(values)).toEqual({ name: 'Deluxe', description: 'View', maxOccupancy: 3, basePrice: 15050, amenities: [] });
  });

  it('maps rooms with an optional floor', () => {
    const room: Room = { id: 3, hotelId: 1, roomTypeId: 2, roomNumber: '101', floor: null, status: 'maintenance', ...stamps };
    expect(roomBody(roomToValues(room))).toEqual({ roomTypeId: 2, roomNumber: '101', floor: null, status: 'maintenance' });
    expect(roomBody({ ...roomToValues(room), floor: '4' }).floor).toBe(4);
  });
});
