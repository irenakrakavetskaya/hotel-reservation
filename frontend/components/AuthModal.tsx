'use client';

import { useEffect, useRef, useState } from 'react';
import LoginForm from '../app/login/LoginForm';
import RegisterForm from '../app/register/RegisterForm';

type AuthModalProps = {
  open: boolean;
  onClose: () => void;
};

export default function AuthModal({ open, onClose }: AuthModalProps) {
  const dialogRef = useRef<HTMLDialogElement>(null);
  const [session, setSession] = useState(0);
  const [handoff, setHandoff] = useState<string | null>(null);

  function close() {
    setSession((n) => n + 1);
    setHandoff(null);
    onClose();
  }

  useEffect(() => {
    const dialog = dialogRef.current;
    if (!dialog) return;
    if (open && !dialog.open) dialog.showModal();
    if (!open && dialog.open) dialog.close();
  }, [open]);

  return (
    <dialog
      ref={dialogRef}
      className="auth-modal"
      aria-labelledby="auth-modal-title"
      onClose={close}
      onClick={(event) => {
        // A click on the dialog element itself (not its content) hits the backdrop.
        if (event.target === event.currentTarget) close();
      }}
    >
      <div className="auth-modal__header">
        <h2 id="auth-modal-title">Sign in or create an account</h2>
        <button className="auth-modal__close" type="button" onClick={close} aria-label="Close">×</button>
      </div>
      <div className="auth-modal__panels">
        <section aria-labelledby="auth-modal-signin">
          <h3 id="auth-modal-signin">Sign in</h3>
          <p>Already have an account? Continue your booking.</p>
          <LoginForm key={`login-${session}-${handoff ?? ''}`} onSuccess={close} initialEmail={handoff ?? ''} focusPassword={handoff !== null} />
        </section>
        <section aria-labelledby="auth-modal-register">
          <h3 id="auth-modal-register">Create an account</h3>
          <p>New here? It only takes a moment.</p>
          <RegisterForm key={`register-${session}`} onSuccess={close} onEmailTaken={setHandoff} />
        </section>
      </div>
    </dialog>
  );
}
