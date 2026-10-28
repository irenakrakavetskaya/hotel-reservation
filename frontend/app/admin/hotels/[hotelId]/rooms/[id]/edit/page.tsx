import AdminForm from '../../../../../../../components/admin/AdminForm';
import { adminData, orNotFound, parseId } from '../../../../../../../lib/admin-data';
import { roomToValues } from '../../../../../../../lib/admin-forms';

export default async function EditRoomPage({ params }: { params: Promise<{ hotelId: string; id: string }> }) {
  const { hotelId: rawHotelId, id: rawId } = await params;
  const hotelId = parseId(rawHotelId);
  const id = parseId(rawId);
  const [room, roomTypes] = await orNotFound(Promise.all([adminData.room(hotelId, id), adminData.roomTypes(hotelId)]));

  return (
    <main className="page-shell page-shell--compact auth-page">
      <section className="auth-card"><p className="eyebrow">Staff</p><h1>Edit room {room.roomNumber}</h1>
        <AdminForm resource="room" roomTypes={roomTypes} method="PUT" endpoint={`hotels/${hotelId}/rooms/${id}`} initialValues={roomToValues(room)} successHref={`/admin/hotels/${hotelId}/rooms`} submitLabel="Save changes" />
      </section>
    </main>
  );
}
