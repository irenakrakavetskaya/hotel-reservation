type PasswordToggleProps = { visible: boolean; onToggle: () => void };

export default function PasswordToggle({ visible, onToggle }: PasswordToggleProps) {
  return (
    <button type="button" className="password-toggle" onClick={onToggle} aria-pressed={visible} aria-label={visible ? 'Hide password' : 'Show password'} title={visible ? 'Hide password' : 'Show password'}>
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z" />
        <circle cx="12" cy="12" r="3" />
        {visible && <path d="M3 3l18 18" />}
      </svg>
    </button>
  );
}
