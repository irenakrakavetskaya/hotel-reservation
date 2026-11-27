import { getAccessToken } from './server-api';
import { apiBaseUrl } from './api';

export async function forwardApiDocumentation(part: 'ui' | 'schema'): Promise<Response> {
  const headers = { 'Cache-Control': 'private, no-store', 'X-Content-Type-Options': 'nosniff' };
  const token = await getAccessToken();
  if (!token) {
    return new Response('Authentication required.', { status: 401, headers });
  }

  let upstream: Response;
  try {
    const suffix = part === 'schema' ? '/openapi.json' : '';
    upstream = await fetch(`${apiBaseUrl()}/admin/api-doc${suffix}`, {
      headers: { Authorization: `Bearer ${token}` },
      cache: 'no-store',
      redirect: 'error',
    });
  } catch {
    return new Response('Documentation service unavailable.', { status: 503, headers });
  }

  if (!upstream.ok) {
    return new Response('Documentation access denied.', { status: upstream.status, headers });
  }

  return new Response(await upstream.text(), {
    headers: {
      ...headers,
      'Content-Type': part === 'schema' ? 'application/json; charset=utf-8' : 'text/html; charset=utf-8',
    },
  });
}
