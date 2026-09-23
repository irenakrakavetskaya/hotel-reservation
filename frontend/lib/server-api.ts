import { cookies } from 'next/headers';
import { hotelApi } from './api';

async function accessToken(): Promise<string | null> {
  return (await cookies()).get('access_token')?.value ?? null;
}

export async function getAccessToken(): Promise<string | null> {
  return accessToken();
}

export async function getHotels() {
  return hotelApi.list(await accessToken());
}

export async function getHotel(hotelId: number) {
  return hotelApi.get(hotelId, await accessToken());
}

export async function getRoomTypes(hotelId: number) {
  return hotelApi.listRoomTypes(hotelId, await accessToken());
}

export async function getRoomType(hotelId: number, roomTypeId: number) {
  return hotelApi.getRoomType(hotelId, roomTypeId, await accessToken());
}

export async function getAvailability(hotelId: number, roomTypeId: number, startDate: string, endDate: string, roomCount: number) {
  return hotelApi.availability(hotelId, roomTypeId, startDate, endDate, roomCount);
}

export async function getReservations() {
  return hotelApi.listReservations(await accessToken());
}
