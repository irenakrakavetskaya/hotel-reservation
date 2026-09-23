import Link from 'next/link';
import { getAccessToken } from '../lib/server-api';
import LogoutButton from './LogoutButton';

export default async function AuthNav() {
  const token = await getAccessToken();

  return token ? (
    <span className="auth-nav"><Link href="/reservations">Reservations</Link><LogoutButton /></span>
  ) : (
    <span className="auth-nav"><Link href="/login">Sign in</Link><Link href="/register">Create account</Link></span>
  );
}
