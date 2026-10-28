import { beforeEach, describe, expect, it, vi } from 'vitest';
import { DELETE, POST } from '../../../../app/api/customer/[...path]/route';

const { getAccessToken } = vi.hoisted(() => ({ getAccessToken: vi.fn() }));
vi.mock('../../../../lib/server-api', () => ({ getAccessToken }));

const ctx = (...path: string[]) => ({ params: Promise.resolve({ path }) });
const post = (body: unknown) => new Request('http://localhost/api/customer/x', { method: 'POST', body: JSON.stringify(body) });
const del = () => new Request('http://localhost/api/customer/x', { method: 'DELETE' });
const id = '0190b0c0-1234-7abc-8def-0123456789ab';

beforeEach(() => {
  getAccessToken.mockResolvedValue('jwt-token');
});

describe('customer proxy route', () => {
  it('rejects paths and methods outside the reservation mutations without calling the API', async () => {
    const fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);

    for (const path of [['hotels'], ['admin', 'users'], ['reservations', id], ['payments', id], ['reservations', 'abc']]) {
      expect((await POST(post({}), ctx(...path))).status).toBe(404);
    }
    for (const path of [['reservations'], ['payments'], ['reservations', 'abc'], ['reservations', id, 'x']]) {
      expect((await DELETE(del(), ctx(...path))).status).toBe(404);
    }
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('returns 401 without a session cookie', async () => {
    getAccessToken.mockResolvedValue(null);
    const fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);

    expect((await POST(post({}), ctx('reservations'))).status).toBe(401);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('forwards the body with the bearer token and passes the response through', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ status: 'pending' }), { status: 201, headers: { 'Content-Type': 'application/json' } }));
    vi.stubGlobal('fetch', fetchMock);

    const response = await POST(post({ reservationID: id }), ctx('reservations'));

    expect(response.status).toBe(201);
    expect(await response.json()).toEqual({ status: 'pending' });
    const [url, init] = fetchMock.mock.calls[0];
    expect(String(url)).toMatch(/\/reservations$/);
    expect(init.method).toBe('POST');
    expect(init.headers.Authorization).toBe('Bearer jwt-token');
    expect(init.body).toBe(JSON.stringify({ reservationID: id }));
  });

  it('forwards cancellations and preserves upstream error statuses', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ error: 'nope' }), { status: 409, headers: { 'Content-Type': 'application/json' } }));
    vi.stubGlobal('fetch', fetchMock);

    const response = await DELETE(del(), ctx('reservations', id));

    expect(response.status).toBe(409);
    const [url, init] = fetchMock.mock.calls[0];
    expect(String(url)).toMatch(new RegExp(`/reservations/${id}$`));
    expect(init.method).toBe('DELETE');
    expect(init.body).toBeUndefined();
  });

  it('returns 503 when the API is unreachable', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('down')));
    expect((await POST(post({}), ctx('payments'))).status).toBe(503);
  });
});
