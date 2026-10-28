import AdminForm from '../../../../../components/admin/AdminForm';
import { adminData, orNotFound, parseId } from '../../../../../lib/admin-data';
import { hotelToValues } from '../../../../../lib/admin-forms';

export default async function EditHotelPage({ params }: { params: Promise<{ hotelId: string }> }) {
  const hotelId = parseId((await params).hotelId);
  const hotel = await orNotFound(adminData.hotel(hotelId));

  return (
    <main className="page-shell page-shell--compact auth-page">
      <section className="auth-card"><p className="eyebrow">Staff</p><h1>Edit {hotel.name}</h1>
        <AdminForm resource="hotel" method="PUT" endpoint={`hotels/${hotelId}`} initialValues={hotelToValues(hotel)} successHref="/admin/hotels" submitLabel="Save changes" />
      </section>
    </main>
  );
}
