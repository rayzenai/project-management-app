/**
 * JSON calls from the Forms pages (the builder's saves, a draft as people
 * type, Submit), with the page's CSRF token. A 422 carries `errors`; any
 * other failure throws.
 */

export class FormsHttpError extends Error {
    constructor(
        message: string,
        public status: number,
        public errors: Record<string, string>,
    ) {
        super(message);
    }
}

function csrf(): string {
    return (
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? ''
    );
}

export async function send<T>(
    method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE',
    url: string,
    body?: unknown,
): Promise<{ message: string | null; data: T }> {
    const isForm = body instanceof FormData;
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrf(),
            ...(body === undefined || isForm
                ? {}
                : { 'Content-Type': 'application/json' }),
        },
        body:
            body === undefined
                ? undefined
                : isForm
                  ? body
                  : JSON.stringify(body),
    });

    const json = (await response.json().catch(() => ({}))) as {
        message?: string;
        data?: T;
        errors?: Record<string, string | string[]>;
    };

    if (!response.ok) {
        const errors: Record<string, string> = {};

        for (const [key, value] of Object.entries(json.errors ?? {})) {
            errors[key] = Array.isArray(value) ? (value[0] ?? '') : value;
        }

        throw new FormsHttpError(
            json.message ??
                (response.status === 419
                    ? 'Your session ran out. Reload the page.'
                    : "That didn't go through. Try again."),
            response.status,
            errors,
        );
    }

    return { message: json.message ?? null, data: json.data as T };
}
