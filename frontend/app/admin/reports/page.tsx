import ReportsPanel from '../../../components/admin/ReportsPanel';

export default function AdminReportsPage() {
  return (
    <main className="page-shell page-shell--compact">
      <header className="page-heading">
        <p className="eyebrow">Staff</p>
        <h1>Reservation reports</h1>
        <p className="intro">Generate a PDF summary for reservations created during a selected UTC date range.</p>
      </header>
      <ReportsPanel />
    </main>
  );
}
