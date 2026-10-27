import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import LoginForm from '../../../app/login/LoginForm';

const router = vi.hoisted(() => ({ push: vi.fn(), refresh: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => router }));

function respond(status: number, body: unknown) {
  const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify(body), { status }));
  vi.stubGlobal('fetch', fetchMock);
  return fetchMock;
}

beforeEach(() => {
  vi.unstubAllGlobals();
});

describe('LoginForm', () => {
  it('shows field errors, focuses the first invalid field and does not call the API', async () => {
    const fetchMock = respond(200, {});
    render(<LoginForm />);
    await userEvent.click(screen.getByRole('button', { name: 'Sign in' }));

    expect(screen.getByText('Enter your email address.')).toBeInTheDocument();
    expect(screen.getByLabelText(/^Email/i)).toHaveFocus();
    expect(screen.getByLabelText(/^Email/i)).toHaveAttribute('aria-invalid', 'true');
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('validates the email on blur and clears the error once fixed', async () => {
    render(<LoginForm />);
    const email = screen.getByLabelText(/^Email/i);
    await userEvent.type(email, 'nope');
    await userEvent.tab();
    expect(screen.getByText(/must contain "@"/)).toBeInTheDocument();

    await userEvent.clear(email);
    await userEvent.type(email, 'a@b.co');
    expect(email).not.toHaveAttribute('aria-invalid');
    expect(screen.queryByText(/must contain "@"|must look like/)).not.toBeInTheDocument();
  });

  it('requires a password', async () => {
    render(<LoginForm />);
    await userEvent.type(screen.getByLabelText(/^Email/i), 'a@b.co');
    await userEvent.click(screen.getByRole('button', { name: 'Sign in' }));
    expect(screen.getByText('Enter your password.')).toBeInTheDocument();
    expect(screen.getByLabelText(/^Password/i)).toHaveFocus();
  });

  it('toggles password visibility', async () => {
    render(<LoginForm />);
    const password = screen.getByLabelText(/^Password/i);
    expect(password).toHaveAttribute('type', 'password');
    await userEvent.click(screen.getByRole('button', { name: 'Show password' }));
    expect(password).toHaveAttribute('type', 'text');
    expect(screen.getByRole('button', { name: 'Hide password' })).toBeInTheDocument();
  });

  it('submits trimmed credentials and redirects to reservations', async () => {
    const fetchMock = respond(200, { user: {} });
    render(<LoginForm />);
    await userEvent.type(screen.getByLabelText(/^Email/i), ' a@b.co ');
    await userEvent.type(screen.getByLabelText(/^Password/i), 'secret');
    await userEvent.click(screen.getByRole('button', { name: 'Sign in' }));

    await waitFor(() => expect(router.push).toHaveBeenCalledWith('/reservations'));
    expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({ email: 'a@b.co', password: 'secret' });
    expect(router.refresh).toHaveBeenCalled();
  });

  it('calls onSuccess instead of navigating when embedded', async () => {
    respond(200, { user: {} });
    const onSuccess = vi.fn();
    render(<LoginForm onSuccess={onSuccess} />);
    await userEvent.type(screen.getByLabelText(/^Email/i), 'a@b.co');
    await userEvent.type(screen.getByLabelText(/^Password/i), 'secret');
    await userEvent.click(screen.getByRole('button', { name: 'Sign in' }));

    await waitFor(() => expect(onSuccess).toHaveBeenCalled());
    expect(router.push).not.toHaveBeenCalled();
  });

  it('shows the server message for wrong credentials', async () => {
    respond(401, { error: 'Email or password is incorrect.', code: 'invalid_credentials' });
    render(<LoginForm />);
    await userEvent.type(screen.getByLabelText(/^Email/i), 'a@b.co');
    await userEvent.type(screen.getByLabelText(/^Password/i), 'wrong');
    await userEvent.click(screen.getByRole('button', { name: 'Sign in' }));

    expect(await screen.findByText('Email or password is incorrect.')).toBeInTheDocument();
    expect(router.push).not.toHaveBeenCalled();
  });

  it('shows a network error message', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('offline')));
    render(<LoginForm />);
    await userEvent.type(screen.getByLabelText(/^Email/i), 'a@b.co');
    await userEvent.type(screen.getByLabelText(/^Password/i), 'secret');
    await userEvent.click(screen.getByRole('button', { name: 'Sign in' }));
    expect(await screen.findByText(/Network problem/)).toBeInTheDocument();
  });

  it('prefills the email and focuses the password for the sign-in hand-off', () => {
    render(<LoginForm initialEmail="a@b.co" focusPassword />);
    expect(screen.getByLabelText(/^Email/i)).toHaveValue('a@b.co');
    expect(screen.getByLabelText(/^Password/i)).toHaveFocus();
  });
});
