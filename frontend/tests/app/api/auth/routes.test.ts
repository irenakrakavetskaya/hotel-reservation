import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '../../../../lib/api';
import { POST as login } from '../../../../app/api/auth/login/route';
import { POST as logout } from '../../../../app/api/auth/logout/route';
import { POST as register } from '../../../../app/api/auth/register/route';

const { authApi } = vi.hoisted(() => ({ authApi: { login: vi.fn(), register: vi.fn() } }));

vi.mock('../../../../lib/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../../../../lib/api')>()),
  authApi,
}));

function post(path: string, body: unknown): Request {
  return new Request(`http://localhost${path}`, { method: 'POST', body: typeof body === 'string' ? body : JSON.stringify(body) });
}

const credentials = { email: 'a@b.co', password: 'password1' };
const registration = { ...credentials, passwordConfirmation: 'password1' };

beforeEach(() => {
  authApi.login.mockReset();
  authApi.register.mockReset();
});

describe('POST /api/auth/login', () => {
  it('sets an httpOnly cookie and never returns the token', async () => {
    authApi.login.mockResolvedValue({ token: 'jwt', user: { email: 'a@b.co' } });
    const response = await login(post('/api/auth/login', credentials));

    expect(response.status).toBe(200);
    expect(await response.json()).toEqual({ user: { email: 'a@b.co' } });
    const cookie = response.headers.get('set-cookie') ?? '';
    expect(cookie).toContain('access_token=jwt');
    expect(cookie.toLowerCase()).toContain('httponly');
  });

  it('rejects malformed bodies with 422', async () => {
    const response = await login(post('/api/auth/login', { email: 'a@b.co' }));
    expect(response.status).toBe(422);
    expect(authApi.login).not.toHaveBeenCalled();
  });

  it.each([
    [401, 401, 'invalid_credentials'],
    [429, 429, 'rate_limited'],
    [500, 503, 'unavailable'],
  ])('maps backend %i to %i %s', async (backendStatus, status, code) => {
    authApi.login.mockRejectedValue(new ApiError(backendStatus, 'x', null));
    const response = await login(post('/api/auth/login', credentials));
    expect(response.status).toBe(status);
    expect((await response.json()).code).toBe(code);
  });

  it('treats unexpected failures as unavailable', async () => {
    authApi.login.mockRejectedValue(new Error('network'));
    const response = await login(post('/api/auth/login', credentials));
    expect(response.status).toBe(503);
  });
});

describe('POST /api/auth/register', () => {
  it('creates the account and sets the cookie', async () => {
    authApi.register.mockResolvedValue({ token: 'jwt', user: { email: 'a@b.co' } });
    const response = await register(post('/api/auth/register', registration));

    expect(response.status).toBe(201);
    expect(response.headers.get('set-cookie')).toContain('access_token=jwt');
  });

  it('requires the password confirmation', async () => {
    const response = await register(post('/api/auth/register', credentials));
    expect(response.status).toBe(422);
  });

  it('reports a duplicate email on the email field', async () => {
    authApi.register.mockRejectedValue(new ApiError(409, 'exists', null));
    const response = await register(post('/api/auth/register', registration));
    const payload = await response.json();

    expect(response.status).toBe(409);
    expect(payload.code).toBe('email_taken');
    expect(payload.fieldErrors.email).toMatch(/already exists/);
  });

  it('maps validation and server failures', async () => {
    authApi.register.mockRejectedValueOnce(new ApiError(422, 'bad', null));
    expect((await (await register(post('/api/auth/register', registration))).json()).code).toBe('validation_failed');

    authApi.register.mockRejectedValueOnce(new ApiError(502, 'down', null));
    const response = await register(post('/api/auth/register', registration));
    expect(response.status).toBe(503);
  });
});

describe('POST /api/auth/logout', () => {
  it('expires the cookie', async () => {
    const response = await logout(post('/api/auth/logout', {}));
    expect(response.headers.get('set-cookie')).toMatch(/access_token=;/);
  });
});
