import Link from 'next/link';
import { adminData } from '../../lib/admin-data';
import { getSession, isAdmin } from '../../lib/session';

export default async function AdminDashboard() {
  const [session, hotels] = await Promise.all([getSession(), adminData.hotels()]);

  return (
    <main className="page-shell page-shell--compact">
      <header className="page-heading"><p className="eyebrow">Staff</p><h1>Manage the chain.</h1><p className="intro">Keep hotels, room types and rooms up to date.</p></header>
      <section className="admin-cards">
        <article className="empty-state"><h2>{hotels.length} {hotels.length === 1 ? 'hotel' : 'hotels'}</h2><Link href="/admin/hotels">Manage hotels <span aria-hidden="true">-&gt;</span></Link></article>
        {isAdmin(session) && <article className="empty-state"><h2>Users</h2><p>Grant or revoke staff and admin access.</p><Link href="/admin/users">Manage users <span aria-hidden="true">-&gt;</span></Link></article>}
      </section>
    </main>
  );
}
