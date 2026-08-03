/**
 * Thin fetch wrapper for the /api/* JSON endpoints (routes/api.php) from
 * console pages. There's no axios in this project, so this replicates the
 * bit axios would normally do automatically: read the XSRF-TOKEN cookie
 * (already set by Sanctum/Laravel on every Inertia page load, since
 * dashboard navigation already goes through the 'web' CSRF middleware) and
 * send it back as X-XSRF-TOKEN so Sanctum's stateful CSRF check passes.
 */

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export class ApiError extends Error {
    constructor(
        public status: number,
        public body: unknown,
    ) {
        super(
            (body as { error?: { message?: string }; message?: string })
                ?.error?.message ??
                (body as { message?: string })?.message ??
                `Request failed (${status})`,
        );
    }
}

export async function api<T = unknown>(
    path: string,
    options: RequestInit = {},
): Promise<T> {
    const isFormData = options.body instanceof FormData;

    const response = await fetch(`/api${path}`, {
        ...options,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
            ...(isFormData ? {} : { 'Content-Type': 'application/json' }),
            ...options.headers,
        },
    });

    if (!response.ok) {
        const body = await response.json().catch(() => ({}));

        throw new ApiError(response.status, body);
    }

    if (response.status === 204) {
        return null as T;
    }

    return response.json();
}
