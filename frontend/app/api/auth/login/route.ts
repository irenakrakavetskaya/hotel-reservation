import { NextResponse } from 'next/server';
import { ApiError, authApi } from '../../../../lib/api';

const TOKEN_MAX_AGE = 60 * 60;

export async function POST(request: Request) {
  try {
    const body: unknown = await request.json();
    if (!isCredentials(body)) {
      return NextResponse.json({ error: 'Email and password are required.' }, { status: 422 });
    }

    const result = await authApi.login(body.email, body.password);
    const response = NextResponse.json({ user: result.user });
    response.cookies.set('access_token', result.token, {
      httpOnly: true,
      secure: process.env.AUTH_COOKIE_SECURE === 'true' || new URL(request.url).protocol === 'https:',
      sameSite: 'lax',
      path: '/',
      maxAge: TOKEN_MAX_AGE,
    });
    return response;
  } catch (error) {
    if (error instanceof ApiError) {
      return NextResponse.json({ error: error.status === 401 ? 'Email or password is incorrect.' : 'Login failed.' }, { status: error.status });
    }
    return NextResponse.json({ error: 'Login failed.' }, { status: 500 });
  }
}

function isCredentials(value: unknown): value is { email: string; password: string } {
  return typeof value === 'object' && value !== null && 'email' in value && typeof value.email === 'string'
    && 'password' in value && typeof value.password === 'string';
}