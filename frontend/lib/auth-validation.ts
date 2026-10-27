export type FieldErrors = Partial<Record<'email' | 'password' | 'passwordConfirmation', string>>;

const EMAIL_PATTERN = /^[^\s@]+@[^\s@.]+(\.[^\s@.]+)+$/;

export function validateEmail(value: string): string | undefined {
  const email = value.trim();
  if (!email) return 'Enter your email address.';
  if (email.length > 180) return 'Email must be 180 characters or fewer.';
  if (!email.includes('@')) return 'Email must contain "@", e.g. name@example.com.';
  if (!EMAIL_PATTERN.test(email)) return 'Email must look like name@example.com.';
  return undefined;
}

export function validateLoginPassword(value: string): string | undefined {
  return value ? undefined : 'Enter your password.';
}

export function validateNewPassword(value: string): string | undefined {
  if (!value) return 'Choose a password.';
  if (!value.trim()) return 'Password cannot consist of spaces only.';
  if (value.length < 8) return `Password must be at least 8 characters (you entered ${value.length}).`;
  if (value.length > 255) return 'Password must be 255 characters or fewer.';
  return undefined;
}

export function validateConfirmation(password: string, confirmation: string): string | undefined {
  if (!confirmation) return 'Repeat your password.';
  if (confirmation !== password) return 'Passwords do not match.';
  return undefined;
}

export function parseApiError(payload: unknown, fallback: string): { message: string; fieldErrors: FieldErrors } {
  const fieldErrors: FieldErrors = {};
  let message = fallback;
  if (typeof payload === 'object' && payload !== null) {
    if ('error' in payload && typeof payload.error === 'string') message = payload.error;
    if ('fieldErrors' in payload && typeof payload.fieldErrors === 'object' && payload.fieldErrors !== null) {
      const source = payload.fieldErrors as Record<string, unknown>;
      for (const key of ['email', 'password', 'passwordConfirmation'] as const) {
        if (typeof source[key] === 'string') fieldErrors[key] = source[key];
      }
    }
  }
  return { message, fieldErrors };
}
