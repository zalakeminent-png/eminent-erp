import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Sidebar } from '@/layout/Sidebar';
import { BomEditorPage } from '@/pages/master-data/documents/BomPages';
import { DocumentActions, BOM_KIND } from '@/pages/master-data/documents/DocumentCommon';
import { ProcessesPage } from '@/pages/master-data/SimpleMasters';
import ItemFormPage from '@/pages/master-data/items/ItemFormPage';
import type { VersionedDoc } from '@/pages/master-data/types';
import { makeUser, renderWithAuth } from '@/test/utils';

const get = vi.fn();
const post = vi.fn();
const put = vi.fn();

vi.mock('@/lib/api', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@/lib/api')>();
    return { ...actual, api: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a), put: (...a: unknown[]) => put(...a), patch: vi.fn(), delete: vi.fn() } };
});

const page = (data: unknown[]) => ({ data: { data, meta: { current_page: 1, last_page: 1, per_page: 15, total: data.length, from: 1, to: data.length } } });
const uoms = [
    { id: 1, code: 'PCS', name: 'Pieces', dimension: 'count', dimension_label: 'Count', universally_convertible: false, decimal_precision: 0, is_active: true },
    { id: 2, code: 'KG', name: 'Kilogram', dimension: 'mass', dimension_label: 'Mass', universally_convertible: true, decimal_precision: 3, is_active: true },
];
const items = [
    { id: 10, code: 'FG-STUD-001', name: 'SS316 Tactile Stud', item_type: 'finished_good', is_active: true, base_uom: { id: 1, code: 'PCS', name: 'Pieces', decimal_precision: 0 } },
    { id: 11, code: 'RM-SS316-001', name: 'SS316 Bar', item_type: 'raw_material', is_active: true, base_uom: { id: 2, code: 'KG', name: 'Kilogram', decimal_precision: 3 } },
];

beforeEach(() => {
    get.mockReset();
    post.mockReset();
    put.mockReset();
    get.mockImplementation((url: string) => {
        if (url === '/uoms') return Promise.resolve({ data: { data: uoms } });
        if (url === '/items') return Promise.resolve(page(items));
        if (url === '/manufacturing-processes') return Promise.resolve(page([{ id: 5, code: 'CNC', name: 'CNC Turning', process_type: 'internal', requires_qc: false, default_department: null, is_active: true }]));
        if (url === '/departments') return Promise.resolve({ data: { data: [] } });
        if (url === '/item-categories/tree') return Promise.resolve({ data: { data: [{ id: 3, code: 'FG', name: 'Finished Goods', sku_prefix: 'FG', default_item_type: 'finished_good', is_active: true, is_system: true, items_count: 0, children: [] }] } });
        if (url === '/items/next-code') return Promise.resolve({ data: { data: { mode: 'optional', example_prefix: 'FG', suggested_item_type: 'finished_good' } } });
        return Promise.resolve({ data: { data: [] } });
    });
});

describe('master data navigation', () => {
    it('shows only the master data screens the user may access', () => {
        renderWithAuth(<Sidebar open={false} onClose={() => undefined} />, { user: makeUser(['master-data.view', 'items.view', 'item-categories.view', 'uoms.view']) });
        const nav = screen.getAllByRole('navigation', { name: 'Main navigation' })[0];
        const links = within(nav).getAllByRole('link', { hidden: true }).map((l) => l.textContent);

        expect(links).toEqual(['Dashboard', 'Item Categories', 'Items / Products', 'Units of Measurement']);
        expect(within(nav).queryByText('Bill of Materials')).not.toBeInTheDocument();
        expect(within(nav).queryByText('Excel Import')).not.toBeInTheDocument();
    });
});

describe('simple master form', () => {
    it('creates a process through the modal with continuous typing', async () => {
        post.mockResolvedValue({ data: { data: {} } });
        renderWithAuth(<ProcessesPage />, { user: makeUser(['processes.view', 'processes.create', 'processes.update']) });

        expect(await screen.findByText('CNC Turning')).toBeInTheDocument();
        await userEvent.click(screen.getByRole('button', { name: 'Add process' }));

        const code = screen.getByLabelText(/Process code/);
        await userEvent.type(code, 'vmc');
        expect(code).toHaveValue('VMC'); // uppercased while typing
        expect(code).toHaveFocus();
        await userEvent.type(screen.getByLabelText(/Process name/), 'VMC Machining');
        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(post).toHaveBeenCalledWith('/manufacturing-processes', expect.objectContaining({ code: 'VMC', name: 'VMC Machining', process_type: 'internal', default_department_id: null }));
    });

    it('hides add/edit actions without permission', async () => {
        renderWithAuth(<ProcessesPage />, { user: makeUser(['processes.view']) });
        expect(await screen.findByText('CNC Turning')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Add process' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Edit CNC' })).not.toBeInTheDocument();
    });
});

