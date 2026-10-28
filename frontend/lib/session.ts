import { cookies } from 'next/headers';

export type Session = { roles: string[] };

// The signature is NOT verified here (the frontend has no JWT key). This only gates UI;
// every admin API call is re-authorized by the backend with the same token.
export function decodeSession(token: string | null | undefined, now: number = Date.now()): Session | null {
  if (!token) return null;
  const parts = token.split('.');
  if (parts.length !== 3) return null;

  try {
    const payload: unknown = JSON.parse(Buffer.from(parts[1], 'base64url').toString('utf8'));
    if (typeof payload !== 'object' || payload === null) return null;
    if ('exp' in payload && typeof payload.exp === 'number' && payload.exp * 1000 <= now) return null;
    const roles = 'roles' in payload && Array.isArray(payload.roles)
      ? payload.roles.filter((role): role is string => typeof role === 'string')
      : [];
    return { roles };
  } catch {
    return null;
  }
}

export function isAdmin(session: Session | null): boolean {
  return session?.roles.includes('ROLE_ADMIN') ?? false;
}

export function isStaff(session: Session | null): boolean {
  return isAdmin(session) || (session?.roles.includes('ROLE_STAFF') ?? false);
}

export async function getSession(): Promise<Session | null> {
  return decodeSession((await cookies()).get('access_token')?.value);
}
