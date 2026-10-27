'use client';

import { useRouter } from 'next/navigation';
import { useEffect, useId, useRef, useState } from 'react';
import PasswordToggle from '../../components/PasswordToggle';
import { parseApiError, validateEmail, validateLoginPassword, type FieldErrors } from '../../lib/auth-validation';

type LoginFormProps = { onSuccess?: () => void; initialEmail?: string; focusPassword?: boolean };

export default function LoginForm({ onSuccess, initialEmail = '', focusPassword = false }: LoginFormProps = {}) {
  const router = useRouter();
  const id = useId();
  const emailRef = useRef<HTMLInputElement>(null);
  const passwordRef = useRef<HTMLInputElement>(null);
  const [email, setEmail] = useState(initialEmail);
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [touched, setTouched] = useState<Partial<Record<'email' | 'password', boolean>>>({});
  const [serverErrors, setServerErrors] = useState<FieldErrors>({});
  const [error, setError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  useEffect(() => {
    if (focusPassword) passwordRef.current?.focus();
  }, [focusPassword]);

  const errors: FieldErrors = {
    email: touched.email ? validateEmail(email) : undefined,
    password: touched.password ? validateLoginPassword(password) : undefined,
  };

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError(null);
    setServerErrors({});
    setTouched({ email: true, password: true });
    if (validateEmail(email)) { emailRef.current?.focus(); return; }
    if (validateLoginPassword(password)) { passwordRef.current?.focus(); return; }
    setIsSubmitting(true);

    try {
      const response = await fetch('/api/auth/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email: email.trim(), password }),
      });
      const payload: unknown = await response.json();
      if (!response.ok) {
        const parsed = parseApiError(payload, 'Sign in failed. Please try again.');
        setError(parsed.message);
        setServerErrors(parsed.fieldErrors);
        return;
      }
      router.refresh();
      if (onSuccess) onSuccess();
      else router.push('/reservations');
    } catch {
      setError('Network problem: check your connection and try again.');
    } finally {
      setIsSubmitting(false);
    }
  }

  const emailError = errors.email ?? serverErrors.email;
  const passwordError = errors.password ?? serverErrors.password;

  return (
    <form className="auth-form" onSubmit={submit} noValidate>
      <label>Email
        <input ref={emailRef} type="email" value={email} onChange={(event) => setEmail(event.target.value)} onBlur={() => setTouched((t) => ({ ...t, email: true }))} autoComplete="email" aria-invalid={emailError ? true : undefined} aria-describedby={emailError ? `${id}-email` : undefined} />
        {emailError && <span className="field-error" id={`${id}-email`} role="alert">{emailError}</span>}
      </label>
      <label>Password
        <span className="password-field">
          <input ref={passwordRef} type={showPassword ? 'text' : 'password'} value={password} onChange={(event) => setPassword(event.target.value)} onBlur={() => setTouched((t) => ({ ...t, password: true }))} autoComplete="current-password" aria-invalid={passwordError ? true : undefined} aria-describedby={passwordError ? `${id}-password` : undefined} />
          <PasswordToggle visible={showPassword} onToggle={() => setShowPassword((v) => !v)} />
        </span>
        {passwordError && <span className="field-error" id={`${id}-password`} role="alert">{passwordError}</span>}
      </label>
      <button type="submit" disabled={isSubmitting}>{isSubmitting ? 'Signing in...' : 'Sign in'}</button>
      {error && <p className="booking-error" role="alert">{error}</p>}
    </form>
  );
}
