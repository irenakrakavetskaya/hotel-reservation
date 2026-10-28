import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AdminForm from '../../../components/admin/AdminForm';
import DeleteButton from '../../../components/admin/DeleteButton';
import RoleToggles from '../../../components/admin/RoleToggles';

const router = vi.hoisted(() => ({ push: vi.fn(), refresh: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => router }));

function respond(status: number, body: unknown) {
  const fetchMock = vi.fn().mockResolvedValue(new Response(body === null ? null : JSON.stringify(body), { status }));
  vi.stubGlobal('fetch', fetchMock);
  return fetchMock;
}

beforeEach(() => {
  vi.unstubAllGlobals();
});

describe('AdminForm (hotel)', () => {
  async function fillRequired() {
    await userEvent.type(screen.getByLabelText(/^Name/), 'Inn');
    await userEvent.type(screen.getByLabelText(/^Address/), '1 Main St');
    await userEvent.type(screen.getByLabelText(/^City/), 'Oslo');
    await userEvent.type(screen.getByLabelText(/^Country/), 'Norway');
  }

  it('blocks submit and flags empty required fields', async () => {
    const fetchMock = respond(201, {});
    render(<AdminForm resource="hotel" method="POST" endpoint="hotels" successHref="/admin/hotels" submitLabel="Create hotel" />);
    await userEvent.click(screen.getByRole('button', { name: 'Create hotel' }));

    expect(screen.getByText('Enter name.')).toBeInTheDocument();
    expect(screen.getByLabelText(/^Name/)).toHaveAttribute('aria-invalid', 'true');
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('posts the typed payload to the proxy and navigates on success', async () => {
    const fetchMock = respond(201, { id: 1 });
    render(<AdminForm resource="hotel" method="POST" endpoint="hotels" successHref="/admin/hotels" submitLabel="Create hotel" />);
    await fillRequired();
    await userEvent.type(screen.getByLabelText(/^Amenities/), 'Wi-Fi, Pool');
    await userEvent.click(screen.getByRole('button', { name: 'Create hotel' }));

    await waitFor(() => expect(router.push).toHaveBeenCalledWith('/admin/hotels'));
    const [url, init] = fetchMock.mock.calls[0];
    expect(url).toBe('/api/admin/hotels');
    expect(init.method).toBe('POST');
    expect(JSON.parse(init.body)).toMatchObject({ name: 'Inn', city: 'Oslo', starRating: 3, description: null, amenities: ['Wi-Fi', 'Pool'] });
    expect(router.refresh).toHaveBeenCalled();
  });

  it('maps backend validation violations onto fields', async () => {
    respond(422, { violations: [{ propertyPath: 'starRating', title: 'This value should be between 1 and 5.' }] });
    render(<AdminForm resource="hotel" method="POST" endpoint="hotels" successHref="/admin/hotels" submitLabel="Create hotel" />);
    await fillRequired();
    await userEvent.click(screen.getByRole('button', { name: 'Create hotel' }));

    expect(await screen.findByText('This value should be between 1 and 5.')).toBeInTheDocument();
    expect(screen.getByLabelText(/^Star rating/)).toHaveAttribute('aria-invalid', 'true');
    expect(router.push).not.toHaveBeenCalled();
  });

  it('sends expired sessions back to sign-in', async () => {
    respond(401, { error: 'Sign in to continue.' });
    render(<AdminForm resource="hotel" method="POST" endpoint="hotels" successHref="/admin/hotels" submitLabel="Create hotel" />);
    await fillRequired();
    await userEvent.click(screen.getByRole('button', { name: 'Create hotel' }));

    await waitFor(() => expect(router.push).toHaveBeenCalledWith('/login?next=%2Fadmin%2Fhotels'));
  });

  it('shows a forbidden message for 403', async () => {
    respond(403, { error: 'Forbidden' });
    render(<AdminForm resource="hotel" method="POST" endpoint="hotels" successHref="/admin/hotels" submitLabel="Create hotel" />);
    await fillRequired();
    await userEvent.click(screen.getByRole('button', { name: 'Create hotel' }));

    expect(await screen.findByText('You do not have permission to do that.')).toBeInTheDocument();
  });
});

describe('AdminForm (room type)', () => {
  it('converts the entered dollars to cents', async () => {
    const fetchMock = respond(201, {});
    render(<AdminForm resource="roomType" method="POST" endpoint="hotels/1/room-types" successHref="/admin/hotels/1/room-types" submitLabel="Create room type" />);
    await userEvent.type(screen.getByLabelText(/^Name/), 'Deluxe');
    await userEvent.type(screen.getByLabelText(/^Base price/), '150.50');
    await userEvent.click(screen.getByRole('button', { name: 'Create room type' }));

    await waitFor(() => expect(fetchMock).toHaveBeenCalled());
    expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toMatchObject({ name: 'Deluxe', basePrice: 15050, maxOccupancy: 2 });
  });
});

describe('DeleteButton', () => {
  it('does nothing when the confirmation is declined', async () => {
    const fetchMock = respond(204, null);
    vi.spyOn(window, 'confirm').mockReturnValue(false);
    render(<DeleteButton endpoint="hotels/1" label="Delete Inn" confirmMessage="Delete?" />);
    await userEvent.click(screen.getByRole('button', { name: 'Delete Inn' }));

    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('deletes and refreshes the list', async () => {
    const fetchMock = respond(204, null);
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    render(<DeleteButton endpoint="hotels/1" label="Delete Inn" confirmMessage="Delete?" />);
    await userEvent.click(screen.getByRole('button', { name: 'Delete Inn' }));

    await waitFor(() => expect(router.refresh).toHaveBeenCalled());
    expect(fetchMock.mock.calls[0][0]).toBe('/api/admin/hotels/1');
    expect(fetchMock.mock.calls[0][1].method).toBe('DELETE');
  });

  it('shows the conflict message', async () => {
    respond(409, { error: 'Hotel has reservations.' });
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    render(<DeleteButton endpoint="hotels/1" label="Delete Inn" confirmMessage="Delete?" />);
    await userEvent.click(screen.getByRole('button', { name: 'Delete Inn' }));

    expect(await screen.findByText(/Hotel has reservations\./)).toBeInTheDocument();
  });
});

describe('RoleToggles', () => {
  it('grants staff by sending only assignable roles', async () => {
    const fetchMock = respond(200, { id: 5, email: 'a@b.co', roles: ['ROLE_STAFF', 'ROLE_USER'] });
    render(<RoleToggles userId={5} email="a@b.co" roles={['ROLE_USER']} />);
    await userEvent.click(screen.getByRole('checkbox', { name: 'Staff role for a@b.co' }));

    await waitFor(() => expect(screen.getByRole('checkbox', { name: 'Staff role for a@b.co' })).toBeChecked());
    expect(fetchMock.mock.calls[0][0]).toBe('/api/admin/admin/users/5/roles');
    expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({ roles: ['ROLE_STAFF'] });
  });

  it('shows the server error and keeps the previous state', async () => {
    respond(409, { error: 'You cannot remove your own admin role.' });
    render(<RoleToggles userId={1} email="me@b.co" roles={['ROLE_ADMIN', 'ROLE_USER']} />);
    await userEvent.click(screen.getByRole('checkbox', { name: 'Admin role for me@b.co' }));

    expect(await screen.findByText('You cannot remove your own admin role.')).toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: 'Admin role for me@b.co' })).toBeChecked();
  });
});
