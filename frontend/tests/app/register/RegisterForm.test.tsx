import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import RegisterForm from '../../../app/register/RegisterForm';

const router = vi.hoisted(() => ({ push: vi.fn(), refresh: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => router }));

function respond(status: number, body: unknown) {
  const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify(body), { status }));
  vi.stubGlobal('fetch', fetchMock);
  return fetchMock;
}

async function fill(email: string, password: string, confirmation: string) {
  await userEvent.type(screen.getByLabelText(/^Email/i), email);
  await userEvent.type(screen.getByLabelText(/^Password/i), password);
  await userEvent.type(screen.getByLabelText(/^Confirm password/i), confirmation);
}

beforeEach(() => {
  vi.unstubAllGlobals();
});

describe('RegisterForm', () => {
  it('shows the password hint until there is an error', async () => {
    render(<RegisterForm />);
    expect(screen.getByText('At least 8 characters.')).toBeInTheDocument();
    await userEvent.type(screen.getByLabelText(/^Password/i), 'abc');
    await userEvent.tab();
    expect(screen.getByText('Password must be at least 8 characters (you entered 3).')).toBeInTheDocument();
    expect(screen.queryByText('At least 8 characters.')).not.toBeInTheDocument();
  });

  it('reports mismatched passwords without calling the API', async () => {
    const fetchMock = respond(201, {});
    render(<RegisterForm />);
    await fill('a@b.co', 'password1', 'password2');
    await userEvent.click(screen.getByRole('button', { name: 'Create account' }));

    expect(screen.getByText('Passwords do not match.')).toBeInTheDocument();
    expect(screen.getByLabelText(/^Confirm password/i)).toHaveFocus();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('focuses the first invalid field on an empty submit', async () => {
    render(<RegisterForm />);
    await userEvent.click(screen.getByRole('button', { name: 'Create account' }));
    expect(screen.getByLabelText(/^Email/i)).toHaveFocus();
    expect(screen.getByText('Choose a password.')).toBeInTheDocument();
    expect(screen.getByText('Repeat your password.')).toBeInTheDocument();
  });

  it('toggles both password fields together', async () => {
    render(<RegisterForm />);
    await userEvent.click(screen.getByRole('button', { name: 'Show password' }));
    expect(screen.getByLabelText(/^Password/i)).toHaveAttribute('type', 'text');
    expect(screen.getByLabelText(/^Confirm password/i)).toHaveAttribute('type', 'text');
  });

  it('registers and redirects', async () => {
    const fetchMock = respond(201, { user: {} });
    render(<RegisterForm />);
    await fill('a@b.co', 'password1', 'password1');
    await userEvent.click(screen.getByRole('button', { name: 'Create account' }));

    await waitFor(() => expect(router.push).toHaveBeenCalledWith('/reservations'));
    expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({ email: 'a@b.co', password: 'password1', passwordConfirmation: 'password1' });
  });

  it('shows a duplicate email on the email field and offers sign-in hand-off', async () => {
    respond(409, { error: 'An account with this email already exists.', code: 'email_taken', fieldErrors: { email: 'Email taken.' } });
    const onEmailTaken = vi.fn();
    render(<RegisterForm onEmailTaken={onEmailTaken} />);
    await fill('a@b.co', 'password1', 'password1');
    await userEvent.click(screen.getByRole('button', { name: 'Create account' }));

    expect(await screen.findByText('Email taken.')).toBeInTheDocument();
    expect(screen.getByLabelText(/^Email/i)).toHaveAttribute('aria-invalid', 'true');
    await userEvent.click(screen.getByRole('button', { name: 'Sign in instead' }));
    expect(onEmailTaken).toHaveBeenCalledWith('a@b.co');
  });

  it('does not offer the hand-off without the callback', async () => {
    respond(409, { error: 'exists', fieldErrors: { email: 'Email taken.' } });
    render(<RegisterForm />);
    await fill('a@b.co', 'password1', 'password1');
    await userEvent.click(screen.getByRole('button', { name: 'Create account' }));
    await screen.findByText('Email taken.');
    expect(screen.queryByRole('button', { name: 'Sign in instead' })).not.toBeInTheDocument();
  });
});
