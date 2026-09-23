export type Hotel = {
  id: number;
  name: string;
  address: string;
  city: string;
  country: string;
  starRating: number;
  description: string | null;
  amenities: string[];
  createdAt: string;
  updatedAt: string;
};

export type RoomType = {
  id: number;
  hotelId: number;
  name: string;
  description: string | null;
  maxOccupancy: number;
  basePrice: number;
  amenities: string[];
  createdAt: string;
  updatedAt: string;
};

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    message: string,
    public readonly details: unknown = null
  ) {
    super(message);
    this.name = 'ApiError';
  }

  get isUnauthorized(): boolean {
    return this.status === 401;
  }

  get isConflict(): boolean {
    return this.status === 409;
  }
}

type ApiFetchOptions = Omit<RequestInit, 'headers'> & {
  token?: string | null;
  headers?: HeadersInit;
};

function apiBaseUrl(): string {
  return process.env.API_SERVER_URL ?? process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8090/v1';
}

async function responseMessage(response: Response): Promise<{ message: string; details: unknown }> {
  const contentType = response.headers.get('content-type') ?? '';
  if (!contentType.includes('application/json')) {
    return { message: response.statusText || 'The API request failed.', details: null };
  }

  const payload: unknown = await response.json();
  if (typeof payload === 'object' && payload !== null && 'message' in payload && typeof payload.message === 'string') {
    return { message: payload.message, details: payload };
  }

  return { message: response.statusText || 'The API request failed.', details: payload };
}

export async function apiFetch<T>(path: string, options: ApiFetchOptions = {}): Promise<T> {
  const headers = new Headers(options.headers);
  headers.set('Accept', 'application/json');
  if (options.body && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json');
  }
  if (options.token) {
    headers.set('Authorization', `Bearer ${options.token}`);
  }

  const response = await fetch(`${apiBaseUrl()}${path}`, {
    ...options,
    headers,
  });

  if (!response.ok) {
    const { message, details } = await responseMessage(response);
    throw new ApiError(response.status, message, details);
  }

  if (response.status === 204) {
    return undefined as T;
  }

  return response.json() as Promise<T>;
}

export const hotelApi = {
  list(token?: string | null) {
    return apiFetch<Hotel[]>('/hotels', { token, next: { revalidate: 60 } });
  },
  get(hotelId: number, token?: string | null) {
    return apiFetch<Hotel>(`/hotels/${hotelId}`, { token, next: { revalidate: 60 } });
  },
  listRoomTypes(hotelId: number, token?: string | null) {
    return apiFetch<RoomType[]>(`/hotels/${hotelId}/room-types`, { token, next: { revalidate: 60 } });
  },
  getRoomType(hotelId: number, roomTypeId: number, token?: string | null) {
    return apiFetch<RoomType>(`/hotels/${hotelId}/room-types/${roomTypeId}`, { token, next: { revalidate: 60 } });
  },
};
