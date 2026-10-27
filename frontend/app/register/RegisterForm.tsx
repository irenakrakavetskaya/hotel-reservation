'use client';

import { useRouter } from 'next/navigation';
import { useId, useRef, useState } from 'react';
import PasswordToggle from '../../components/PasswordToggle';
import { parseApiError, validateConfirmation, validateEmail, validateNewPassword, type FieldErrors } from '../../lib/auth-validation';

type RegisterFormProps = { onSuccess?: () => void; onEmailTaken?: (email: string) => void };
type Field = 'email' | 'password' | 'passwordConfirmation';

export default function RegisterForm({ onSuccess, onEmailTaken }: RegisterFormProps = {}) {
  const router = useRouter();
  const id = useId();
  const emailRef = useRef<HTMLInputElement>(null);
  const passwordRef = useRef<HTMLInputElement>(null);
  const confirmRef = useRef<HTMLInputElement>(null);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [touched, setTouched] = useState<Partial<Record<Field, boolean>>>({});
  const [serverErrors, setServerErrors] = useState<FieldErrors>({});
  const [error, setError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [emailTaken, setEmailTaken] = useState(false);

  const clientErrors: FieldErrors = {
    email: validateEmail(email),
    password: validateNewPassword(password),
    passwordConfirmation: validateConfirmation(password, passwordConfirmation),
  };
  const fields: Field[] = ['email', 'password', 'passwordConfirmation'];
  const visible = (field: Field) => (touched[field] ? clientErrors[field] : undefined) ?? serverErrors[field];

  function touch(field: Field) {
    setTouched((t) => ({ ...t, [field]: true }));
  }

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError(null);
    setServerErrors({});
    setEmailTaken(false);
    setTouched({ email: true, password: true, passwordConfirmation: true });
    const firstInvalid = fields.find((field) => clientErrors[field]);
    if (firstInvalid) { ({ email: emailRef, password: passwordRef, passwordConfirmation: confirmRef })[firstInvalid].current?.focus(); return; }
    setIsSubmitting(true);

    try {
      const response = await fetch('/api/auth/register', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email: email.trim(), password, passwordConfirmation }),
      });
      const payload: unknown = await response.json();
      if (!response.ok) {
        const parsed = parseApiError(payload, 'Registration failed. Please try again.');
        setError(parsed.message);
        setServerErrors(parsed.fieldErrors);
        setEmailTaken(response.status === 409);
        if (parsed.fieldErrors.email) emailRef.current?.focus();
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

  const emailError = visible('email');
  const passwordError = visible('password');
  const confirmError = visible('passwordConfirmation');
  const type = showPassword ? 'text' : 'password';

  return (
    <form className="auth-form" onSubmit={submit} noValidate>
      <label>Email
        <input ref={emailRef} type="email" value={email} onChange={(event) => { setEmail(event.target.value); setServerErrors((s) => ({ ...s, email: undefined })); }} onBlur={() => touch('email')} autoComplete="email" aria-invalid={emailError ? true : undefined} aria-describedby={emailError ? `${id}-email` : undefined} />
        {emailError && <span className="field-error" id={`${id}-email`} role="alert">{emailError}</span>}
      </label>
      <label>Password
        <span className="password-field">
          <input ref={passwordRef} type={type} value={password} onChange={(event) => setPassword(event.target.value)} onBlur={() => touch('password')} autoComplete="new-password" aria-invalid={passwordError ? true : undefined} aria-describedby={`${id}-password`} />
          <PasswordToggle visible={showPassword} onToggle={() => setShowPassword((v) => !v)} />
        </span>
        {passwordError
          ? <span className="field-error" id={`${id}-password`} role="alert">{passwordError}</span>
          : <span className="field-hint" id={`${id}-password`}>At least 8 characters.</span>}
      </label>
      <label>Confirm password
        <input ref={confirmRef} type={type} value={passwordConfirmation} onChange={(event) => setPasswordConfirmation(event.target.value)} onBlur={() => touch('passwordConfirmation')} autoComplete="new-password" aria-invalid={confirmError ? true : undefined} aria-describedby={confirmError ? `${id}-confirm` : undefined} />
        {confirmError && <span className="field-error" id={`${id}-confirm`} role="alert">{confirmError}</span>}
      </label>
      <button type="submit" disabled={isSubmitting}>{isSubmitting ? 'Creating account...' : 'Create account'}</button>
      {error && <p className="booking-error" role="alert">{error}</p>}
      {emailTaken && onEmailTaken && <button type="button" className="link-button" onClick={() => onEmailTaken(email.trim())}>Sign in instead</button>}
    </form>
  );
}
