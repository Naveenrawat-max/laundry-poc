/** Directory prefix from the page, for example "/proti". Empty when the app owns the host root. */

declare global {
  interface Window {
    /** Set in app.blade.php from the path of APP_URL, before the app starts. */
    __APP_BASE__?: string
  }
}

export function appBase(): string {
  if (typeof window === 'undefined') return ''
  const trimmed = (window.__APP_BASE__ ?? '').trim().replace(/\/$/, '')
  if (trimmed === '' || trimmed === '/') return ''
  return trimmed.startsWith('/') ? trimmed : `/${trimmed}`
}
