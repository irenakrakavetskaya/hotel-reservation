'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';

export default function LogoutButton() {
  const router = useRouter();
  const [isLoggingOut, setIsLoggingOut] = useState(false);

  async function logout() {
    setIsLoggingOut(true);
    await fetch('/api/auth/logout', { method: 'POST' });
    router.push('/');
    router.refresh();
  }

  return <button className="nav-button" type="button" onClick={logout} disabled={isLoggingOut}>{isLoggingOut ? 'Signing out...' : 'Sign out'}</button>;
}
