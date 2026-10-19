import { useCallback, useEffect, useRef, useState } from 'react';
import { api, errorMessage } from '@/lib/api';

interface ApiState<T> {
    data: T | null;
    loading: boolean;
    error: string | null;
    reload: () => void;
    setData: (data: T | null) => void;
}

/**
 * Minimal GET hook: fetches `url` (skipped when null), refetches when the
 * URL or params change, and ignores responses from superseded requests.
 */
export function useApi<T>(url: string | null, params?: Record<string, unknown>): ApiState<T> {
    const [data, setData] = useState<T | null>(null);
    const [loading, setLoading] = useState(url !== null);
    const [error, setError] = useState<string | null>(null);
    const [nonce, setNonce] = useState(0);
    const requestId = useRef(0);
    const paramsKey = JSON.stringify(params ?? {});

    useEffect(() => {
        if (url === null) {
            setLoading(false);
            return;
        }
        const id = ++requestId.current;
        setLoading(true);
        setError(null);

        const cleanParams = Object.fromEntries(
            Object.entries(JSON.parse(paramsKey) as Record<string, unknown>).filter(([, v]) => v !== '' && v !== null && v !== undefined),
        );

        api.get<T>(url, { params: cleanParams })
            .then((response) => {
                if (id === requestId.current) setData(response.data);
            })
            .catch((err) => {
                if (id === requestId.current) setError(errorMessage(err, 'Unable to load data.'));
            })
            .finally(() => {
                if (id === requestId.current) setLoading(false);
            });
    }, [url, paramsKey, nonce]);

    const reload = useCallback(() => setNonce((n) => n + 1), []);

    return { data, loading, error, reload, setData };
}

export function useDebouncedValue<T>(value: T, delay = 350): T {
    const [debounced, setDebounced] = useState(value);
    useEffect(() => {
        const timer = setTimeout(() => setDebounced(value), delay);
        return () => clearTimeout(timer);
    }, [value, delay]);
    return debounced;
}
