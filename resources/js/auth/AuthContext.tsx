import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { api, AUTH_EXPIRED_EVENT, ensureCsrfCookie } from '@/lib/api';
import type { AuthUser } from '@/types';

export type AuthStatus = 'loading' | 'authenticated' | 'guest';

export interface AuthContextValue {
    user: AuthUser | null;
    status: AuthStatus;
    /** Message to show on the login screen after a forced sign-out. */
    notice: string | null;
    login: (email: string, password: string, remember: boolean) => Promise<AuthUser>;
    logout: () => Promise<void>;
    refresh: () => Promise<void>;
    setUser: (user: AuthUser) => void;
    /** True when the user holds ANY of the given permissions. UI hint only; the API enforces access. */
    can: (permission: string | string[]) => boolean;
}

export const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
    const [user, setUserState] = useState<AuthUser | null>(null);
    const [status, setStatus] = useState<AuthStatus>('loading');
    const [notice, setNotice] = useState<string | null>(null);

    const setUser = useCallback((next: AuthUser) => {
        setUserState(next);
        setStatus('authenticated');
    }, []);

    const clear = useCallback((message: string | null = null) => {
        setUserState(null);
        setStatus('guest');
        setNotice(message);
    }, []);

    const refresh = useCallback(async () => {
        try {
            const { data } = await api.get<{ data: AuthUser }>('/auth/me');
            setUser(data.data);
        } catch {
            clear();
        }
    }, [setUser, clear]);

    useEffect(() => {
        void refresh();
    }, [refresh]);

    useEffect(() => {
        const onExpired = (event: Event) => {
            const detail = (event as CustomEvent<string | undefined>).detail;
            clear(detail && /deactivated/i.test(detail) ? detail : 'Your session has ended. Please sign in again.');
        };
        window.addEventListener(AUTH_EXPIRED_EVENT, onExpired);
        return () => window.removeEventListener(AUTH_EXPIRED_EVENT, onExpired);
    }, [clear]);

    const login = useCallback(
        async (email: string, password: string, remember: boolean) => {
            await ensureCsrfCookie();
            const { data } = await api.post<{ data: AuthUser }>('/auth/login', { email, password, remember });
            setNotice(null);
            setUser(data.data);
            // The session ID and CSRF token rotate on login.
            await ensureCsrfCookie(true);
            return data.data;
        },
        [setUser],
    );

    const logout = useCallback(async () => {
        try {
            await api.post('/auth/logout');
        } finally {
            clear();
            await ensureCsrfCookie(true).catch(() => undefined);
        }
    }, [clear]);

    const can = useCallback(
        (permission: string | string[]) => {
            if (!user) return false;
            const wanted = Array.isArray(permission) ? permission : [permission];
            return wanted.some((p) => user.permissions.includes(p));
        },
        [user],
    );

    const value = useMemo<AuthContextValue>(
        () => ({ user, status, notice, login, logout, refresh, setUser, can }),
        [user, status, notice, login, logout, refresh, setUser, can],
    );

    return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
    const context = useContext(AuthContext);
    if (!context) throw new Error('useAuth must be used within <AuthProvider>');
    return context;
}

/** Renders children only when the user holds one of the permissions. */
export function Can({ permission, children, fallback = null }: { permission: string | string[]; children: ReactNode; fallback?: ReactNode }) {
    const { can } = useAuth();
    return <>{can(permission) ? children : fallback}</>;
}
