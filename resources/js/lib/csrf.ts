/**
 * Laravel's XSRF-TOKEN cookie, which it sets on every response.
 *
 * `fetch` does not attach it the way axios does, so every hand-written request
 * passes it explicitly - without it the POST is rejected as a forgery, which is
 * exactly what should happen.
 */
function csrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/** The headers every JSON POST in the application sends. */
export function jsonPostHeaders(): Record<string, string> {
    return {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-XSRF-TOKEN': csrfToken(),
        'X-Requested-With': 'XMLHttpRequest',
    };
}
