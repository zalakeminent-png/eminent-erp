import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Sidebar } from '@/layout/Sidebar';
import { makeUser, renderWithAuth } from '@/test/utils';
import InventoryDashboardPage from './InventoryDashboardPage';
import { InventoryDocumentDetailsPage, InventoryDocumentEditorPage } from './documents/DocumentPages';
import { DOCS } from './documents/config';
import { StockCountDetailsPage } from './StockCountPages';
import { OpeningStockDetailsPage } from './OpeningStockPages';
import { STOCK_VIEWS, StockListPage } from './StockPages';

const get = vi.fn();
const post = vi.fn();
const put = vi.fn();

vi.mock('@/lib/api', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@/lib/api')>();
    return { ...actual, api: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a), put: (...a: unknown[]) => put(...a), patch: vi.fn(), delete: vi.fn() } };
});

const meta = (n: number) => ({ current_page: 1, last_page: 1, per_page: 25, total: n, from: n ? 1 : null, to: n || null });
const kg = { id: 2, code: 'KG', name: 'Kilogram', decimal_precision: 3 };
const lookups = {
    factories: [{ id: 1, code: 'F1', name: 'Main Plant', is_active: true }],
    departments: [{ id: 4, factory_id: 1, code: 'MACH', name: 'Machining', is_active: true }],
    warehouses: [{ id: 7, factory_id: 1, department_id: null, code: 'RMS', name: 'Raw Material Store', stock_purpose: 'raw_material', is_active: true }],
    stock_statuses: [{ value: 'available', label: 'Available' }],
    stock_purposes: [{ value: 'production', label: 'Production / WIP' }],
};
const locations = [{ id: 31, code: 'R1', name: 'Rack 1', warehouse_id: 7, warehouse_code: 'RMS', purpose: 'raw_material', stock_purpose: null, is_active: true }];

function baseGet(url: string) {
    if (url === '/inventory/lookups/structure') return Promise.resolve({ data: { data: lookups } });
    if (url === '/inventory/lookups/locations') return Promise.resolve({ data: { data: locations } });
    if (url === '/inventory/lookups/suppliers') return Promise.resolve({ data: { data: [{ id: 5, code: 'SUP-1', name: 'Steel Traders' }] } });
    if (url === '/uoms') return Promise.resolve({ data: { data: [{ ...kg, dimension: 'mass', dimension_label: 'Mass', universally_convertible: true, is_system: true, is_active: true }] } });
    if (url === '/items') return Promise.resolve({ data: { data: [{ id: 11, code: 'RM-SS316', name: 'SS316 Bar', item_type: 'raw_material', is_active: true, base_uom: kg }], meta: meta(1) } });
    if (url === '/inventory/stock/availability') return Promise.resolve({ data: { data: { on_hand: '40', reserved: '0', available: '40', locked_by_count: null } } });
    return Promise.resolve({ data: { data: [] } });
}

beforeEach(() => {
    get.mockReset();
    post.mockReset();
    put.mockReset();
    get.mockImplementation(baseGet);
});

const doc = (overrides: Record<string, unknown> = {}) => ({
    id: 3, document_type: 'grn', document_number: 'GRN-000003', document_date: '2026-10-20', status: 'submitted', requires_handover: true, decision: null,
    supplier: { id: 5, code: 'SUP-1', name: 'Steel Traders' }, warehouse: { id: 7, code: 'RMS', name: 'Raw Material Store' },
    supplier_invoice_number: 'CH-1', reference_number: null, remarks: null, submitted_at: null, approved_at: null, issued_at: null, received_at: null, posted_at: null, closed_at: null, created_at: null,
    lines: [{
        id: 91, line_no: 1, item: { id: 11, code: 'RM-SS316', name: 'SS316 Bar' }, quantity: '25', uom: kg, conversion_factor: '1', base_quantity: '25', base_uom: kg,
        source_location: null, destination_location: { ...locations[0] }, from_status: null, to_status: 'qc_pending', unit_cost: null, system_base_quantity: null, new_base_quantity: null,
        received_base_quantity: '0', outstanding_base_quantity: '25', condition: null, remarks: null,
    }],
    events: [{ id: 1, action: 'created', from_status: null, to_status: 'draft', transaction_number: null, remarks: null, user: { id: 1, full_name: 'Store Keeper' }, created_at: '2026-10-20T10:00:00Z' }],
    attachments: [],
    allowed_actions: ['post', 'cancel'],
    ...overrides,
});

