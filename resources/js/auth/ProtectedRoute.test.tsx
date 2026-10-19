import { Route, Routes } from 'react-router-dom';
import { screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { GuestOnly, RequireAuth, RequirePermission } from '@/auth/ProtectedRoute';
import { makeUser, renderWithAuth } from '@/test/utils';

function TestRoutes() {
    return (
        <Routes>
            <Route element={<GuestOnly />}>
                <Route path="/login" element={<p>Login screen</p>} />
            </Route>
            <Route element={<RequireAuth />}>
                <Route path="/" element={<p>Dashboard screen</p>} />
                <Route
                    path="/settings/users"
                    element={
                        <RequirePermission permission="users.view">
                            <p>User list screen</p>
                        </RequirePermission>
                    }
                />
            </Route>
        </Routes>
    );
}

describe('protected routes', () => {
    it('redirects guests to the login page', () => {
        renderWithAuth(<TestRoutes />, { status: 'guest', route: '/settings/users' });

        expect(screen.getByText('Login screen')).toBeInTheDocument();
        expect(screen.queryByText('User list screen')).not.toBeInTheDocument();
    });

    it('shows a loading indicator while the session is being checked', () => {
        renderWithAuth(<TestRoutes />, { status: 'loading', route: '/' });

        expect(screen.getByRole('status', { name: 'Loading' })).toBeInTheDocument();
        expect(screen.queryByText('Dashboard screen')).not.toBeInTheDocument();
        expect(screen.queryByText('Login screen')).not.toBeInTheDocument();
    });

    it('renders protected content for authenticated users with permission', () => {
        renderWithAuth(<TestRoutes />, { user: makeUser(['users.view']), route: '/settings/users' });

        expect(screen.getByText('User list screen')).toBeInTheDocument();
    });

    it('shows access denied when the permission is missing', () => {
        renderWithAuth(<TestRoutes />, { user: makeUser(['dashboard.view']), route: '/settings/users' });

        expect(screen.getByRole('heading', { name: 'Access denied' })).toBeInTheDocument();
        expect(screen.queryByText('User list screen')).not.toBeInTheDocument();
    });

    it('sends signed-in users away from the login page', () => {
        renderWithAuth(<TestRoutes />, { user: makeUser([]), route: '/login' });

        expect(screen.getByText('Dashboard screen')).toBeInTheDocument();
    });
});
