import Link from 'next/link';
import { notFound } from 'next/navigation';
import { ApiError, type RoomType } from '../../../lib/api';
import { getHotel, getRoomTypes } from '../../../lib/server-api';

export const dynamic = 'force-dynamic';

type HotelPageProps = {
  params: Promise<{ hotelId: string }>;
};

function formatPrice(priceInCents: number): string {
  return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(priceInCents / 100);
}

function RoomTypeCard({ hotelId, roomType }: { hotelId: number; roomType: RoomType }) {
  return (
    <article className="room-card">
      <div>
        <p className="room-card__kicker">Up to {roomType.maxOccupancy} guests</p>
        <h3>{roomType.name}</h3>
        <p>{roomType.description ?? 'A comfortable room type for an easy stay.'}</p>
      </div>
      <div className="room-card__footer">
        <strong>{formatPrice(roomType.basePrice)} <span>/ night</span></strong>
        <Link href={`/hotels/${hotelId}/room-types/${roomType.id}`}>Explore room <span aria-hidden="true">-&gt;</span></Link>
      </div>
    </article>
  );
}

export default async function HotelDetailPage({ params }: HotelPageProps) {
  const hotelId = Number((await params).hotelId);
  if (!Number.isInteger(hotelId) || hotelId < 1) notFound();

  let hotel: Awaited<ReturnType<typeof getHotel>>;
  let roomTypes: Awaited<ReturnType<typeof getRoomTypes>>;
  try {
    [hotel, roomTypes] = await Promise.all([getHotel(hotelId), getRoomTypes(hotelId)]);
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) notFound();
    return <main className="page-shell page-shell--compact"><section className="error-state"><p className="eyebrow">Connection issue</p><h1>Hotel details unavailable</h1><p>We could not load this hotel right now.</p><Link href="/hotels">Back to hotels</Link></section></main>;
  }

  return (
    <main className="page-shell page-shell--compact">
      <header className="detail-hero">
        <div>
          <p className="eyebrow">{hotel.city}, {hotel.country}</p>
          <h1>{hotel.name}</h1>
          <p className="detail-hero__address">{hotel.address}</p>
        </div>
        <div className="rating" aria-label={`${hotel.starRating} star hotel`}>{'★'.repeat(hotel.starRating)}</div>
      </header>
      <section className="detail-copy">
        <p>{hotel.description ?? 'A welcoming base for your next stay.'}</p>
        {hotel.amenities.length > 0 && <ul className="amenity-list">{hotel.amenities.map((amenity) => <li key={amenity}>{amenity}</li>)}</ul>}
      </section>
      <section className="rooms-section">
        <div className="section-heading"><p className="eyebrow">Choose your room</p><h2>Room types</h2></div>
        {roomTypes.length > 0 ? <div className="room-grid">{roomTypes.map((roomType) => <RoomTypeCard key={roomType.id} hotelId={hotel.id} roomType={roomType} />)}</div> : <div className="empty-state"><h3>No room types listed yet.</h3><p>Check back soon for available rooms at this hotel.</p></div>}
      </section>
    </main>
  );
}
