import LoginForm from './LoginForm';
import { safeNextPath } from '../../lib/safe-redirect';

export default async function LoginPage({ searchParams }: { searchParams: Promise<{ next?: string | string[] }> }) {
  const redirectTo = safeNextPath((await searchParams).next);
  return (
    <main className="page-shell page-shell--compact auth-page">
      <section className="auth-card"><p className="eyebrow">Welcome back</p><h1>Sign in to your stays.</h1><p className="intro">Use your account to continue to availability, booking, and reservation history.</p><LoginForm redirectTo={redirectTo} /></section>
    </main>
  );
}
