import Link from 'next/link';
import DeleteButton from '../../../components/admin/DeleteButton';
import { adminData } from '../../../lib/admin-data';

export default async function AdminHotelsPage() {
  const hotels = await adminData.hotels();

  return (
    <main className="page-shell page-shell--compact">
      <header className="page-heading"><p className="eyebrow">Staff</p><h1>Hotels</h1><Link className="button-link" href="/admin/hotels/new">Add hotel</Link></header>
      {hotels.length === 0 ? <section className="empty-state"><h2>No hotels yet.</h2></section> : (
        <table className="admin-table">
          <thead><tr><th>Name</th><th>City</th><th>Stars</th><th>Manage</th></tr></thead>
          <tbody>
            {hotels.map((hotel) => (
              <tr key={hotel.id}>
                <td>{hotel.name}</td>
                <td>{hotel.city}, {hotel.country}</td>
                <td>{hotel.starRating}</td>
                <td className="admin-actions">
                  <Link href={`/admin/hotels/${hotel.id}/edit`}>Edit</Link>
                  <Link href={`/admin/hotels/${hotel.id}/room-types`}>Room types</Link>
                  <Link href={`/admin/hotels/${hotel.id}/rooms`}>Rooms</Link>
                  <DeleteButton endpoint={`hotels/${hotel.id}`} label={`Delete ${hotel.name}`} confirmMessage={`Delete ${hotel.name}? This cannot be undone.`} />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </main>
  );
}
