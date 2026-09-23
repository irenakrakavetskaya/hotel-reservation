import Link from 'next/link';
import { ApiError, type Hotel } from '../../lib/api';
import { getHotels } from '../../lib/server-api';

export const dynamic = 'force-dynamic';

function HotelCard({ hotel }: { hotel: Hotel }) {
  return (
    <article className="hotel-card">
      <div className="hotel-card__topline">
        <span className="hotel-card__city">{hotel.city}, {hotel.country}</span>
        <span aria-label={`${hotel.starRating} star hotel`}>{'★'.repeat(hotel.starRating)}</span>
      </div>
      <h2>{hotel.name}</h2>
      <p>{hotel.description ?? 'A considered stay with room to settle in.'}</p>
      <div className="hotel-card__footer">
        <span>{hotel.address}</span>
        <Link href={`/hotels/${hotel.id}`}>View hotel <span aria-hidden="true">-&gt;</span></Link>
      </div>
    </article>
  );
}

export default async function HotelsPage() {
  let hotels: Hotel[];
  try {
    hotels = await getHotels();
  } catch (error) {
    if (error instanceof ApiError && error.isUnauthorized) {
      return <ApiErrorState title="Sign-in required" message="Your session does not have permission to browse these hotels." />;
    }
    return <ApiErrorState title="Hotels are taking a moment" message="We could not load the hotel catalogue. Please try again shortly." />;
  }

  return (
    <main className="page-shell page-shell--compact">
      <header className="page-heading">
        <p className="eyebrow">Find your next address</p>
        <h1>Hotels with a little more character.</h1>
        <p className="intro">Explore the collection and open a hotel to compare its room types.</p>
      </header>
      {hotels.length > 0 ? (
        <section className="hotel-grid" aria-label="Hotels">
          {hotels.map((hotel) => <HotelCard key={hotel.id} hotel={hotel} />)}
        </section>
      ) : (
        <section className="empty-state">
          <h2>No hotels are listed yet.</h2>
          <p>Once a hotel is added to the catalogue, it will appear here.</p>
        </section>
      )}
    </main>
  );
}

function ApiErrorState({ title, message }: { title: string; message: string }) {
  return (
    <main className="page-shell page-shell--compact">
      <section className="error-state"><p className="eyebrow">Connection issue</p><h1>{title}</h1><p>{message}</p><Link href="/hotels">Try again</Link></section>
    </main>
  );
}
