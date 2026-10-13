/** Calls the API under /api/v1. One client per login: the office uses the session cookie, a handheld its own token. */

export class ApiError extends Error {
  constructor(
    public status: number,
    public code: string,
    message: string,
  ) {
    super(message)
  }
}

export type Api = {
  get<T>(path: string): Promise<T>
  post<T>(path: string, body?: unknown): Promise<T>
  patch<T>(path: string, body: unknown): Promise<T>
  /** A form with files: the JSON goes in the field "payload", as closing a stop needs it. */
  form<T>(path: string, payload: unknown, files: Record<string, Blob>): Promise<T>
  /** The address of a file behind the API, for an <img>. Cookie logins only. */
  url(path: string): string
}

type Auth = {
  /** A handheld's token. Without one the browser's session cookie is used. */
  token?: () => string | null
  /** false for a handheld: it never sends the cookie, or the server would see the office user and not the handheld's. */
  cookies?: boolean
  /** The forgery token of a cookie login, from POST /auth/login or GET /auth/me. */
  csrf?: () => string | null
  /** Called on 401, so the owner can show its login screen again. */
  onLoggedOut?: () => void
}

const BASE = '/api/v1'

export function createApi(auth: Auth = {}): Api {
  async function call<T>(method: string, path: string, body?: BodyInit, json = false): Promise<T> {
    const token = auth.token?.() ?? null
    const headers: Record<string, string> = { Accept: 'application/json' }
    if (json) headers['Content-Type'] = 'application/json'
    if (token) headers.Authorization = `Bearer ${token}`
    else if (auth.csrf?.()) headers['X-CSRF-Token'] = auth.csrf()!

    let response: Response
    try {
      response = await fetch(BASE + path, { method, headers, body, credentials: auth.cookies === false ? 'omit' : 'same-origin' })
    } catch {
      throw new ApiError(0, 'offline', 'The server could not be reached.')
    }
    const data = await response.json().catch(() => null)
    if (!response.ok) {
      if (response.status === 401) auth.onLoggedOut?.()
      throw new ApiError(response.status, data?.error?.code ?? 'http_error', data?.error?.message ?? `The server answered ${response.status}.`)
    }
    return data as T
  }

  return {
    get: (path) => call('GET', path),
    post: (path, body) => call('POST', path, JSON.stringify(body ?? {}), true),
    patch: (path, body) => call('PATCH', path, JSON.stringify(body), true),
    form: (path, payload, files) => {
      const form = new FormData()
      form.set('payload', JSON.stringify(payload))
      for (const [name, file] of Object.entries(files)) form.set(name, file, name)
      return call('POST', path, form)
    },
    url: (path) => BASE + path,
  }
}

export function message(error: unknown): string {
  return error instanceof Error ? error.message : 'Something went wrong.'
}
