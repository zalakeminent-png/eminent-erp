import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Sidebar } from '@/layout/Sidebar';
import { LocationTree } from '@/pages/structure/components/LocationTree';
import DepartmentListPage from '@/pages/structure/departments/DepartmentListPage';
import FactoryListPage from '@/pages/structure/factories/FactoryListPage';
import FactoryStructurePage from '@/pages/structure/FactoryStructurePage';
import type { LocationNode } from '@/pages/structure/types';
import { makeUser, renderWithAuth } from '@/test/utils';

const get = vi.fn();
const del = vi.fn();

vi.mock('@/lib/api', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@/lib/api')>();
    return { ...actual, api: { get: (...args: unknown[]) => get(...args), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: (...args: unknown[]) => del(...args) } };
});

const node = (id: number, code: string, children: LocationNode[] = [], extra: Partial<LocationNode> = {}): LocationNode => ({
    id, code, name: `Name ${code}`, type: 'rack', type_label: 'Rack', is_virtual: false, is_pickable: true, is_receivable: true, is_active: true, children, ...extra,
});

const tree = [node(1, 'RACK-A', [node(2, 'SHELF-A1'), node(3, 'SHELF-A2', [], { is_virtual: true })]), node(4, 'RACK-B')];

beforeEach(() => { get.mockReset(); del.mockReset(); });

describe('factory setup navigation', () => {
    const links = () => within(screen.getAllByRole('navigation', { name: 'Main navigation' })[0]).queryAllByRole('link', { hidden: true }).map((l) => l.textContent);

    it('shows only the factory setup screens the user is permitted to see', () => {
        renderWithAuth(<Sidebar open={false} onClose={() => undefined} />, { user: makeUser(['warehouses.view', 'locations.view']) });

        // Warehouses are internal and no longer have their own screen.
        expect(links()).toEqual(['Storage Locations']);
        expect(screen.queryAllByText('Factories')).toHaveLength(0);
        expect(screen.queryAllByText('Departments')).toHaveLength(0);
    });

    it('shows the full factory setup group to a structure administrator', () => {
        renderWithAuth(<Sidebar open={false} onClose={() => undefined} />, {
            user: makeUser(['factory-structure.view', 'factories.view', 'departments.view', 'warehouses.view', 'locations.view', 'structure-types.view']),
        });

        expect(links()).toEqual(['Structure Overview', 'Factories', 'Departments', 'Storage Locations', 'Types']);
    });
});

describe('location tree', () => {
    it('renders the nested hierarchy and collapses/expands nodes', async () => {
        renderWithAuth(<LocationTree nodes={tree} />);

        expect(screen.getByText('RACK-A')).toBeInTheDocument();
        expect(screen.getByText('SHELF-A1')).toBeInTheDocument();
        expect(screen.getByText('Virtual')).toBeInTheDocument();

        await userEvent.click(screen.getByRole('button', { name: 'Collapse RACK-A' }));
        expect(screen.queryByText('SHELF-A1')).not.toBeInTheDocument();
        expect(screen.getByText('RACK-B')).toBeInTheDocument();

        await userEvent.click(screen.getByRole('button', { name: 'Expand RACK-A' }));
        expect(screen.getByText('SHELF-A2')).toBeInTheDocument();
    });

    it('offers "add child" links only when allowed', () => {
        const { unmount } = renderWithAuth(<LocationTree nodes={tree} canAddChild addChildHref={(n) => `/new?parent_id=${n.id}`} />);
        expect(screen.getByRole('link', { name: 'Add child location under RACK-A' })).toHaveAttribute('href', '/new?parent_id=1');
        unmount();

        renderWithAuth(<LocationTree nodes={tree} />);
        expect(screen.queryByRole('link', { name: /Add child location/ })).not.toBeInTheDocument();
    });
});

