import { revalidatePath } from 'next/cache';
import { NextResponse } from 'next/server';
import { apiBaseUrl } from '../../../../lib/api';
import { isCrossOrigin } from '../../../../lib/same-origin';
import { getAccessToken } from '../../../../lib/server-api';

// Browser forms call this same-origin proxy so the httpOnly access_token cookie never reaches client JS.
// Only the admin-managed resources are reachable; the backend still authorizes every call.
const ALLOWED_PATH = /^(hotels(\/\d+(\/(room-types|rooms)(\/\d+)?)?)?|admin\/users(\/\d+\/roles)?)$/;
const METHODS = new Set(['POST', 'PUT', 'DELETE']);
const REPORT_COLLECTION_PATH = /^admin\/reports$/;
const REPORT_READ_PATH = /^admin\/reports(?:\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(?:\/download)?)?$/i;

type Context = { params: Promise<{ path: string[] }> };

async function forward(request: Request, { params }: Context): Promise<Response> {
  const path = (await params).path.join('/');
  const isReportPath = REPORT_READ_PATH.test(path);
  const isAllowedReportMethod = (REPORT_COLLECTION_PATH.test(path) && request.method === 'POST')
    || (isReportPath && request.method === 'GET');
  if (!isAllowedReportMethod && (!ALLOWED_PATH.test(path) || !METHODS.has(request.method))) {
    return NextResponse.json({ error: 'Not found.' }, { status: 404 });
  }

  if (isCrossOrigin(request)) {
    return NextResponse.json({ error: 'Cross-origin requests are not allowed.' }, { status: 403 });
  }

  const token = await getAccessToken();
  if (!token) {
    return NextResponse.json({ error: 'Sign in to continue.' }, { status: 401 });
  }

  const hasBody = request.method === 'POST' || request.method === 'PUT';
  let upstream: Response;
  try {
    upstream = await fetch(`${apiBaseUrl()}/${path}`, {
      method: request.method,
      headers: {
        Accept: path.endsWith('/download') ? 'application/pdf' : 'application/json',
        Authorization: `Bearer ${token}`,
        ...(hasBody ? { 'Content-Type': 'application/json' } : {}),
      },
      body: hasBody ? await request.text() : undefined,
      cache: 'no-store',
    });
  } catch {
    return NextResponse.json({ error: 'The API is unreachable. Please try again.' }, { status: 503 });
  }

  if (upstream.ok && path.startsWith('hotels')) {
    revalidatePath('/hotels', 'layout');
  }

  const responseHeaders = new Headers();
  for (const header of ['content-type', 'content-disposition', 'cache-control']) {
    const value = upstream.headers.get(header);
    if (value) responseHeaders.set(header, value);
  }
  const body = await upstream.arrayBuffer();
  return new Response(body.byteLength === 0 ? null : body, {
    status: upstream.status,
    headers: responseHeaders,
  });
}

export const POST = forward;
export const PUT = forward;
export const DELETE = forward;
export const GET = forward;
