import AdminForm from '../../../../../../components/admin/AdminForm';
import { adminData, orNotFound, parseId } from '../../../../../../lib/admin-data';

export default async function NewRoomPage({ params }: { params: Promise<{ hotelId: string }> }) {
  const hotelId = parseId((await params).hotelId);
  const roomTypes = await orNotFound(adminData.roomTypes(hotelId));

  return (
    <main className="page-shell page-shell--compact auth-page">
      <section className="auth-card"><p className="eyebrow">Staff</p><h1>Add room</h1>
        <AdminForm resource="room" roomTypes={roomTypes} method="POST" endpoint={`hotels/${hotelId}/rooms`} successHref={`/admin/hotels/${hotelId}/rooms`} submitLabel="Create room" />
      </section>
    </main>
  );
}
