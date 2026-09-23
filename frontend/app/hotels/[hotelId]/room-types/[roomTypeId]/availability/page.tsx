import Link from 'next/link';
import { notFound } from 'next/navigation';
import { ApiError } from '../../../../../../lib/api';
import { getAccessToken, getHotel, getRoomType } from '../../../../../../lib/server-api';
import BookingForm from '../BookingForm';

export const dynamic = 'force-dynamic';

type AvailabilityPageProps = {
  params: Promise<{ hotelId: string; roomTypeId: string }>;
  searchParams: Promise<{ startDate?: string; endDate?: string; roomCount?: string }>;
};

const datePattern = /^\d{4}-\d{2}-\d{2}$/;

export default async function AvailabilityPage({ params, searchParams }: AvailabilityPageProps) {
  const routeParams = await params;
  const query = await searchParams;
  const hotelId = Number(routeParams.hotelId);
  const roomTypeId = Number(routeParams.roomTypeId);
  if (!Number.isInteger(hotelId) || !Number.isInteger(roomTypeId) || hotelId < 1 || roomTypeId < 1) notFound();

  const initialStartDate = query.startDate && datePattern.test(query.startDate) ? query.startDate : undefined;
  const initialEndDate = query.endDate && datePattern.test(query.endDate) ? query.endDate : undefined;
  const parsedRoomCount = Number(query.roomCount);
  const initialRoomCount = Number.isInteger(parsedRoomCount) && parsedRoomCount > 0 ? parsedRoomCount : undefined;

  let hotel: Awaited<ReturnType<typeof getHotel>>;
  let roomType: Awaited<ReturnType<typeof getRoomType>>;
  let token: string | null;
  try {
    [hotel, roomType, token] = await Promise.all([getHotel(hotelId), getRoomType(hotelId, roomTypeId), getAccessToken()]);
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) notFound();
    return <main className="page-shell page-shell--compact"><section className="error-state"><p className="eyebrow">Connection issue</p><h1>Availability unavailable</h1><p>We could not load this room type right now.</p><Link href={`/hotels/${hotelId}/room-types/${roomTypeId}`}>Back to room</Link></section></main>;
  }

  return (
    <main className="page-shell page-shell--compact">
      <nav className="topbar" aria-label="Primary navigation"><Link className="brand" href="/">Hotel Reserve</Link><Link href={`/hotels/${hotel.id}/room-types/${roomType.id}`}>{roomType.name}</Link></nav>
      <header className="page-heading"><p className="eyebrow">Live availability</p><h1>Check your dates before you book.</h1><p className="intro">Availability is checked against the latest inventory when you submit the form.</p></header>
      <BookingForm hotel={hotel} roomType={roomType} token={token} initialStartDate={initialStartDate} initialEndDate={initialEndDate} initialRoomCount={initialRoomCount} />
    </main>
  );
}
