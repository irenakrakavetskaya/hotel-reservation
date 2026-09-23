import type { Metadata } from 'next';
import Link from 'next/link';
import AuthNav from '../components/AuthNav';
import './globals.css';

export const metadata: Metadata = {
  title: 'Hotel Reserve',
  description: 'Find and reserve your next stay.'
};

export default function RootLayout({
  children
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="en">
      <body>
        <div className="global-nav"><LinkHome /><AuthNav /></div>
        {children}
      </body>
    </html>
  );
}

function LinkHome() {
  return <Link className="global-nav__brand" href="/">Hotel Reserve</Link>;
}
