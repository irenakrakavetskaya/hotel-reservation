import { NextResponse } from 'next/server';
import { apiBaseUrl } from '../../../../lib/api';
import { getAccessToken } from '../../../../lib/server-api';

// Browser code calls this same-origin proxy so the httpOnly access_token cookie never reaches client JS.
// Only customer reservation mutations are reachable; the backend still authorizes every call.
const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
const ROUTES: Record<string, RegExp> = {
  POST: /^(reservations|payments)$/,
  DELETE: new RegExp(`^reservations/${UUID}$`),
};

type Context = { params: Promise<{ path: string[] }> };

async function forward(request: Request, { params }: Context): Promise<Response> {
  const path = (await params).path.join('/');
  if (!ROUTES[request.method]?.test(path)) {
    return NextResponse.json({ error: 'Not found.' }, { status: 404 });
  }

  const token = await getAccessToken();
  if (!token) {
    return NextResponse.json({ error: 'Sign in to continue.' }, { status: 401 });
  }

  const hasBody = request.method !== 'DELETE';
  let upstream: Response;
  try {
    upstream = await fetch(`${apiBaseUrl()}/${path}`, {
      method: request.method,
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
        ...(hasBody ? { 'Content-Type': 'application/json' } : {}),
      },
      body: hasBody ? await request.text() : undefined,
      cache: 'no-store',
    });
  } catch {
    return NextResponse.json({ error: 'The API is unreachable. Please try again.' }, { status: 503 });
  }

  const text = await upstream.text();
  return new Response(text === '' ? null : text, {
    status: upstream.status,
    headers: { 'Content-Type': upstream.headers.get('content-type') ?? 'application/json' },
  });
}

export const POST = forward;
export const DELETE = forward;
