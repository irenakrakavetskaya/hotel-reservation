import { beforeEach, describe, expect, it, vi } from 'vitest';
import { GET } from '../../app/admin/api-doc/route';
import { GET as GET_SCHEMA } from '../../app/admin/api-doc/openapi.json/route';

const { getAccessToken } = vi.hoisted(() => ({ getAccessToken: vi.fn() }));
vi.mock('../../lib/server-api', () => ({ getAccessToken }));

beforeEach(() => {
  getAccessToken.mockResolvedValue('session-secret');
});

describe('API documentation proxy', () => {
  it.each([GET, GET_SCHEMA])('rejects missing sessions without contacting the backend', async (getDocumentation) => {
    getAccessToken.mockResolvedValue(null);
    const fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);
    const response = await getDocumentation();
    expect(response.status).toBe(401);
    expect(fetchMock).not.toHaveBeenCalled();
    expect(response.headers.get('cache-control')).toContain('no-store');
  });

  it('forwards credentials only to the backend and does not return them', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response('<html>docs</html>'));
    vi.stubGlobal('fetch', fetchMock);
    const response = await GET();
    expect(response.status).toBe(200);
    expect(await response.text()).toBe('<html>docs</html>');
    expect(response.headers.get('cache-control')).toContain('no-store');
    expect(fetchMock.mock.calls[0][1].headers.Authorization).toBe('Bearer session-secret');
    expect(fetchMock.mock.calls[0][1].redirect).toBe('error');
  });

  it.each([GET, GET_SCHEMA])('preserves backend authorization denial and handles unavailable services', async (getDocumentation) => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('secret details', { status: 403 })));
    const denied = await getDocumentation();
    expect(denied.status).toBe(403);
    expect(await denied.text()).not.toContain('secret details');
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')));
    expect((await getDocumentation()).status).toBe(503);
  });

  it('authorizes each schema request through the backend', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response('{"openapi":"3.0.0"}'));
    vi.stubGlobal('fetch', fetchMock);
    const response = await GET_SCHEMA();
    expect(response.headers.get('content-type')).toContain('application/json');
    expect(response.headers.get('cache-control')).toContain('no-store');
    expect(String(fetchMock.mock.calls[0][0])).toMatch(/\/admin\/api-doc\/openapi\.json$/);
    expect(fetchMock.mock.calls[0][1].headers.Authorization).toBe('Bearer session-secret');
  });
});
