import Link from 'next/link';
import DeleteButton from '../../../../../components/admin/DeleteButton';
import { adminData, orNotFound, parseId } from '../../../../../lib/admin-data';

export default async function AdminRoomsPage({ params }: { params: Promise<{ hotelId: string }> }) {
  const hotelId = parseId((await params).hotelId);
  const [hotel, rooms, roomTypes] = await orNotFound(Promise.all([adminData.hotel(hotelId), adminData.rooms(hotelId), adminData.roomTypes(hotelId)]));
  const typeName = new Map(roomTypes.map((roomType) => [roomType.id, roomType.name]));

  return (
    <main className="page-shell page-shell--compact">
      <header className="page-heading"><p className="eyebrow">{hotel.name}</p><h1>Rooms</h1>
        {roomTypes.length > 0
          ? <Link className="button-link" href={`/admin/hotels/${hotelId}/rooms/new`}>Add room</Link>
          : <p className="intro">Add a room type before adding rooms.</p>}
      </header>
      {rooms.length === 0 ? <section className="empty-state"><h2>No rooms yet.</h2></section> : (
        <table className="admin-table">
          <thead><tr><th>Number</th><th>Type</th><th>Floor</th><th>Status</th><th>Manage</th></tr></thead>
          <tbody>
            {rooms.map((room) => (
              <tr key={room.id}>
                <td>{room.roomNumber}</td>
                <td>{typeName.get(room.roomTypeId) ?? `#${room.roomTypeId}`}</td>
                <td>{room.floor ?? '-'}</td>
                <td>{room.status}</td>
                <td className="admin-actions">
                  <Link href={`/admin/hotels/${hotelId}/rooms/${room.id}/edit`}>Edit</Link>
                  <DeleteButton endpoint={`hotels/${hotelId}/rooms/${room.id}`} label={`Delete room ${room.roomNumber}`} confirmMessage={`Delete room ${room.roomNumber}?`} />
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
