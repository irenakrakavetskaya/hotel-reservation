import AdminForm from '../../../../../../../components/admin/AdminForm';
import { adminData, orNotFound, parseId } from '../../../../../../../lib/admin-data';
import { roomTypeToValues } from '../../../../../../../lib/admin-forms';

export default async function EditRoomTypePage({ params }: { params: Promise<{ hotelId: string; id: string }> }) {
  const { hotelId: rawHotelId, id: rawId } = await params;
  const hotelId = parseId(rawHotelId);
  const id = parseId(rawId);
  const roomType = await orNotFound(adminData.roomType(hotelId, id));

  return (
    <main className="page-shell page-shell--compact auth-page">
      <section className="auth-card"><p className="eyebrow">Staff</p><h1>Edit {roomType.name}</h1>
        <AdminForm resource="roomType" method="PUT" endpoint={`hotels/${hotelId}/room-types/${id}`} initialValues={roomTypeToValues(roomType)} successHref={`/admin/hotels/${hotelId}/room-types`} submitLabel="Save changes" />
      </section>
    </main>
  );
}
