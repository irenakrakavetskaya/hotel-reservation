import { describe, expect, it } from 'vitest';
import { decodeSession, isAdmin, isStaff } from '../../lib/session';

function token(payload: unknown): string {
  const encode = (value: unknown) => Buffer.from(JSON.stringify(value)).toString('base64url');
  return `${encode({ alg: 'RS256' })}.${encode(payload)}.signature`;
}

const NOW = 1_700_000_000_000;
const future = NOW / 1000 + 3600;

describe('decodeSession', () => {
  it('reads roles from a valid token', () => {
    expect(decodeSession(token({ roles: ['ROLE_STAFF', 'ROLE_USER'], exp: future }), NOW)).toEqual({ roles: ['ROLE_STAFF', 'ROLE_USER'] });
  });

  it('returns null for missing, malformed or non-JSON tokens', () => {
    expect(decodeSession(null, NOW)).toBeNull();
    expect(decodeSession('', NOW)).toBeNull();
    expect(decodeSession('not-a-jwt', NOW)).toBeNull();
    expect(decodeSession('a.%%%.c', NOW)).toBeNull();
    expect(decodeSession(`a.${Buffer.from('"text"').toString('base64url')}.c`, NOW)).toBeNull();
  });

  it('returns null for an expired token', () => {
    expect(decodeSession(token({ roles: ['ROLE_ADMIN'], exp: NOW / 1000 - 1 }), NOW)).toBeNull();
  });

  it('ignores non-string roles and tolerates a missing roles claim', () => {
    expect(decodeSession(token({ roles: ['ROLE_STAFF', 7, null] }), NOW)).toEqual({ roles: ['ROLE_STAFF'] });
    expect(decodeSession(token({}), NOW)).toEqual({ roles: [] });
  });
});

describe('role helpers', () => {
  it('treats admins as staff but not the reverse', () => {
    expect(isStaff({ roles: ['ROLE_STAFF'] })).toBe(true);
    expect(isStaff({ roles: ['ROLE_ADMIN'] })).toBe(true);
    expect(isAdmin({ roles: ['ROLE_STAFF'] })).toBe(false);
    expect(isAdmin({ roles: ['ROLE_ADMIN'] })).toBe(true);
  });

  it('denies plain users and anonymous visitors', () => {
    expect(isStaff({ roles: ['ROLE_USER'] })).toBe(false);
    expect(isStaff(null)).toBe(false);
    expect(isAdmin(null)).toBe(false);
  });
});
