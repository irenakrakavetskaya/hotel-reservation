import { describe, expect, it } from 'vitest';
import { isCrossOrigin } from '../../lib/same-origin';

const req = (headers: Record<string, string>) => new Request('http://frontend:3000/api/admin/hotels', { method: 'POST', headers });

describe('isCrossOrigin', () => {
  it('allows requests without an Origin header', () => {
    expect(isCrossOrigin(req({}))).toBe(false);
  });

  it('allows a matching host, ignoring ports (nginx forwards Host without one)', () => {
    expect(isCrossOrigin(req({ origin: 'http://localhost:8090', host: 'localhost' }))).toBe(false);
  });

  it('prefers X-Forwarded-Host over Host', () => {
    expect(isCrossOrigin(req({ origin: 'https://shop.example.com', host: 'frontend:3000', 'x-forwarded-host': 'shop.example.com' }))).toBe(false);
  });

  it('rejects a different host', () => {
    expect(isCrossOrigin(req({ origin: 'https://evil.example', host: 'localhost' }))).toBe(true);
  });

  it('rejects a malformed Origin', () => {
    expect(isCrossOrigin(req({ origin: 'not a url', host: 'localhost' }))).toBe(true);
  });
});
