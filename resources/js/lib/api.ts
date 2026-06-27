/**
 * Shared API client for iPop360 frontend.
 * A thin fetch wrapper with JSON parsing, base URL, and error handling.
 */

interface ApiRequestOptions {
    method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
    body?: Record<string, unknown> | FormData | string;
    headers?: Record<string, string>;
}

interface ApiErrorResponse {
    message?: string;
    errors?: Record<string, string[]>;
}

/**
 * Get the base URL for API requests (SSR-safe).
 */
export function getBaseUrl(): string {
    if (typeof window !== 'undefined') {
        return `${window.location.protocol}//${window.location.host}`;
    }
    return 'https://ipop360.vp-associates.com';
}

/**
 * Make an API request with proper error handling.
 *
 * @param endpoint - The API endpoint (e.g., '/api/restaurants')
 * @param options - Request options (method, body, headers)
 * @returns Promise with parsed JSON response
 * @throws Error with message from API or generic message
 */
export async function api<T = unknown>(
    endpoint: string,
    options: ApiRequestOptions = {}
): Promise<T> {
    const {
        method = 'GET',
        body,
        headers = {},
    } = options;

    const url = endpoint.startsWith('http') ? endpoint : `${getBaseUrl()}${endpoint}`;

    const requestHeaders: HeadersInit = {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        ...headers,
    };

    const requestInit: RequestInit = {
        method,
        headers: requestHeaders,
    };

    if (body) {
        if (body instanceof FormData) {
            // Let browser set Content-Type for FormData
            delete (requestHeaders as Record<string, string>)['Content-Type'];
            requestInit.body = body as FormData;
        } else if (typeof body === 'string') {
            requestInit.body = body;
        } else {
            requestInit.body = JSON.stringify(body);
        }
    }

    try {
        const response = await fetch(url, requestInit);

        if (!response.ok) {
            const errorData: ApiErrorResponse = await response.json().catch(() => ({}));
            const errorMessage = errorData.message || `Request failed with status ${response.status}`;
            throw new Error(errorMessage);
        }

        return await response.json() as T;
    } catch (error) {
        if (error instanceof Error) {
            throw error;
        }
        throw new Error('An unexpected error occurred');
    }
}

/**
 * Make a GET request.
 */
export function get<T = unknown>(endpoint: string): Promise<T> {
    return api<T>(endpoint, { method: 'GET' });
}

/**
 * Make a POST request.
 */
export function post<T = unknown>(endpoint: string, body: Record<string, unknown> | FormData): Promise<T> {
    return api<T>(endpoint, { method: 'POST', body });
}

/**
 * Make a PUT request.
 */
export function put<T = unknown>(endpoint: string, body: Record<string, unknown>): Promise<T> {
    return api<T>(endpoint, { method: 'PUT', body });
}

/**
 * Make a PATCH request.
 */
export function patch<T = unknown>(endpoint: string, body: Record<string, unknown>): Promise<T> {
    return api<T>(endpoint, { method: 'PATCH', body });
}

/**
 * Make a DELETE request.
 */
export function del<T = unknown>(endpoint: string): Promise<T> {
    return api<T>(endpoint, { method: 'DELETE' });
}

/**
 * Build URLSearchParams from an object.
 */
export function buildParams(params: Record<string, string | number | boolean | null | undefined>): URLSearchParams {
    const query = new URLSearchParams();
    for (const [key, value] of Object.entries(params)) {
        if (value !== null && value !== undefined) {
            query.set(key, String(value));
        }
    }
    return query;
}
