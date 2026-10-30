// Defense in depth on top of SameSite=Lax: reject state-changing proxy calls whose Origin
// header names a different host. Ports are ignored because nginx forwards Host without one.
export function isCrossOrigin(request: Request): boolean {
  const origin = request.headers.get('origin');
  if (!origin) return false;

  const host = (request.headers.get('x-forwarded-host') ?? request.headers.get('host') ?? new URL(request.url).host)
    .split(',')[0]
    .trim();

  try {
    return new URL(origin).hostname !== new URL(`http://${host}`).hostname;
  } catch {
    return true;
  }
}
