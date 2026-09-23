import RegisterForm from './RegisterForm';

export default function RegisterPage() {
  return (
    <main className="page-shell page-shell--compact auth-page">
      <section className="auth-card"><p className="eyebrow">Join Hotel Reserve</p><h1>Make your next stay easier.</h1><p className="intro">Create an account to check availability, book rooms, and manage your reservations.</p><RegisterForm /></section>
    </main>
  );
}
