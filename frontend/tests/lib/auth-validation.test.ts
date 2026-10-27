import { describe, expect, it } from 'vitest';
import { parseApiError, validateConfirmation, validateEmail, validateLoginPassword, validateNewPassword } from '../../lib/auth-validation';

describe('validateEmail', () => {
  it.each([
    ['', 'Enter your email address.'],
    ['   ', 'Enter your email address.'],
    ['plain', 'Email must contain "@", e.g. name@example.com.'],
    ['a@b', 'Email must look like name@example.com.'],
    ['a@@b.com', 'Email must look like name@example.com.'],
    [`${'a'.repeat(176)}@b.co`, 'Email must be 180 characters or fewer.'],
  ])('rejects %j', (value, message) => {
    expect(validateEmail(value)).toBe(message);
  });

  it('accepts a valid address and ignores surrounding whitespace', () => {
    expect(validateEmail('  name@example.com ')).toBeUndefined();
  });
});

describe('password validators', () => {
  it('requires a login password', () => {
    expect(validateLoginPassword('')).toBe('Enter your password.');
    expect(validateLoginPassword('x')).toBeUndefined();
  });

  it('validates a new password', () => {
    expect(validateNewPassword('')).toBe('Choose a password.');
    expect(validateNewPassword('        ')).toBe('Password cannot consist of spaces only.');
    expect(validateNewPassword('short')).toBe('Password must be at least 8 characters (you entered 5).');
    expect(validateNewPassword('a'.repeat(256))).toBe('Password must be 255 characters or fewer.');
    expect(validateNewPassword('12345678')).toBeUndefined();
  });

  it('validates the confirmation', () => {
    expect(validateConfirmation('abcdefgh', '')).toBe('Repeat your password.');
    expect(validateConfirmation('abcdefgh', 'abcdefgx')).toBe('Passwords do not match.');
    expect(validateConfirmation('abcdefgh', 'abcdefgh')).toBeUndefined();
  });
});

describe('parseApiError', () => {
  it('uses the error message and known field errors only', () => {
    const result = parseApiError({ error: 'Taken', fieldErrors: { email: 'Used', other: 'x', password: 5 } }, 'fallback');
    expect(result).toEqual({ message: 'Taken', fieldErrors: { email: 'Used' } });
  });

  it.each([[null], ['text'], [{}], [{ error: 4 }]])('falls back for %j', (payload) => {
    expect(parseApiError(payload, 'fallback')).toEqual({ message: 'fallback', fieldErrors: {} });
  });
});
