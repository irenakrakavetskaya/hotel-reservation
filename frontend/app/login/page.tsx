import LoginForm from './LoginForm';

export default function LoginPage() {
  return (
    <main className="page-shell page-shell--compact auth-page">
      <section className="auth-card"><p className="eyebrow">Welcome back</p><h1>Sign in to your stays.</h1><p className="intro">Use your account to continue to availability, booking, and reservation history.</p><LoginForm /></section>
    </main>
  );
}
