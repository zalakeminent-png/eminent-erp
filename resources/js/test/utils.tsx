import type { ReactNode } from 'react';
import { MemoryRouter } from 'react-router-dom';
import { render } from '@testing-library/react';
import { AuthContext, type AuthContextValue, type AuthStatus } from '@/auth/AuthContext';
import { ToastProvider } from '@/components/ui/Toast';
import type { AuthUser } from '@/types';

export function makeUser(permissions: string[], overrides: Partial<AuthUser> = {}): AuthUser {
    return {
        id: 1,
        employee_code: null,
        first_name: 'Test',
        last_name: 'User',
        full_name: 'Test User',
        email: 'test@factory.test',
        mobile: null,
        department_id: null,
        is_active: true,
        last_login_at: null,
        deactivated_at: null,
        created_at: null,
        updated_at: null,
        roles: [{ id: 9, name: 'Tester', is_active: true }],
        permissions,
        company: { id: 1, name: 'Eminent Castings', code: 'EMC', logo_url: null },
        ...overrides,
    };
}

export function authValue(status: AuthStatus, user: AuthUser | null = null): AuthContextValue {
    return {
        user,
        status,
        notice: null,
        login: async () => user!,
        logout: async () => undefined,
        refresh: async () => undefined,
        setUser: () => undefined,
        can: (permission) => {
            if (!user) return false;
            const wanted = Array.isArray(permission) ? permission : [permission];
            return wanted.some((p) => user.permissions.includes(p));
        },
    };
}

/** Renders UI with a fixed auth state, bypassing the real /auth/me call. */
export function renderWithAuth(ui: ReactNode, { status = 'authenticated', user = null, route = '/' }: { status?: AuthStatus; user?: AuthUser | null; route?: string } = {}) {
    return render(
        <MemoryRouter initialEntries={[route]} future={{ v7_startTransition: true, v7_relativeSplatPath: true }}>
            <ToastProvider>
                <AuthContext.Provider value={authValue(status, user)}>{ui}</AuthContext.Provider>
            </ToastProvider>
        </MemoryRouter>,
    );
}
