import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiError, apiFetch } from '../../lib/api';

function mockFetch(response: Response) {
  const fetchMock = vi.fn().mockImplementation(() => Promise.resolve(response.clone()));
  vi.stubGlobal('fetch', fetchMock);
  return fetchMock;
}

function json(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

afterEach(() => vi.unstubAllGlobals());

describe('apiFetch', () => {
  it('sends JSON headers and the bearer token only when given', async () => {
    const fetchMock = mockFetch(json({ ok: true }));
    await apiFetch('/x', { method: 'POST', body: '{}', token: 'abc' });
    const headers = new Headers(fetchMock.mock.calls[0][1].headers);
    expect(headers.get('Authorization')).toContain('abc');
    expect(headers.get('Content-Type')).toBe('application/json');

    await apiFetch('/x');
    expect(new Headers(fetchMock.mock.calls[1][1].headers).has('Authorization')).toBe(false);
  });

  it('returns parsed JSON and undefined for 204', async () => {
    mockFetch(json({ id: 1 }));
    expect(await apiFetch('/x')).toEqual({ id: 1 });
    mockFetch(new Response(null, { status: 204 }));
    expect(await apiFetch('/x')).toBeUndefined();
  });

  it('throws ApiError with the server message and status helpers', async () => {
    mockFetch(json({ message: 'Invalid credentials.' }, 401));
    const error = await apiFetch('/x').catch((e: unknown) => e);
    expect(error).toBeInstanceOf(ApiError);
    expect(error).toMatchObject({ status: 401, message: 'Invalid credentials.', isUnauthorized: true, isConflict: false });

    mockFetch(json({ error: 'sold out' }, 409));
    const conflict = await apiFetch('/x').catch((e: unknown) => e);
    expect(conflict).toMatchObject({ status: 409, isConflict: true });
  });

  it('handles non-JSON error bodies', async () => {
    mockFetch(new Response('<html>', { status: 500, statusText: 'Server Error', headers: { 'Content-Type': 'text/html' } }));
    await expect(apiFetch('/x')).rejects.toMatchObject({ status: 500, message: 'Server Error' });
  });
});
