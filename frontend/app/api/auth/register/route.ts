import { NextResponse } from 'next/server';
import { ApiError, authApi } from '../../../../lib/api';

const TOKEN_MAX_AGE = 60 * 60;

export async function POST(request: Request) {
  try {
    const body: unknown = await request.json();
    if (!isCredentials(body) || typeof body.passwordConfirmation !== 'string') {
      return NextResponse.json({ error: 'Email, password, and password confirmation are required.' }, { status: 422 });
    }

    const result = await authApi.register(body.email, body.password, body.passwordConfirmation);
    const response = NextResponse.json({ user: result.user }, { status: 201 });
    setAuthCookie(response, result.token, request.url);
    return response;
  } catch (error) {
    return authErrorResponse(error);
  }
}

function isCredentials(value: unknown): value is { email: string; password: string; passwordConfirmation?: unknown } {
  return typeof value === 'object' && value !== null && 'email' in value && typeof value.email === 'string'
    && 'password' in value && typeof value.password === 'string';
}

function setAuthCookie(response: NextResponse, token: string, requestUrl: string): void {
  response.cookies.set('access_token', token, {
    httpOnly: true,
    secure: process.env.AUTH_COOKIE_SECURE === 'true' || new URL(requestUrl).protocol === 'https:',
    sameSite: 'lax',
    path: '/',
    maxAge: TOKEN_MAX_AGE,
  });
}

function authErrorResponse(error: unknown): NextResponse {
  if (error instanceof ApiError) {
    if (error.status === 409) {
      const message = 'An account with this email already exists. Try signing in instead.';
      return NextResponse.json({ error: message, code: 'email_taken', fieldErrors: { email: message } }, { status: 409 });
    }
    if (error.status === 422) {
      return NextResponse.json({ error: 'Some details are not valid. Check the highlighted fields and try again.', code: 'validation_failed' }, { status: 422 });
    }
    return NextResponse.json({ error: 'Registration is temporarily unavailable. Please try again in a moment.', code: 'unavailable' }, { status: error.status >= 500 ? 503 : error.status });
  }
  return NextResponse.json({ error: 'Registration is temporarily unavailable. Please try again in a moment.', code: 'unavailable' }, { status: 503 });
}