describe('BOM line editor', () => {
    it('adds and removes components and submits the line payload', async () => {
        post.mockResolvedValue({ data: { data: { id: 7, number: 'BOM-00001' } } });
        renderWithAuth(
            <Routes><Route path="/master-data/boms/new" element={<BomEditorPage />} /><Route path="/master-data/boms/:id" element={<p>Details page</p>} /></Routes>,
            { user: makeUser(['boms.view', 'boms.create', 'boms.update']), route: '/master-data/boms/new' },
        );

        await userEvent.click(await screen.findByLabelText('Finished / semi-finished item'));
        await userEvent.click(await screen.findByRole('option', { name: /FG-STUD-001/ }));

        const pickers = screen.getAllByPlaceholderText('Component item');
        await userEvent.click(pickers[0]);
        await userEvent.click(await screen.findByRole('option', { name: /RM-SS316-001/ }));
        await userEvent.type(screen.getByLabelText('Quantity line 1'), '12.5');
        await userEvent.clear(screen.getByLabelText('Scrap % line 1'));
        await userEvent.type(screen.getByLabelText('Scrap % line 1'), '2');

        await userEvent.click(screen.getByRole('button', { name: 'Add component' }));
        expect(screen.getByLabelText('Quantity line 2')).toBeInTheDocument();
        await userEvent.click(screen.getByRole('button', { name: 'Remove line 2' }));
        expect(screen.queryByLabelText('Quantity line 2')).not.toBeInTheDocument();

        await userEvent.click(screen.getByRole('button', { name: 'Save draft' }));

        expect(post).toHaveBeenCalledWith('/boms', expect.objectContaining({
            item_id: 10, output_qty: '1000', output_uom_id: 1,
            lines: [{ component_item_id: 11, quantity: '12.5', uom_id: 2, scrap_percent: '2', remarks: null }],
        }));
        expect(await screen.findByText('Details page')).toBeInTheDocument();
    });

    it('shows server validation errors on the offending line', async () => {
        post.mockRejectedValue({
            isAxiosError: true,
            response: { status: 422, data: { message: 'Invalid', errors: { 'lines.0.component_item_id': ['Adding FG-STUD-001 would create a circular BOM.'] } } },
        });
        renderWithAuth(<BomEditorPage />, { user: makeUser(['boms.create']), route: '/master-data/boms/new' });

        await userEvent.click(await screen.findByLabelText('Finished / semi-finished item'));
        await userEvent.click(await screen.findByRole('option', { name: /FG-STUD-001/ }));
        await userEvent.click(screen.getAllByPlaceholderText('Component item')[0]);
        await userEvent.click(await screen.findByRole('option', { name: /FG-STUD-001/ }));
        await userEvent.type(screen.getByLabelText('Quantity line 1'), '1');
        await userEvent.click(screen.getByRole('button', { name: 'Save draft' }));

        expect(await screen.findByText(/circular BOM/)).toBeInTheDocument();
    });
});

describe('document approval actions', () => {
    const draft: VersionedDoc = {
        id: 7, number: 'BOM-00001', version: 1, item_id: 10, description: null, effective_from: '2026-10-20', effective_to: null,
        status: 'draft', is_editable: true, approved_at: null, created_at: null, updated_at: null,
    };

    it('offers approval only with the approve permission', () => {
        const { unmount } = renderWithAuth(<DocumentActions kind={BOM_KIND} doc={draft} onChanged={() => undefined} />, { user: makeUser(['boms.view', 'boms.update']) });
        expect(screen.queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Edit draft' })).toBeInTheDocument();
        unmount();

        renderWithAuth(<DocumentActions kind={BOM_KIND} doc={draft} onChanged={() => undefined} />, { user: makeUser(['boms.view', 'boms.approve']) });
        expect(screen.getByRole('button', { name: 'Approve' })).toBeInTheDocument();
    });

    it('does not offer editing for an approved (frozen) version', () => {
        renderWithAuth(<DocumentActions kind={BOM_KIND} doc={{ ...draft, status: 'approved', is_editable: false, approved_at: '2026-10-20T10:00:00Z' }} onChanged={() => undefined} />,
            { user: makeUser(['boms.view', 'boms.update', 'boms.approve', 'boms.create']) });
        expect(screen.queryByRole('link', { name: 'Edit draft' })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Deactivate' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Duplicate as revision' })).toBeInTheDocument();
    });
});

describe('item form', () => {
    it('validates required fields and posts the item on desktop and mobile widths', async () => {
        post.mockResolvedValue({ data: { data: { id: 99 }, message: 'Item FG-001 created.' } });

        for (const width of [1280, 375]) {
            window.innerWidth = width;
            window.dispatchEvent(new Event('resize'));
            post.mockClear();
            const { unmount } = renderWithAuth(<ItemFormPage />, { user: makeUser(['items.create']), route: '/master-data/items/new' });

            await userEvent.click(await screen.findByRole('button', { name: 'Create item' }));
            expect(screen.getByText('Item name is required.')).toBeInTheDocument();
            expect(post).not.toHaveBeenCalled();

            await userEvent.type(screen.getByLabelText(/Item name/), 'SS316 Stud');
            await userEvent.selectOptions(screen.getByLabelText(/Item category/), '3');
            await userEvent.selectOptions(screen.getByLabelText(/Base unit of measurement/), '1');
            await userEvent.click(screen.getByRole('tab', { name: 'Stock Configuration' }));
            await userEvent.click(screen.getByLabelText(/^Manufactured/));
            await userEvent.click(screen.getByRole('button', { name: 'Create item' }));

            expect(post).toHaveBeenCalledWith('/items', expect.objectContaining({ name: 'SS316 Stud', category_id: 3, base_uom_id: 1, is_manufactured: true, code: null, allow_negative_stock: false }));
            unmount();
        }
    });
});
