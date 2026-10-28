import AdminForm from '../../../../components/admin/AdminForm';

export default function NewHotelPage() {
  return (
    <main className="page-shell page-shell--compact auth-page">
      <section className="auth-card"><p className="eyebrow">Staff</p><h1>Add hotel</h1>
        <AdminForm resource="hotel" method="POST" endpoint="hotels" successHref="/admin/hotels" submitLabel="Create hotel" />
      </section>
    </main>
  );
}
