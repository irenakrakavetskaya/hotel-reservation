import { isValidElement } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AdminLayout from '../../../app/admin/layout';

const { getSession } = vi.hoisted(() => ({ getSession: vi.fn() }));
vi.mock('../../../lib/session', async (importOriginal) => ({ ...(await importOriginal<typeof import('../../../lib/session')>()), getSession }));
vi.mock('next/navigation', () => ({
  redirect: (url: string) => { throw new Error(`REDIRECT:${url}`); },
  notFound: () => { throw new Error('NOT_FOUND'); },
}));

beforeEach(() => getSession.mockReset());

describe('admin layout guard', () => {
  it('redirects anonymous visitors to sign-in and returns to /admin afterwards', async () => {
    getSession.mockResolvedValue(null);
    await expect(AdminLayout({ children: null })).rejects.toThrow('REDIRECT:/login?next=/admin');
  });

  it('hides the area from signed-in users without a staff role', async () => {
    getSession.mockResolvedValue({ roles: ['ROLE_USER'] });
    await expect(AdminLayout({ children: null })).rejects.toThrow('NOT_FOUND');
  });

  it.each([['ROLE_STAFF'], ['ROLE_ADMIN']])('renders for %s', async (role) => {
    getSession.mockResolvedValue({ roles: [role, 'ROLE_USER'] });
    expect(isValidElement(await AdminLayout({ children: null }))).toBe(true);
  });
});
