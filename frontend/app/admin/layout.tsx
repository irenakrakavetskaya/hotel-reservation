import { notFound, redirect } from 'next/navigation';
import Link from 'next/link';
import { getSession, isAdmin, isStaff } from '../../lib/session';

export const dynamic = 'force-dynamic';

// Server-side gate: anonymous users go to sign-in, signed-in non-staff users see a 404 so the area is not advertised.
export default async function AdminLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  const session = await getSession();
  if (!session) redirect('/login?next=/admin');
  if (!isStaff(session)) notFound();

  return (
    <div className="admin-shell">
      <nav className="admin-subnav" aria-label="Admin">
        <Link href="/admin">Dashboard</Link>
        <Link href="/admin/hotels">Hotels</Link>
        {isAdmin(session) && <Link href="/admin/users">Users</Link>}
      </nav>
      {children}
    </div>
  );
}
