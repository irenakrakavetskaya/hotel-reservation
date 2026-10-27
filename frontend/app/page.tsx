import { getHotels } from '../lib/server-api';
import SearchForm, { type HotelOption } from './SearchForm';

export const dynamic = 'force-dynamic';

async function loadHotelOptions(): Promise<HotelOption[]> {
  try {
    return (await getHotels()).map(({ id, name }) => ({ id, name }));
  } catch {
    return [];
  }
}

export default async function HomePage() {
  const hotels = await loadHotelOptions();

  return (
    <main className="page-shell">
      <section className="hero" id="search">
        <p className="eyebrow">A better way to arrive</p>
        <h1>Make room for the good part.</h1>
        <p className="intro">
          Browse comfortable stays, compare room types, and book with confidence.
        </p>
        <SearchForm hotels={hotels} />
      </section>

      <section className="signal-row" aria-label="Service highlights">
        <div>
          <strong>Flexible stays</strong>
          <span>Dates that work for your plans.</span>
        </div>
        <div>
          <strong>Clear pricing</strong>
          <span>See room rates before you commit.</span>
        </div>
        <div>
          <strong>Reliable booking</strong>
          <span>Your reservation is protected on retry.</span>
        </div>
      </section>
    </main>
  );
}
