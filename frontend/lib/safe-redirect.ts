// Only same-origin absolute paths are allowed, so a crafted ?next= cannot send users to another site.
export function safeNextPath(value: string | string[] | undefined | null, fallback = '/reservations'): string {
  if (typeof value !== 'string') return fallback;
  if (!value.startsWith('/') || value.startsWith('//') || value.includes('\\') || /[\r\n]/.test(value)) return fallback;
  return value;
}
