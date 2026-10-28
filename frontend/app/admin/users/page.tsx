import { notFound } from 'next/navigation';
import RoleToggles from '../../../components/admin/RoleToggles';
import { adminData } from '../../../lib/admin-data';
import { getSession, isAdmin } from '../../../lib/session';

export default async function AdminUsersPage({ searchParams }: { searchParams: Promise<{ search?: string }> }) {
  if (!isAdmin(await getSession())) notFound();
  const search = (await searchParams).search?.trim();
  const users = await adminData.users(search);

  return (
    <main className="page-shell page-shell--compact">
      <header className="page-heading"><p className="eyebrow">Admin</p><h1>Users</h1>
        <p className="intro">Role changes apply the next time the user signs in.</p>
        <form className="admin-search" action="/admin/users" method="get">
          <label>Search by email<input type="search" name="search" defaultValue={search ?? ''} /></label>
          <button type="submit">Search</button>
        </form>
      </header>
      {users.length === 0 ? <section className="empty-state"><h2>No users found.</h2></section> : (
        <table className="admin-table">
          <thead><tr><th>Email</th><th>Roles</th></tr></thead>
          <tbody>
            {users.map((user) => (
              <tr key={user.id}><td>{user.email}</td><td><RoleToggles userId={user.id} email={user.email} roles={user.roles} /></td></tr>
            ))}
          </tbody>
        </table>
      )}
    </main>
  );
}