describe('inventory navigation', () => {
    it('shows only inventory screens the user may access', () => {
        renderWithAuth(<Sidebar open={false} onClose={() => undefined} />, { user: makeUser(['inventory.stock.view', 'inventory.grn.view', 'inventory.counts.view']) });
        const nav = screen.getAllByRole('navigation', { name: 'Main navigation' })[0];
        const links = within(nav).getAllByRole('link').map((l) => l.textContent);
        expect(links).toEqual(['Stock Overview', 'Goods Receipts', 'Stock Counting']);
    });
});

describe('inventory dashboard', () => {
    it('never adds quantities of different units and flags unvalued stock', async () => {
        get.mockImplementation((url: string) => url === '/inventory/dashboard' ? Promise.resolve({ data: { data: {
            active_items: 12, items_with_available_stock: 5,
            valuation: { known_value: '46', unvalued_balances: 1 },
            stock_by_uom: [{ uom: 'KG', quantity: '500', skus: 2, value: '40', unvalued_balances: 1 }, { uom: 'PCS', quantity: '1000', skus: 1, value: '6', unvalued_balances: 0 }],
            groups: [{ key: 'raw_material', label: 'Raw Material', skus: 2, quantities: [{ uom: 'KG', quantity: '500', skus: 2, value: '40', unvalued_balances: 1 }, { uom: 'PCS', quantity: '1000', skus: 1, value: '6', unvalued_balances: 0 }], known_value: '46', unvalued_balances: 1 }],
            rejected: [], scrap: [], qc_pending: [], low_stock: { count: 0, items: [] }, recent_movements: [],
            pending: { goods_receipts: 2, transfers: 1, material_issues: 0, adjustments_awaiting_approval: 3 },
        } } }) : baseGet(url));
        renderWithAuth(<InventoryDashboardPage />, { user: makeUser(['inventory.dashboard.view']) });

        const group = (await screen.findByText('Raw Material')).closest('div') as HTMLElement;
        expect(within(group).getByText('500')).toBeInTheDocument();
        expect(within(group).getByText('1,000')).toBeInTheDocument();
        expect(screen.queryByText('1,500')).not.toBeInTheDocument();
        expect(screen.getByText(/1 balance\(s\) have no recorded cost and are not valued/)).toBeInTheDocument();
        expect(screen.getByText('Adjustments awaiting approval').previousSibling).toHaveTextContent('3');
    });
});

describe('inventory dashboard location filter', () => {
    it('is enabled and lists locations without choosing a warehouse first', async () => {
        const empty = {
            active_items: 0, items_with_available_stock: 0, valuation: { known_value: '0', unvalued_balances: 0 }, stock_by_uom: [], groups: [],
            rejected: [], scrap: [], qc_pending: [], low_stock: { count: 0, items: [] }, recent_movements: [],
            pending: { goods_receipts: 0, transfers: 0, material_issues: 0, adjustments_awaiting_approval: 0 },
        };
        get.mockImplementation((url: string) => url === '/inventory/dashboard' ? Promise.resolve({ data: { data: empty } }) : baseGet(url));
        renderWithAuth(<InventoryDashboardPage />, { user: makeUser(['inventory.dashboard.view']) });
        const select = screen.getByLabelText('Location');
        expect(select).toBeEnabled();
        expect(await within(select).findByRole('option', { name: 'RMS / R1' })).toBeInTheDocument();

        await userEvent.selectOptions(select, '31');
        await waitFor(() => expect(get).toHaveBeenCalledWith('/inventory/dashboard', expect.objectContaining({ params: expect.objectContaining({ location_id: '31' }) })));
    });
});

describe('goods receipt editor', () => {
    it('submits header and lines with base UOM default', async () => {
        post.mockResolvedValue({ data: { data: { id: 3, document_number: 'GRN-000003' } } });
        renderWithAuth(
            <Routes><Route path="/new" element={<InventoryDocumentEditorPage config={DOCS.grn} />} /><Route path="/inventory/goods-receipts/:id" element={<p>Details</p>} /></Routes>,
            { route: '/new', user: makeUser(['inventory.grn.view', 'inventory.grn.create', 'items.view']) },
        );

        await userEvent.selectOptions(await screen.findByLabelText('Receiving warehouse'), '7');
        await userEvent.click(screen.getByLabelText('Supplier'));
        await userEvent.click(await screen.findByRole('option', { name: /Steel Traders/ }));
        await userEvent.type(screen.getByLabelText('Supplier invoice / challan no.'), 'CH-77');
        await userEvent.click(screen.getByLabelText('Line 1 item'));
        await userEvent.click(await screen.findByRole('option', { name: /RM-SS316/ }));
        await userEvent.type(screen.getByLabelText('Line 1 quantity'), '12.5');
        await waitFor(() => expect(within(screen.getByLabelText('Line 1 destination')).getAllByRole('option').length).toBeGreaterThan(1));
        await userEvent.selectOptions(screen.getByLabelText('Line 1 destination'), '31');
        await userEvent.click(screen.getByRole('button', { name: 'Save draft' }));

        await waitFor(() => expect(post).toHaveBeenCalled());
        expect(post).toHaveBeenCalledWith('/inventory/grns', expect.objectContaining({
            warehouse_id: '7', supplier_id: 5, supplier_invoice_number: 'CH-77',
            lines: [expect.objectContaining({ item_id: 11, quantity: '12.5', uom_id: '2', destination_location_id: '31' })],
        }));
        expect(await screen.findByText('Details')).toBeInTheDocument();
    });

    it('rejects letters in quantity entry', async () => {
        renderWithAuth(<InventoryDocumentEditorPage config={DOCS.grn} />, { user: makeUser(['inventory.grn.create']) });
        const qty = await screen.findByLabelText('Line 1 quantity');
        await userEvent.type(qty, '1a2.5x');
        expect(qty).toHaveValue('12.5');
    });
});

