'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { adminRequest } from '../../lib/admin-client';

type DeleteButtonProps = { endpoint: string; label: string; confirmMessage: string };

export default function DeleteButton({ endpoint, label, confirmMessage }: DeleteButtonProps) {
  const router = useRouter();
  const [isDeleting, setIsDeleting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function remove() {
    if (!window.confirm(confirmMessage)) return;
    setIsDeleting(true);
    setError(null);
    const result = await adminRequest('DELETE', endpoint);
    setIsDeleting(false);
    if (!result.ok) {
      setError(result.status === 409 ? `${result.message} It may still be in use.` : result.message);
      return;
    }
    router.refresh();
  }

  return (
    <span className="admin-delete">
      <button type="button" className="nav-button admin-danger" onClick={remove} disabled={isDeleting} aria-label={label}>{isDeleting ? 'Deleting...' : 'Delete'}</button>
      {error && <span className="field-error" role="alert">{error}</span>}
    </span>
  );
}
