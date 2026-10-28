import Link from 'next/link';
import { getAccessToken } from '../lib/server-api';
import { decodeSession, isStaff } from '../lib/session';
import LogoutButton from './LogoutButton';

export default async function AuthNav() {
  const token = await getAccessToken();
  const staff = isStaff(decodeSession(token));

  return token ? (
    <span className="auth-nav">{staff && <Link href="/admin">Admin</Link>}<Link href="/reservations">Reservations</Link><LogoutButton /></span>
  ) : (
    <span className="auth-nav"><Link href="/login">Sign in</Link><Link href="/register">Create account</Link></span>
  );
}
