import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AuthNav from '../../components/AuthNav';

const { getAccessToken } = vi.hoisted(() => ({ getAccessToken: vi.fn() }));
vi.mock('../../lib/server-api', () => ({ getAccessToken }));
vi.mock('../../components/LogoutButton', () => ({ default: () => <button>Sign out</button> }));

function jwt(roles: string[]): string {
  const encode = (value: unknown) => Buffer.from(JSON.stringify(value)).toString('base64url');
  return `${encode({ alg: 'RS256' })}.${encode({ roles, exp: Math.floor(Date.now() / 1000) + 3600 })}.sig`;
}

async function renderNav() {
  render(await AuthNav());
}

beforeEach(() => getAccessToken.mockReset());

describe('AuthNav', () => {
  it('shows sign-in and register links to anonymous visitors', async () => {
    getAccessToken.mockResolvedValue(null);
    await renderNav();
    expect(screen.getByRole('link', { name: 'Sign in' })).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Admin' })).not.toBeInTheDocument();
  });

  it('shows the Admin link only for staff and admins', async () => {
    getAccessToken.mockResolvedValue(jwt(['ROLE_STAFF', 'ROLE_USER']));
    await renderNav();
    expect(screen.getByRole('link', { name: 'Admin' })).toHaveAttribute('href', '/admin');
    expect(screen.queryByRole('link', { name: 'Sign in' })).not.toBeInTheDocument();
  });

  it('hides the Admin link from plain users', async () => {
    getAccessToken.mockResolvedValue(jwt(['ROLE_USER']));
    await renderNav();
    expect(screen.getByRole('link', { name: 'Reservations' })).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Admin' })).not.toBeInTheDocument();
  });
});
