import { NextResponse } from 'next/server';

export async function POST(request: Request) {
  const response = NextResponse.json({ ok: true });
  response.cookies.set('access_token', '', {
    httpOnly: true,
    secure: process.env.AUTH_COOKIE_SECURE === 'true' || new URL(request.url).protocol === 'https:',
    sameSite: 'lax',
    path: '/',
    maxAge: 0,
  });
  return response;
}