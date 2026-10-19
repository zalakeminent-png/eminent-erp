import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import StructureTypesPage from '@/pages/structure/StructureTypesPage';
import { makeUser, renderWithAuth } from '@/test/utils';

const get = vi.fn();
const post = vi.fn();
const put = vi.fn();

vi.mock('@/lib/api', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@/lib/api')>();
    return { ...actual, api: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a), put: (...a: unknown[]) => put(...a) } };
});

const types = [
    { id: 1, category: 'department', name: 'Stores', description: null, sort_order: 1, is_active: true, usage_count: 0 },
    { id: 2, category: 'department', name: 'Casting', description: 'Foundry', sort_order: 2, is_active: true, usage_count: 0 },
    { id: 3, category: 'warehouse', name: 'General Store', description: null, sort_order: 1, is_active: true, usage_count: 0 },
];

const user = makeUser(['structure-types.view', 'structure-types.create', 'structure-types.update']);

beforeEach(() => {
    get.mockReset().mockResolvedValue({ data: { data: types } });
    post.mockReset().mockResolvedValue({ data: { data: {} } });
    put.mockReset().mockResolvedValue({ data: { data: {} } });
});

describe('department & warehouse type pop-ups', () => {
    it('accepts continuous multi-character input in the Add form without losing focus', async () => {
        renderWithAuth(<StructureTypesPage />, { user });
        await screen.findByText('Stores');

        await userEvent.click(screen.getAllByRole('button', { name: 'Add type' })[0]);

        const name = screen.getByLabelText(/^Name/);
        expect(name).toHaveFocus(); // autoFocus is respected
        await userEvent.type(name, 'Quality Control');
        expect(name).toHaveValue('Quality Control');
        expect(name).toHaveFocus();

        const description = screen.getByLabelText('Description');
        await userEvent.click(description);
        await userEvent.type(description, 'Inspection of castings.');
        expect(description).toHaveValue('Inspection of castings.');
        expect(description).toHaveFocus();

        const sort = screen.getByLabelText('Sort order');
        expect(sort).toHaveValue(3); // next sequential position
        await userEvent.clear(sort);
        await userEvent.type(sort, '12');
        expect(sort).toHaveValue(12);
        expect(sort).toHaveFocus();

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));
        expect(post).toHaveBeenCalledWith('/structure-types', {
            category: 'department', name: 'Quality Control', description: 'Inspection of castings.', sort_order: 12, is_active: true,
        });
    });

    it('accepts continuous input in the Edit form for warehouse types', async () => {
        renderWithAuth(<StructureTypesPage />, { user });
        await screen.findByText('General Store');

        await userEvent.click(screen.getByRole('button', { name: 'Edit General Store' }));

        const name = screen.getByLabelText(/^Name/);
        await userEvent.clear(name);
        await userEvent.type(name, 'Central Store');
        expect(name).toHaveValue('Central Store');
        expect(name).toHaveFocus();

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));
        expect(put).toHaveBeenCalledWith('/structure-types/3', {
            name: 'Central Store', description: null, sort_order: 1, is_active: true,
        });
    });

    it('still closes with Escape', async () => {
        renderWithAuth(<StructureTypesPage />, { user });
        await screen.findByText('Stores');

        await userEvent.click(screen.getAllByRole('button', { name: 'Add type' })[0]);
        await userEvent.type(screen.getByLabelText(/^Name/), 'Paint');
        await userEvent.keyboard('{Escape}');
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
});
