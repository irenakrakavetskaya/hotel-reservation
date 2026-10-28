import AdminForm from '../../../../../../components/admin/AdminForm';
import { parseId } from '../../../../../../lib/admin-data';

export default async function NewRoomTypePage({ params }: { params: Promise<{ hotelId: string }> }) {
  const hotelId = parseId((await params).hotelId);

  return (
    <main className="page-shell page-shell--compact auth-page">
      <section className="auth-card"><p className="eyebrow">Staff</p><h1>Add room type</h1>
        <AdminForm resource="roomType" method="POST" endpoint={`hotels/${hotelId}/room-types`} successHref={`/admin/hotels/${hotelId}/room-types`} submitLabel="Create room type" />
      </section>
    </main>
  );
}
