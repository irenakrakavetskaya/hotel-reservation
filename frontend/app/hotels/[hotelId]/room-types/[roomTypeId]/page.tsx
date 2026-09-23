import Link from 'next/link';
import { notFound } from 'next/navigation';
import { ApiError } from '../../../../../lib/api';
import { getAccessToken, getHotel, getRoomType } from '../../../../../lib/server-api';
import BookingForm from './BookingForm';

export const dynamic = 'force-dynamic';

type RoomTypePageProps = {
  params: Promise<{ hotelId: string; roomTypeId: string }>;
};

function formatPrice(priceInCents: number): string {
  return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(priceInCents / 100);
}

export default async function RoomTypeDetailPage({ params }: RoomTypePageProps) {
  const routeParams = await params;
  const hotelId = Number(routeParams.hotelId);
  const roomTypeId = Number(routeParams.roomTypeId);
  if (!Number.isInteger(hotelId) || !Number.isInteger(roomTypeId) || hotelId < 1 || roomTypeId < 1) notFound();

  let hotel: Awaited<ReturnType<typeof getHotel>>;
  let roomType: Awaited<ReturnType<typeof getRoomType>>;
  try {
    [hotel, roomType] = await Promise.all([getHotel(hotelId), getRoomType(hotelId, roomTypeId)]);
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) notFound();
    return <main className="page-shell page-shell--compact"><section className="error-state"><p className="eyebrow">Connection issue</p><h1>Room details unavailable</h1><p>We could not load this room type right now.</p><Link href={`/hotels/${hotelId}`}>Back to hotel</Link></section></main>;
  }

  return (
    <main className="page-shell page-shell--compact">
      <nav className="topbar" aria-label="Primary navigation">
        <Link className="brand" href="/">Hotel Reserve</Link>
        <Link href={`/hotels/${hotel.id}`}>{hotel.name}</Link>
      </nav>
      <header className="room-detail-hero">
        <p className="eyebrow">{hotel.city}, {hotel.country}</p>
        <h1>{roomType.name}</h1>
        <p className="intro">A room made for a calm arrival and an unhurried morning.</p>
      </header>
      <section className="room-detail-layout">
        <div className="room-detail-copy">
          <p>{roomType.description ?? 'Settle into a thoughtful room with the essentials close at hand.'}</p>
          {roomType.amenities.length > 0 && <ul className="amenity-list">{roomType.amenities.map((amenity) => <li key={amenity}>{amenity}</li>)}</ul>}
        </div>
        <aside className="booking-panel booking-panel--summary">
          <p className="eyebrow">Starting rate</p>
          <strong>{formatPrice(roomType.basePrice)} <span>/ night</span></strong>
          <p>Up to {roomType.maxOccupancy} guests</p>
        </aside>
      </section>
      <BookingForm hotel={hotel} roomType={roomType} token={await getAccessToken()} />
    </main>
  );
}
