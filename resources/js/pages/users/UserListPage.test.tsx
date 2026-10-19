import { screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import UserListPage from '@/pages/users/UserListPage';
import { makeUser, renderWithAuth } from '@/test/utils';

const get = vi.fn();

vi.mock('@/lib/api', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@/lib/api')>();
    return { ...actual, api: { get: (...args: unknown[]) => get(...args), post: vi.fn(), patch: vi.fn() } };
});

const listedUser = {
    id: 2, employee_code: 'EMP-7', first_name: 'Meera', last_name: 'Iyer', full_name: 'Meera Iyer', email: 'meera@factory.test',
    mobile: null, department_id: null, is_active: true, last_login_at: null, deactivated_at: null, created_at: null, updated_at: null,
    roles: [{ id: 3, name: 'Store Keeper', is_active: true }],
};

beforeEach(() => {
    get.mockReset();
    get.mockImplementation((url: string) =>
        Promise.resolve({
            data: url === '/users'
                ? { data: [listedUser], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1, from: 1, to: 1 } }
                : { data: [{ id: 3, name: 'Store Keeper' }] },
        }),
    );
});

describe('user list actions', () => {
    it('hides create/edit/status/reset actions from a view-only user', async () => {
        renderWithAuth(<UserListPage />, { user: makeUser(['users.view']), route: '/settings/users' });

        expect(await screen.findByText('Meera Iyer')).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Add user' })).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Edit Meera Iyer' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Deactivate Meera Iyer' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Send password reset to Meera Iyer' })).not.toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'View Meera Iyer' })).toBeInTheDocument();
    });

    it('shows management actions to a user administrator', async () => {
        renderWithAuth(<UserListPage />, {
            user: makeUser(['users.view', 'users.create', 'users.update', 'users.deactivate', 'users.reset-password']),
            route: '/settings/users',
        });

        expect(await screen.findByText('Meera Iyer')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Add user' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Edit Meera Iyer' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Deactivate Meera Iyer' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Send password reset to Meera Iyer' })).toBeInTheDocument();
    });
});