describe('document details', () => {
    it('shows only the actions the backend allows and posts on confirm', async () => {
        get.mockImplementation((url: string) => url === '/inventory/grns/3' ? Promise.resolve({ data: { data: doc() } }) : baseGet(url));
        post.mockResolvedValue({ data: { data: doc({ status: 'posted' }) } });
        renderWithAuth(<Routes><Route path="/g/:id" element={<InventoryDocumentDetailsPage config={DOCS.grn} />} /></Routes>, { route: '/g/3', user: makeUser(['inventory.grn.view', 'inventory.grn.post']) });

        expect(await screen.findByText('GRN GRN-000003')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Submit' })).not.toBeInTheDocument();
        expect(screen.getByText('QC Pending')).toBeInTheDocument();
        await userEvent.click(screen.getByRole('button', { name: 'Post to stock' }));
        await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Confirm' }));
        expect(post).toHaveBeenCalledWith('/inventory/grns/3/post', { remarks: null });
    });

    it('sends partial transfer receipts with a request key', async () => {
        const transfer = doc({ document_type: 'transfer', document_number: 'TRF-000001', status: 'in_transit', allowed_actions: ['receive'], supplier: null });
        get.mockImplementation((url: string) => url === '/inventory/transfers/3' ? Promise.resolve({ data: { data: transfer } }) : baseGet(url));
        post.mockResolvedValue({ data: { data: transfer } });
        renderWithAuth(<Routes><Route path="/t/:id" element={<InventoryDocumentDetailsPage config={DOCS.transfer} />} /></Routes>, { route: '/t/3', user: makeUser(['inventory.transfers.view', 'inventory.transfers.receive']) });

        await userEvent.click(await screen.findByRole('button', { name: 'Receive' }));
        await userEvent.type(screen.getByLabelText('Received RM-SS316'), '15');
        await userEvent.click(screen.getByRole('button', { name: 'Confirm' }));

        expect(post).toHaveBeenCalledWith('/inventory/transfers/3/receive', expect.objectContaining({
            receipts: [{ line_id: 91, base_quantity: '15' }], idempotency_key: expect.stringMatching(/^[A-Za-z0-9_-]{8,}$/),
        }));
    });

    it('shows backend refusals such as self-approval', async () => {
        const adjustment = doc({ document_type: 'adjustment', document_number: 'ADJ-000001', allowed_actions: ['approve'], supplier: null });
        get.mockImplementation((url: string) => url === '/inventory/adjustments/3' ? Promise.resolve({ data: { data: adjustment } }) : baseGet(url));
        post.mockRejectedValue({ isAxiosError: true, response: { status: 403, data: { message: 'You cannot approve a stock adjustment you requested.' } } });
        renderWithAuth(<Routes><Route path="/a/:id" element={<InventoryDocumentDetailsPage config={DOCS.adjustment} />} /></Routes>, { route: '/a/3', user: makeUser(['inventory.adjustments.view', 'inventory.adjustments.approve']) });

        await userEvent.click(await screen.findByRole('button', { name: 'Approve' }));
        await userEvent.click(screen.getByRole('button', { name: 'Confirm' }));
        expect(await screen.findByText(/Something went wrong|cannot approve/)).toBeInTheDocument();
    });
});

describe('stock counting', () => {
    it('records counts with large touch inputs and shows the variance', async () => {
        get.mockImplementation((url: string) => url === '/inventory/stock-counts/8' ? Promise.resolve({ data: { data: {
            id: 8, count_number: 'CNT-000008', warehouse: { id: 7, code: 'RMS', name: 'Raw Material Store' }, location_ids: [31], status: 'counting', lock_locations: true,
            cutoff_at: '2026-10-20T08:00:00Z', remarks: null, line_count: 1, created_by: null, approved_by: null, posted_at: null, created_at: '2026-10-20T08:00:00Z',
            allowed_actions: ['record', 'submit', 'cancel'],
            lines: [{ id: 51, item: { id: 11, code: 'RM-SS316', name: 'SS316 Bar' }, location: locations[0], stock_status: 'available', uom: kg, system_quantity: '20', counted_quantity: null, variance: null, remarks: null, counted_by: null }],
        } } }) : baseGet(url));
        put.mockResolvedValue({ data: { data: {} } });
        renderWithAuth(<Routes><Route path="/c/:id" element={<StockCountDetailsPage />} /></Routes>, { route: '/c/8', user: makeUser(['inventory.counts.view', 'inventory.counts.create', 'inventory.counts.submit']) });

        const input = await screen.findByLabelText('Counted RM-SS316 at R1');
        expect(input.className).toContain('h-12');
        await userEvent.type(input, '18');
        expect(screen.getByText('-2')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Submit count' })).toBeDisabled(); // unsaved counts
        await userEvent.click(screen.getByRole('button', { name: 'Save counts' }));
        expect(put).toHaveBeenCalledWith('/inventory/stock-counts/8/counts', { entries: [{ line_id: 51, counted_quantity: '18' }] });
    });
});

describe('opening stock wizard', () => {
    it('shows row errors and validates with the chosen mapping', async () => {
        get.mockImplementation((url: string) => url === '/inventory/opening-stock/imports/4' ? Promise.resolve({ data: { data: {
            id: 4, import_number: 'OPN-00004', original_name: 'stock.xlsx', status: 'draft', headers: ['Code', 'Store', 'Bin', 'Qty'],
            mapping: { item_code: 'Code', warehouse_code: 'Store', location_code: 'Bin', quantity: 'Qty' }, total_rows: 2, accepted_rows: 1, rejected_rows: 1,
            transaction_id: null, failure_message: null, reversal_reason: null, created_by: null, approved_by: null, posted_by: null, posted_at: null, created_at: '2026-10-20T08:00:00Z',
            allowed_actions: ['validate', 'submit'],
            columns: [{ key: 'item_code', label: 'Item Code', required: true }, { key: 'quantity', label: 'Quantity', required: true }],
            rows: [{ id: 1, row_number: 3, status: 'rejected', errors: ['Item RM-NOPE does not exist.'], raw: { item_code: 'RM-NOPE', quantity: '5' }, item: null, location: null, stock_status: null, quantity: '5', uom: null, base_quantity: null, unit_cost: null }],
            rows_meta: meta(1),
        } } }) : baseGet(url));
        post.mockResolvedValue({ data: { data: {} } });
        renderWithAuth(<Routes><Route path="/o/:id" element={<OpeningStockDetailsPage />} /></Routes>, { route: '/o/4', user: makeUser(['inventory.opening-stock.view', 'inventory.opening-stock.import']) });

        expect(await screen.findByText('Item RM-NOPE does not exist.')).toBeInTheDocument();
        await userEvent.click(screen.getByRole('button', { name: 'Validate rows' }));
        expect(post).toHaveBeenCalledWith('/inventory/opening-stock/imports/4/validate', { mapping: { item_code: 'Code', warehouse_code: 'Store', location_code: 'Bin', quantity: 'Qty' } });
        expect(screen.getByRole('button', { name: 'Submit for approval' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Post to stock ledger' })).not.toBeInTheDocument();
    });
});

describe('stock overview', () => {
    it('lists balances with unit and hides export without permission', async () => {
        get.mockImplementation((url: string) => url === '/inventory/stock' ? Promise.resolve({ data: { data: [{
            id: 1, item: { id: 11, code: 'RM-SS316', name: 'SS316 Bar', item_type: 'raw_material', category: null }, warehouse: { id: 7, code: 'RMS', name: 'Raw Material Store' },
            department: null, location: locations[0], stock_status: 'available', quantity: '1250.5', uom: kg, value: null, last_movement_at: null,
            warehouse_reserved: '0', warehouse_available_to_promise: '1250.5',
        }], meta: meta(1) } }) : baseGet(url));
        renderWithAuth(<StockListPage view={STOCK_VIEWS.stock} />, { user: makeUser(['inventory.stock.view']) });

        expect(await screen.findByText('RM-SS316')).toBeInTheDocument();
        expect(screen.getAllByText('1,250.5').length).toBeGreaterThan(0);
        expect(screen.getByText('Not valued')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Export' })).not.toBeInTheDocument();
    });
});
