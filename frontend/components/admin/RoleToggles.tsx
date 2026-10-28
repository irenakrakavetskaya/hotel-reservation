'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { adminRequest } from '../../lib/admin-client';

type RoleToggleProps = { userId: number; email: string; roles: string[] };

const ROLES = [
  { role: 'ROLE_STAFF', label: 'Staff' },
  { role: 'ROLE_ADMIN', label: 'Admin' },
] as const;

export default function RoleToggles({ userId, email, roles }: RoleToggleProps) {
  const router = useRouter();
  const [current, setCurrent] = useState(roles);
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function toggle(role: string, enabled: boolean) {
    const next = enabled ? [...current, role] : current.filter((item) => item !== role);
    const assignable = next.filter((item) => item === 'ROLE_STAFF' || item === 'ROLE_ADMIN');
    setIsSaving(true);
    setError(null);
    const result = await adminRequest<{ roles: string[] }>('PUT', `admin/users/${userId}/roles`, { roles: assignable });
    setIsSaving(false);
    if (!result.ok) {
      setError(result.message);
      return;
    }
    setCurrent(result.data.roles);
    router.refresh();
  }

  return (
    <div className="admin-roles">
      {ROLES.map(({ role, label }) => (
        <label key={role} className="admin-check">
          <input type="checkbox" checked={current.includes(role)} disabled={isSaving} onChange={(event) => toggle(role, event.target.checked)} aria-label={`${label} role for ${email}`} />
          {label}
        </label>
      ))}
      {error && <span className="field-error" role="alert">{error}</span>}
    </div>
  );
}
