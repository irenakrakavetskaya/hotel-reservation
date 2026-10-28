import Link from 'next/link';
import DeleteButton from '../../../../../components/admin/DeleteButton';
import { adminData, orNotFound, parseId } from '../../../../../lib/admin-data';

function formatPrice(cents: number): string {
  return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(cents / 100);
}

export default async function AdminRoomTypesPage({ params }: { params: Promise<{ hotelId: string }> }) {
  const hotelId = parseId((await params).hotelId);
  const [hotel, roomTypes] = await orNotFound(Promise.all([adminData.hotel(hotelId), adminData.roomTypes(hotelId)]));

  return (
    <main className="page-shell page-shell--compact">
      <header className="page-heading"><p className="eyebrow">{hotel.name}</p><h1>Room types</h1><Link className="button-link" href={`/admin/hotels/${hotelId}/room-types/new`}>Add room type</Link></header>
      {roomTypes.length === 0 ? <section className="empty-state"><h2>No room types yet.</h2></section> : (
        <table className="admin-table">
          <thead><tr><th>Name</th><th>Guests</th><th>Base price</th><th>Manage</th></tr></thead>
          <tbody>
            {roomTypes.map((roomType) => (
              <tr key={roomType.id}>
                <td>{roomType.name}</td>
                <td>{roomType.maxOccupancy}</td>
                <td>{formatPrice(roomType.basePrice)}</td>
                <td className="admin-actions">
                  <Link href={`/admin/hotels/${hotelId}/room-types/${roomType.id}/edit`}>Edit</Link>
                  <DeleteButton endpoint={`hotels/${hotelId}/room-types/${roomType.id}`} label={`Delete ${roomType.name}`} confirmMessage={`Delete room type ${roomType.name}?`} />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      <p><Link href="/admin/hotels">Back to hotels</Link></p>
    </main>
  );
}
