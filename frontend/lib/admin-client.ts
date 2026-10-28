export type AdminResult<T> =
  | { ok: true; data: T }
  | { ok: false; status: number; message: string; fieldErrors: Record<string, string> };

function parseFailure(status: number, payload: unknown): { message: string; fieldErrors: Record<string, string> } {
  const fieldErrors: Record<string, string> = {};
  let message = 'Something went wrong. Please try again.';

  if (typeof payload === 'object' && payload !== null) {
    if ('error' in payload && typeof payload.error === 'string') message = payload.error;
    else if ('detail' in payload && typeof payload.detail === 'string') message = payload.detail;
    if ('violations' in payload && Array.isArray(payload.violations)) {
      for (const violation of payload.violations as unknown[]) {
        if (typeof violation !== 'object' || violation === null) continue;
        const path = 'propertyPath' in violation && typeof violation.propertyPath === 'string' ? violation.propertyPath : '';
        const title = 'title' in violation && typeof violation.title === 'string' ? violation.title : '';
        const field = path.replace(/\[\d+\]$/, '');
        if (field && title && !fieldErrors[field]) fieldErrors[field] = title;
      }
      if (Object.keys(fieldErrors).length > 0) message = 'Please fix the highlighted fields.';
    }
  }

  if (status === 401) message = 'Your session has expired. Sign in again to continue.';
  if (status === 403) message = 'You do not have permission to do that.';
  return { message, fieldErrors };
}

export async function adminRequest<T = unknown>(method: 'POST' | 'PUT' | 'DELETE', path: string, body?: unknown): Promise<AdminResult<T>> {
  let response: Response;
  try {
    response = await fetch(`/api/admin/${path}`, {
      method,
      headers: body === undefined ? undefined : { 'Content-Type': 'application/json' },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
  } catch {
    return { ok: false, status: 0, message: 'Network problem: check your connection and try again.', fieldErrors: {} };
  }

  const text = await response.text();
  let payload: unknown = null;
  try {
    payload = text === '' ? null : JSON.parse(text);
  } catch {
    payload = null;
  }

  if (!response.ok) return { ok: false, status: response.status, ...parseFailure(response.status, payload) };
  return { ok: true, data: payload as T };
}
