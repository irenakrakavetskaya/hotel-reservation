// A full document load re-renders the root layout (and its cookie-based AuthNav);
// client-side router.push() can reuse a cached layout and leave the nav signed out.
export function hardNavigate(url: string): void {
  window.location.assign(url);
}
