import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import SearchForm from './SearchForm';

const router = vi.hoisted(() => ({ push: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => router }));

const hotels = [
  { id: 1, name: 'Hotel Gamma' },
  { id: 3, name: 'Northline House' },
  { id: 4, name: 'Morrow Bay Hotel' },
];

const destination = () => screen.getByRole('combobox');
const checkIn = () => screen.getByLabelText(/^Check-in/) as HTMLInputElement;
const checkOut = () => screen.getByLabelText(/^Check-out/) as HTMLInputElement;
const submit = () => userEvent.click(screen.getByRole('button', { name: /Search stays/ }));

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'], now: new Date(2026, 9, 27, 12) });
});
afterEach(() => vi.useRealTimers());

describe('SearchForm', () => {
  it('defaults check-in to the local today', () => {
    render(<SearchForm hotels={hotels} />);
    expect(checkIn()).toHaveValue('2026-10-27');
    expect(checkIn()).toHaveAttribute('min', '2026-10-27');
  });

  it('suggests hotels while typing, prefix matches first', async () => {
    render(<SearchForm hotels={hotels} />);
    await userEvent.type(destination(), 'ho');
    const options = screen.getAllByRole('option').map((option) => option.textContent);
    expect(options).toEqual(['Hotel Gamma', 'Northline House', 'Morrow Bay Hotel']);
  });

  it('selects a suggestion with the keyboard', async () => {
    render(<SearchForm hotels={hotels} />);
    await userEvent.type(destination(), 'north{ArrowDown}{Enter}');
    expect(destination()).toHaveValue('Northline House');
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
  });

  it('closes the suggestions on Escape', async () => {
    render(<SearchForm hotels={hotels} />);
    await userEvent.type(destination(), 'ho{Escape}');
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
  });

  it('requires destination and check-out', async () => {
    render(<SearchForm hotels={hotels} />);
    await submit();
    expect(screen.getByText('Enter a destination.')).toBeInTheDocument();
    expect(screen.getByText('Select a check-out date.')).toBeInTheDocument();
    expect(router.push).not.toHaveBeenCalled();
  });

  it('rejects a destination that is not a hotel name', async () => {
    render(<SearchForm hotels={hotels} />);
    await userEvent.type(destination(), 'Paris');
    await submit();
    expect(screen.getByText('Choose one of our hotels from the suggestions.')).toBeInTheDocument();
    expect(destination()).toHaveAttribute('aria-invalid', 'true');
  });

  it('rejects past check-in and check-out not after check-in', async () => {
    render(<SearchForm hotels={hotels} />);
    await userEvent.type(destination(), 'Hotel Gamma');
    await userEvent.clear(checkIn());
    await userEvent.type(checkIn(), '2026-10-01');
    await userEvent.type(checkOut(), '2026-10-01');
    await submit();
    expect(screen.getByText('Check-in cannot be in the past.')).toBeInTheDocument();
    expect(screen.getByText('Check-out must be after check-in.')).toBeInTheDocument();
  });

  it('navigates to the hotel with the dates for a valid search (case-insensitive)', async () => {
    render(<SearchForm hotels={hotels} />);
    await userEvent.type(destination(), 'northline house');
    await userEvent.type(checkOut(), '2026-10-30');
    await submit();
    expect(router.push).toHaveBeenCalledWith('/hotels/3?checkIn=2026-10-27&checkOut=2026-10-30');
  });
});