describe('factory list', () => {
    beforeEach(() => {
        get.mockResolvedValue({
            data: {
                data: [{ id: 7, code: 'PUNE', name: 'Pune Plant', type: 'manufacturing', type_label: 'Manufacturing', city: 'Pune', is_active: true, departments_count: 3, warehouses_count: 2 }],
                meta: { current_page: 1, last_page: 1, per_page: 15, total: 1, from: 1, to: 1 },
            },
        });
    });

    it('hides create/edit/deactivate actions from a view-only user', async () => {
        renderWithAuth(<FactoryListPage />, { user: makeUser(['factories.view']), route: '/factory-setup/factories' });

        expect(await screen.findByText('Pune Plant')).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Add factory' })).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Edit Pune Plant' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Deactivate Pune Plant' })).not.toBeInTheDocument();
    });

    it('shows management actions with the matching permissions', async () => {
        renderWithAuth(<FactoryListPage />, { user: makeUser(['factories.view', 'factories.create', 'factories.update', 'factories.deactivate']), route: '/factory-setup/factories' });

        expect(await screen.findByText('Pune Plant')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Add factory' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Edit Pune Plant' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Deactivate Pune Plant' })).toBeInTheDocument();
    });
});

describe('factory structure overview', () => {
    it('renders company, factory, departments and storage locations (no warehouse level)', async () => {
        get.mockResolvedValue({
            data: {
                data: {
                    company: { id: 1, name: 'Eminent Castings', code: 'EMC' },
                    totals: { factories: 1, departments: 1, warehouses: 1, locations: 4 },
                    factories: [{
                        id: 7, code: 'PUNE', name: 'Pune Plant', type_label: 'Manufacturing', city: 'Pune', is_active: true,
                        counts: { departments: 1, warehouses: 1, locations: 4 },
                        departments: [{ id: 3, code: 'CAST', name: 'Casting', is_active: true, type: 'Casting', manager: 'Asha Rao', supervisor: null }],
                        warehouses: [{ id: 5, code: 'RMS', name: 'Raw Material Store', is_active: true, type: null, department: null, locations_count: 4, locations: tree }],
                    }],
                },
            },
        });

        renderWithAuth(<FactoryStructurePage />, { user: makeUser(['factory-structure.view', 'factories.view']), route: '/factory-setup/structure' });

        expect(await screen.findByRole('link', { name: 'Pune Plant' })).toHaveAttribute('href', '/factory-setup/factories/7');
        expect(screen.getAllByText('Eminent Castings').length).toBeGreaterThan(0);
        expect(screen.getByText('Casting')).toBeInTheDocument();
        expect(screen.getByText('Manager: Asha Rao')).toBeInTheDocument();
        expect(screen.getByText('RACK-A')).toBeInTheDocument();
        expect(screen.queryByText('Raw Material Store')).not.toBeInTheDocument();
        expect(screen.getByText('1 dept · 4 loc')).toBeInTheDocument();
        // No permission to view departments: plain text, not a link.
        expect(screen.queryByRole('link', { name: 'Casting' })).not.toBeInTheDocument();
        // No export permission.
        expect(screen.queryByRole('button', { name: /Export CSV/ })).not.toBeInTheDocument();

        await userEvent.click(screen.getByRole('button', { name: 'Collapse Pune Plant' }));
        expect(screen.queryByText('RACK-A')).not.toBeInTheDocument();
    });

    it('shows an empty state when nothing is set up', async () => {
        get.mockResolvedValue({ data: { data: { company: null, totals: { factories: 0, departments: 0, warehouses: 0, locations: 0 }, factories: [] } } });

        renderWithAuth(<FactoryStructurePage />, { user: makeUser(['factory-structure.view']) });

        expect(await screen.findByText('No factories set up yet')).toBeInTheDocument();
    });
});

describe("department delete", () => {
    const department = { id: 12, factory_id: 7, factory: { id: 7, code: "PUNE", name: "Pune Plant" }, code: "PACK", name: "Packing", type: null, manager: null, supervisor: null, members_count: 0, is_active: true };
    const listResponse = { data: { data: [department], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1, from: 1, to: 1 } } };

    beforeEach(() => {
        get.mockImplementation((url: string) => Promise.resolve(url === "/departments" ? listResponse : { data: { data: [] } }));
    });

    it("deletes only after confirmation and refreshes the list", async () => {
        del.mockResolvedValue({ data: { message: "Packing has been deleted." } });
        renderWithAuth(<DepartmentListPage />, { user: makeUser(["departments.view", "departments.delete"]) });

        await userEvent.click(await screen.findByRole("button", { name: "Delete Packing" }));
        expect(del).not.toHaveBeenCalled();
        expect(screen.getByRole("dialog")).toHaveTextContent("permanently delete Packing");
        const loadsBefore = get.mock.calls.filter(([u]) => u === "/departments").length;

        await userEvent.click(within(screen.getByRole("dialog")).getByRole("button", { name: "Delete" }));
        expect(del).toHaveBeenCalledWith("/departments/12");
        expect(await screen.findByText("Packing has been deleted.")).toBeInTheDocument();
        expect(get.mock.calls.filter(([u]) => u === "/departments").length).toBeGreaterThan(loadsBefore);
    });

    it("leaves the department unchanged when cancelled", async () => {
        renderWithAuth(<DepartmentListPage />, { user: makeUser(["departments.view", "departments.delete"]) });

        await userEvent.click(await screen.findByRole("button", { name: "Delete Packing" }));
        await userEvent.click(within(screen.getByRole("dialog")).getByRole("button", { name: "Cancel" }));
        expect(del).not.toHaveBeenCalled();
        expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
        expect(screen.getByText("Packing")).toBeInTheDocument();
    });

    it("shows the server refusal and hides the button without permission", async () => {
        del.mockRejectedValue({ isAxiosError: true, response: { status: 409, data: { message: "Packing cannot be deleted because it has assigned employees." } } });
        const { unmount } = renderWithAuth(<DepartmentListPage />, { user: makeUser(["departments.view", "departments.delete"]) });
        await userEvent.click(await screen.findByRole("button", { name: "Delete Packing" }));
        await userEvent.click(within(screen.getByRole("dialog")).getByRole("button", { name: "Delete" }));
        expect(await screen.findByText(/cannot be deleted|Something went wrong/)).toBeInTheDocument();
        unmount();

        renderWithAuth(<DepartmentListPage />, { user: makeUser(["departments.view", "departments.update", "departments.deactivate"]) });
        expect(await screen.findByText("Packing")).toBeInTheDocument();
        expect(screen.queryByRole("button", { name: "Delete Packing" })).not.toBeInTheDocument();
    });
});