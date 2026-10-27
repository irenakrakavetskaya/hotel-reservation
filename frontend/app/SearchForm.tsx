'use client';

import { useId, useMemo, useState, useSyncExternalStore } from 'react';
import { useRouter } from 'next/navigation';

export type HotelOption = { id: number; name: string };

type SearchFormProps = { hotels: HotelOption[] };

type FieldErrors = { destination?: string; checkIn?: string; checkOut?: string };

const MAX_SUGGESTIONS = 6;

const subscribeNever = () => () => {};
const serverToday = () => '';
const clientToday = () => localDateString(new Date());

function localDateString(date: Date): string {
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${date.getFullYear()}-${month}-${day}`;
}

function findHotel(hotels: HotelOption[], value: string): HotelOption | undefined {
  const needle = value.trim().toLowerCase();
  return hotels.find((hotel) => hotel.name.toLowerCase() === needle);
}

export default function SearchForm({ hotels }: SearchFormProps) {
  const router = useRouter();
  const listboxId = useId();
  const [destination, setDestination] = useState('');
  const [checkInInput, setCheckIn] = useState<string | null>(null);
  const [checkOut, setCheckOut] = useState('');
  // Server snapshot is '' so markup matches during hydration; the client then reads the user's local date.
  const today = useSyncExternalStore(subscribeNever, clientToday, serverToday);
  const checkIn = checkInInput ?? today;
  const [errors, setErrors] = useState<FieldErrors>({});
  const [isOpen, setIsOpen] = useState(false);
  const [activeIndex, setActiveIndex] = useState(-1);

  const suggestions = useMemo(() => {
    const needle = destination.trim().toLowerCase();
    if (!needle) return [];
    const matches = hotels.filter((hotel) => hotel.name.toLowerCase().includes(needle));
    matches.sort((a, b) => Number(!a.name.toLowerCase().startsWith(needle)) - Number(!b.name.toLowerCase().startsWith(needle)));
    return matches.slice(0, MAX_SUGGESTIONS);
  }, [destination, hotels]);

  const hasErrors = Boolean(errors.destination || errors.checkIn || errors.checkOut);
  const showList = isOpen && suggestions.length > 0;

  function selectSuggestion(hotel: HotelOption) {
    setDestination(hotel.name);
    setIsOpen(false);
    setActiveIndex(-1);
    setErrors((current) => ({ ...current, destination: undefined }));
  }

  function onDestinationKeyDown(event: React.KeyboardEvent<HTMLInputElement>) {
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      setIsOpen(true);
      setActiveIndex((index) => (suggestions.length === 0 ? -1 : (index + 1) % suggestions.length));
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      setActiveIndex((index) => (suggestions.length === 0 ? -1 : (index <= 0 ? suggestions.length - 1 : index - 1)));
    } else if (event.key === 'Enter' && showList && activeIndex >= 0) {
      event.preventDefault();
      selectSuggestion(suggestions[activeIndex]);
    } else if (event.key === 'Escape') {
      setIsOpen(false);
      setActiveIndex(-1);
    }
  }

  function validate(): { errors: FieldErrors; hotel?: HotelOption } {
    const next: FieldErrors = {};
    const trimmed = destination.trim();
    const hotel = trimmed ? findHotel(hotels, trimmed) : undefined;

    if (!trimmed) next.destination = 'Enter a destination.';
    else if (!hotel) next.destination = 'Choose one of our hotels from the suggestions.';

    if (!checkIn) next.checkIn = 'Select a check-in date.';
    else if (today && checkIn < today) next.checkIn = 'Check-in cannot be in the past.';

    if (!checkOut) next.checkOut = 'Select a check-out date.';
    else if (checkIn && checkOut <= checkIn) next.checkOut = 'Check-out must be after check-in.';

    return { errors: next, hotel };
  }

  function onSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const result = validate();
    setErrors(result.errors);
    if (Object.keys(result.errors).length > 0 || !result.hotel) return;

    const query = new URLSearchParams({ checkIn, checkOut });
    router.push(`/hotels/${result.hotel.id}?${query}`);
  }

  return (
    <form className={hasErrors ? 'search-panel search-panel--has-errors' : 'search-panel'} onSubmit={onSubmit} noValidate>
      <label className="search-field">
        Destination
        <input
          name="destination"
          placeholder="City or hotel"
          autoComplete="off"
          role="combobox"
          aria-expanded={showList}
          aria-controls={listboxId}
          aria-autocomplete="list"
          aria-activedescendant={showList && activeIndex >= 0 ? `${listboxId}-${activeIndex}` : undefined}
          aria-invalid={errors.destination ? true : undefined}
          aria-describedby={errors.destination ? `${listboxId}-destination-error` : undefined}
          value={destination}
          onChange={(event) => {
            setDestination(event.target.value);
            setIsOpen(true);
            setActiveIndex(-1);
          }}
          onFocus={() => setIsOpen(true)}
          onBlur={() => setIsOpen(false)}
          onKeyDown={onDestinationKeyDown}
        />
        {showList && (
          <ul className="suggestion-list" id={listboxId} role="listbox">
            {suggestions.map((hotel, index) => (
              <li
                key={hotel.id}
                id={`${listboxId}-${index}`}
                role="option"
                aria-selected={index === activeIndex}
                className={index === activeIndex ? 'suggestion-list__item is-active' : 'suggestion-list__item'}
                // mousedown fires before the input's blur closes the list
                onMouseDown={(event) => {
                  event.preventDefault();
                  selectSuggestion(hotel);
                }}
              >
                {hotel.name}
              </li>
            ))}
          </ul>
        )}
        {errors.destination && <span className="field-error" id={`${listboxId}-destination-error`} role="alert">{errors.destination}</span>}
      </label>
      <label>
        Check-in
        <input
          name="checkIn"
          type="date"
          min={today || undefined}
          value={checkIn}
          aria-invalid={errors.checkIn ? true : undefined}
          onChange={(event) => setCheckIn(event.target.value)}
        />
        {errors.checkIn && <span className="field-error" role="alert">{errors.checkIn}</span>}
      </label>
      <label>
        Check-out
        <input
          name="checkOut"
          type="date"
          min={checkIn || today || undefined}
          value={checkOut}
          aria-invalid={errors.checkOut ? true : undefined}
          onChange={(event) => setCheckOut(event.target.value)}
        />
        {errors.checkOut && <span className="field-error" role="alert">{errors.checkOut}</span>}
      </label>
      <button type="submit">Search stays <span aria-hidden="true">-&gt;</span></button>
    </form>
  );
}
