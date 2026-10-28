import { beforeEach, describe, expect, it, vi } from 'vitest';
import { DELETE, POST } from '../../../../app/api/admin/[...path]/route';

const { getAccessToken, revalidatePath } = vi.hoisted(() => ({ getAccessToken: vi.fn(), revalidatePath: vi.fn() }));
vi.mock('../../../../lib/server-api', () => ({ getAccessToken }));
vi.mock('next/cache', () => ({ revalidatePath }));

const ctx = (...path: string[]) => ({ params: Promise.resolve({ path }) });
const post = (body: unknown) => new Request('http://localhost/api/admin/x', { method: 'POST', body: JSON.stringify(body) });

beforeEach(() => {
  getAccessToken.mockResolvedValue('jwt-token');
});

describe('admin proxy route', () => {
  it('rejects paths outside the admin-managed resources without calling the API', async () => {
    const fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);

    for (const path of [['reservations'], ['auth', 'login'], ['hotels', 'abc'], ['hotels', '1', 'rooms', '2', 'x'], ['admin', 'users', '1']]) {
      expect((await POST(post({}), ctx(...path))).status).toBe(404);
    }
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('returns 401 without a session cookie', async () => {
    getAccessToken.mockResolvedValue(null);
    const fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);

    expect((await POST(post({}), ctx('hotels'))).status).toBe(401);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('forwards the body with the bearer token and revalidates public hotel pages', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ id: 9 }), { status: 201, headers: { 'Content-Type': 'application/json' } }));
    vi.stubGlobal('fetch', fetchMock);

    const response = await POST(post({ name: 'Inn' }), ctx('hotels'));

    expect(response.status).toBe(201);
    expect(await response.json()).toEqual({ id: 9 });
    const [url, init] = fetchMock.mock.calls[0];
    expect(String(url)).toMatch(/\/hotels$/);
    expect(init.method).toBe('POST');
    expect(init.headers.Authorization).toBe('Bearer jwt-token');
    expect(init.body).toBe(JSON.stringify({ name: 'Inn' }));
    expect(revalidatePath).toHaveBeenCalledWith('/hotels', 'layout');
  });

  it('passes backend errors through and does not revalidate', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ violations: [] }), { status: 422, headers: { 'Content-Type': 'application/json' } })));

    const response = await POST(post({}), ctx('hotels'));

    expect(response.status).toBe(422);
    expect(revalidatePath).not.toHaveBeenCalled();
  });

  it('handles empty 204 responses for deletes', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(null, { status: 204 }));
    vi.stubGlobal('fetch', fetchMock);

    const response = await DELETE(new Request('http://localhost/api/admin/x', { method: 'DELETE' }), ctx('hotels', '3'));

    expect(response.status).toBe(204);
    expect(fetchMock.mock.calls[0][1].body).toBeUndefined();
  });

  it('maps an unreachable API to 503', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('down')));

    expect((await POST(post({}), ctx('hotels'))).status).toBe(503);
  });
});
