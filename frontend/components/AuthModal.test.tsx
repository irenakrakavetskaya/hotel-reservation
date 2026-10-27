import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import AuthModal from './AuthModal';

vi.mock('next/navigation', () => ({ useRouter: () => ({ push: vi.fn(), refresh: vi.fn() }) }));

describe('AuthModal', () => {
  it('opens the dialog with both forms', () => {
    render(<AuthModal open onClose={vi.fn()} />);
    expect(screen.getByRole('dialog')).toHaveAttribute('open');
    expect(screen.getByRole('heading', { name: 'Sign in or create an account' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Sign in' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Create account' })).toBeInTheDocument();
  });

  it('stays closed when open is false', () => {
    render(<AuthModal open={false} onClose={vi.fn()} />);
    expect(screen.getByRole('dialog', { hidden: true })).not.toHaveAttribute('open');
  });

  it('closes with the × button and resets typed values', async () => {
    const onClose = vi.fn();
    render(<AuthModal open onClose={onClose} />);
    const email = screen.getAllByLabelText(/^Email/i)[0];
    await userEvent.type(email, 'typed@example.com');
    await userEvent.click(screen.getByRole('button', { name: 'Close' }));

    expect(onClose).toHaveBeenCalled();
    expect(screen.getAllByLabelText(/^Email/i)[0]).toHaveValue('');
  });

  it('closes on a backdrop click but not on a content click', async () => {
    const onClose = vi.fn();
    render(<AuthModal open onClose={onClose} />);
    await userEvent.click(screen.getByRole('heading', { name: 'Sign in or create an account' }));
    expect(onClose).not.toHaveBeenCalled();
    await userEvent.click(screen.getByRole('dialog'));
    expect(onClose).toHaveBeenCalled();
  });

  it('hands a taken email over to the sign-in form', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ error: 'x', code: 'email_taken', fieldErrors: { email: 'Email taken.' } }), { status: 409 })));
    render(<AuthModal open onClose={vi.fn()} />);
    const [loginEmail, registerEmail] = screen.getAllByLabelText(/^Email/i);
    await userEvent.type(registerEmail, 'a@b.co');
    const [, registerPassword] = screen.getAllByLabelText(/^Password/i);
    await userEvent.type(registerPassword, 'password1');
    await userEvent.type(screen.getByLabelText(/^Confirm password/i), 'password1');
    await userEvent.click(screen.getByRole('button', { name: 'Create account' }));
    await userEvent.click(await screen.findByRole('button', { name: 'Sign in instead' }));

    expect(screen.getAllByLabelText(/^Email/i)[0]).toHaveValue('a@b.co');
    expect(screen.getAllByLabelText(/^Password/i)[0]).toHaveFocus();
    expect(loginEmail).toBeDefined();
    vi.unstubAllGlobals();
  });
});
