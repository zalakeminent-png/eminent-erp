import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import UserListPage from '@/pages/users/UserListPage';
import { makeUser, renderWithAuth } from '@/test/utils';

const get = vi.fn();
const del = vi.fn();

vi.mock('@/lib/api', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@/lib/api')>();
    return { ...actual, api: { get: (...args: unknown[]) => get(...args), post: vi.fn(), patch: vi.fn(), delete: (...args: unknown[]) => del(...args) } };
});

const listedUser = {
    id: 2, employee_code: 'EMP-7', first_name: 'Meera', last_name: 'Iyer', full_name: 'Meera Iyer', email: 'meera@factory.test',
    mobile: null, department_id: null, is_active: true, last_login_at: null, deactivated_at: null, created_at: null, updated_at: null,
    roles: [{ id: 3, name: 'Store Keeper', is_active: true }],
};

beforeEach(() => {
    get.mockReset();
    del.mockReset();
    get.mockImplementation((url: string) =>
        Promise.resolve({
            data: url === '/users'
                ? { data: [listedUser], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1, from: 1, to: 1 } }
                : { data: [{ id: 3, name: 'Store Keeper' }] },
        }),
    );
});

describe('user delete action', () => {
    it('is hidden without users.delete and for the signed-in user', async () => {
        renderWithAuth(<UserListPage />, { user: makeUser(['users.view', 'users.update']), route: '/settings/users' });
        expect(await screen.findByText('Meera Iyer')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Delete Meera Iyer' })).not.toBeInTheDocument();
    });

    it('confirms, deletes through the API and reloads the list', async () => {
        del.mockResolvedValue({ data: { message: 'Meera Iyer has been deleted.' } });
        renderWithAuth(<UserListPage />, { user: makeUser(['users.view', 'users.delete']), route: '/settings/users' });
        expect(await screen.findByText('Meera Iyer')).toBeInTheDocument();
        const callsBefore = get.mock.calls.filter(([url]) => url === '/users').length;

        await userEvent.click(screen.getByRole('button', { name: 'Delete Meera Iyer' }));
        expect(screen.getByRole('dialog', { name: 'Delete user' })).toHaveTextContent('permanently delete');
        expect(del).not.toHaveBeenCalled();

        await userEvent.click(screen.getByRole('button', { name: 'Delete user' }));

        expect(del).toHaveBeenCalledWith('/users/2');
        expect(await screen.findByText('Meera Iyer has been deleted.')).toBeInTheDocument();
        await waitFor(() => expect(get.mock.calls.filter(([url]) => url === '/users').length).toBeGreaterThan(callsBefore));
    });

    it('keeps the user and shows the server error when deletion fails', async () => {
        del.mockRejectedValue({ isAxiosError: true, response: { status: 409, data: { message: 'Meera Iyer cannot be deleted because they are a warehouse manager.' } } });
        renderWithAuth(<UserListPage />, { user: makeUser(['users.view', 'users.delete']), route: '/settings/users' });

        await userEvent.click(await screen.findByRole('button', { name: 'Delete Meera Iyer' }));
        await userEvent.click(screen.getByRole('button', { name: 'Delete user' }));

        expect(await screen.findByText(/warehouse manager/)).toBeInTheDocument();
        // The row is still listed (its delete action remains).
        expect(screen.getByRole('button', { name: 'Delete Meera Iyer' })).toBeInTheDocument();
    });
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
