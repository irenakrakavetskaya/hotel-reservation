import { notFound } from 'next/navigation';
import { ApiError, apiFetch, type Hotel, type Room, type RoomType, type UserSummary } from './api';
import { getAccessToken } from './server-api';

export function parseId(value: string): number {
  if (!/^\d+$/.test(value)) notFound();
  return Number(value);
}

export async function orNotFound<T>(load: Promise<T>): Promise<T> {
  try {
    return await load;
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) notFound();
    throw error;
  }
}

// Admin pages must always see current data, so these reads bypass the public 60s revalidation.
async function read<T>(path: string): Promise<T> {
  return apiFetch<T>(path, { token: await getAccessToken(), cache: 'no-store' });
}

export const adminData = {
  hotels: () => read<Hotel[]>('/hotels'),
  hotel: (id: number) => read<Hotel>(`/hotels/${id}`),
  roomTypes: (hotelId: number) => read<RoomType[]>(`/hotels/${hotelId}/room-types`),
  roomType: (hotelId: number, id: number) => read<RoomType>(`/hotels/${hotelId}/room-types/${id}`),
  rooms: (hotelId: number) => read<Room[]>(`/hotels/${hotelId}/rooms`),
  room: (hotelId: number, id: number) => read<Room>(`/hotels/${hotelId}/rooms/${id}`),
  users: (search?: string) => read<UserSummary[]>(`/admin/users${search ? `?${new URLSearchParams({ search })}` : ''}`),
};
