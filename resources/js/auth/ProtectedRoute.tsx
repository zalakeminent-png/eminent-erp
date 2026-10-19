import { Navigate, Outlet, useLocation } from 'react-router-dom';
import { useAuth } from '@/auth/AuthContext';
import { FullPageSpinner } from '@/components/ui/Spinner';
import ForbiddenPage from '@/pages/ForbiddenPage';
import type { ReactNode } from 'react';

/** Requires an authenticated session; otherwise redirects to /login. */
export function RequireAuth() {
    const { status } = useAuth();
    const location = useLocation();

    if (status === 'loading') return <FullPageSpinner />;
    if (status === 'guest') {
        return <Navigate to="/login" replace state={{ from: `${location.pathname}${location.search}` }} />;
    }
    return <Outlet />;
}

/** Only for signed-out users (login, forgot password). */
export function GuestOnly() {
    const { status } = useAuth();
    if (status === 'loading') return <FullPageSpinner />;
    if (status === 'authenticated') return <Navigate to="/" replace />;
    return <Outlet />;
}

/**
 * Shows a 403 screen when the user lacks the permission. This is a UX
 * convenience only — every API call is authorized again on the server.
 */
export function RequirePermission({ permission, children }: { permission: string | string[]; children?: ReactNode }) {
    const { can } = useAuth();
    if (!can(permission)) return <ForbiddenPage />;
    return <>{children ?? <Outlet />}</>;
}
