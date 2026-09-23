export default function HomePage() {
  return (
    <main className="page-shell">
      <nav className="topbar" aria-label="Primary navigation">
        <span className="brand">Hotel Reserve</span>
        <a href="#search">Find a stay</a>
      </nav>

      <section className="hero" id="search">
        <p className="eyebrow">A better way to arrive</p>
        <h1>Make room for the good part.</h1>
        <p className="intro">
          Browse comfortable stays, compare room types, and book with confidence.
        </p>
        <form className="search-panel">
          <label>
            Destination
            <input name="destination" placeholder="City or hotel" />
          </label>
          <label>
            Check-in
            <input name="checkIn" type="date" />
          </label>
          <label>
            Check-out
            <input name="checkOut" type="date" />
          </label>
          <button type="submit">Search stays</button>
        </form>
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
