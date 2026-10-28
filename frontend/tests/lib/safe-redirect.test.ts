import { describe, expect, it } from 'vitest';
import { safeNextPath } from '../../lib/safe-redirect';

describe('safeNextPath', () => {
  it('accepts same-origin absolute paths', () => {
    expect(safeNextPath('/admin')).toBe('/admin');
    expect(safeNextPath('/admin/hotels?x=1')).toBe('/admin/hotels?x=1');
  });

  it.each(['https://evil.example', '//evil.example', '/\\evil.example', 'admin', '/a\r\nb', '', undefined, ['/a']])('rejects %j', (value) => {
    expect(safeNextPath(value as string | undefined)).toBe('/reservations');
  });

  it('uses a custom fallback', () => {
    expect(safeNextPath(null, '/')).toBe('/');
  });
});
