import axios, { AxiosError, type InternalAxiosRequestConfig } from 'axios';

/**
 * API client for Sanctum SPA (cookie) authentication. The session lives in
 * an HttpOnly cookie; nothing auth-related is ever written to localStorage.
 * Axios copies the XSRF-TOKEN cookie into the X-XSRF-TOKEN header.
 */
export const api = axios.create({
    baseURL: '/api/v1',
    withCredentials: true,
    withXSRFToken: true,
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

let csrfRequest: Promise<void> | null = null;

export function ensureCsrfCookie(force = false): Promise<void> {
    if (force || !csrfRequest) {
        csrfRequest = axios
            .get('/sanctum/csrf-cookie', { withCredentials: true })
            .then(() => undefined)
            .catch((error) => {
                csrfRequest = null;
                throw error;
            });
    }
    return csrfRequest;
}

export const AUTH_EXPIRED_EVENT = 'erp:auth-expired';

type RetriableConfig = InternalAxiosRequestConfig & { _csrfRetried?: boolean };

api.interceptors.response.use(
    (response) => response,
    async (error: AxiosError<{ message?: string }>) => {
        const status = error.response?.status;
        const config = error.config as RetriableConfig | undefined;

        // CSRF token mismatch (e.g. session rotated): refresh once and retry.
        if (status === 419 && config && !config._csrfRetried) {
            config._csrfRetried = true;
            await ensureCsrfCookie(true);
            return api.request(config);
        }

        const isSessionEndpoint = config?.url?.startsWith('/auth/login') || config?.url?.startsWith('/auth/me');
        const deactivated = status === 403 && /deactivated/i.test(error.response?.data?.message ?? '');

        if ((status === 401 || deactivated) && !isSessionEndpoint) {
            window.dispatchEvent(new CustomEvent(AUTH_EXPIRED_EVENT, { detail: error.response?.data?.message }));
        }

        return Promise.reject(error);
    },
);

export function errorMessage(error: unknown, fallback = 'Something went wrong. Please try again.'): string {
    if (axios.isAxiosError(error)) {
        if (!error.response) return 'Unable to reach the server. Check your connection.';
        const data = error.response.data as { message?: string } | undefined;
        if (error.response.status >= 500) return fallback;
        return data?.message || fallback;
    }
    return fallback;
}

/** Laravel validation errors flattened to the first message per field. */
export function fieldErrors(error: unknown): Record<string, string> {
    if (axios.isAxiosError(error) && error.response?.status === 422) {
        const errors = (error.response.data as { errors?: Record<string, string[]> })?.errors ?? {};
        return Object.fromEntries(Object.entries(errors).map(([key, messages]) => [key, messages[0]]));
    }
    return {};
}
