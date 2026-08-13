// Beam client runtime — the `client_import` half of the generated client's runtime contract
// (`beam.client.client_import`, default '@/lib/api'). Published by
// `php artisan vendor:publish --tag=beam-client-runtime`; after publishing this file is YOURS —
// the generator imports it but never writes it. Contract reference:
// splicewire/laravel-beam docs/client-runtime-contract.md.
//
// This is the one-tier (satellite) reference implementation on `fetch`. A host with an operator
// tier additionally exports `operatorApi` with the same shape (see the platform host for the
// axios-based, interceptor-carrying precedent — both are legitimate; only the exported contract
// is fixed).

/** The double-unwrap envelope generated hooks destructure: `res.data.data` is the payload. */
export type ApiResponse<T> = { data: { data: T } };

function xsrfToken(): string | null {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : null;
}

async function request<T>(method: string, url: string, body?: unknown): Promise<ApiResponse<T>> {
    const token = xsrfToken();

    const res = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...(token ? { 'X-XSRF-TOKEN': token } : {}),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    if (!res.ok) {
        throw new Error(`${method} ${url} failed (${res.status})`);
    }

    // Re-wrap the Laravel `{ data }` body once more so generated hooks can read `res.data.data`.
    return { data: (await res.json()) as { data: T } };
}

export const api = {
    get: <T = unknown>(url: string) => request<T>('GET', url),
    post: <T = unknown>(url: string, body?: unknown) => request<T>('POST', url, body),
    put: <T = unknown>(url: string, body?: unknown) => request<T>('PUT', url, body),
    patch: <T = unknown>(url: string, body?: unknown) => request<T>('PATCH', url, body),
    delete: <T = unknown>(url: string) => request<T>('DELETE', url),
};
