import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Sidebar } from '@/layout/Sidebar';
import { makeUser, renderWithAuth } from '@/test/utils';
import StockOverviewPage from './StockOverviewPage';
import SkuMasterPage from './SkuMasterPage';
import { VendorMappingPage } from './VendorMasterPages';
import { DOC_CONFIGS, StockDocumentDetailsPage, StockDocumentFormPage } from './StockDocumentPages';

const get = vi.fn();
const post = vi.fn();
const put = vi.fn();

vi.mock('@/lib/api', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@/lib/api')>();
    return { ...actual, api: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a), put: (...a: unknown[]) => put(...a), patch: vi.fn(), delete: vi.fn() } };
});

const meta = (n: number) => ({ current_page: 1, last_page: 1, per_page: 25, total: n, from: n ? 1 : null, to: n || null });
const sku = { id: 11, sku: 'ET-WTS008-316', product_name: 'SS 316 Tactile Stud Self Adhesive', category: 'Stud', material: '316', size: '35MM', unit: 'PCS', reorder_level: null, is_active: true };
const vendor = { id: 5, code: 'VEN-0002', name: 'Birju Buff', contact_person: null, phone: null, email: null, city: 'Rajkot', address: null, notes: null, is_active: true };
const lookups = {
    factories: [{ id: 1, code: 'EEPL', name: 'Eminent Plant', is_active: true }],
    locations: [{ id: 31, code: 'RACK-A', name: 'Rack A', factory_id: 1, parent_id: null, is_active: true }],
    departments: [{ id: 4, code: 'MACH', name: 'Machining', factory_id: 1, is_active: true }],
    categories: ['Stud'], materials: ['304', '316'], units: ['PCS'],
};

function baseGet(url: string, config?: { params?: Record<string, unknown> }) {
    if (url === '/stock/lookups') return Promise.resolve({ data: { data: lookups } });
    if (url === '/skus/options') return Promise.resolve({ data: { data: [{ ...sku, mapped: config?.params?.vendor_id ? true : null }] } });
    if (url === '/vendors/options') return Promise.resolve({ data: { data: [{ ...vendor, mapped: config?.params?.sku_id ? true : null }] } });
    if (url === '/stock/availability') return Promise.resolve({ data: { data: { available: '25' } } });
    return Promise.resolve({ data: { data: [] } });
}

beforeEach(() => {
    get.mockReset();
    post.mockReset();
    put.mockReset();
    get.mockImplementation(baseGet);
});

describe('inventory navigation', () => {
    it('shows only the stock screens the user may open', () => {
        renderWithAuth(<Sidebar open={false} onClose={() => undefined} />, { user: makeUser(['stock-overview.view', 'inward.view', 'skus.view']) });
        const nav = screen.getAllByRole('navigation', { name: 'Main navigation' })[0];
        expect(within(nav).getAllByRole('link').map((l) => l.textContent)).toEqual(['Stock Overview', 'Inward Material', 'SKU Master']);
    });
});

describe('stock overview', () => {
    it('shows the live stock sheet columns, statuses and hides export without permission', async () => {
        get.mockImplementation((url: string, c) => url === '/stock/overview' ? Promise.resolve({ data: { data: [
            { id: 11, sku: 'ET-WTS008-316', product_name: 'SS 316 Stud', category: 'Stud', material: '316', size: null, unit: 'PCS', is_active: true, opening_stock: '54508', total_inward: '0', total_rejected: '0', accepted_inward: '0', total_outward: '16101', live_balance: '38407', reorder_level: '100', status: 'ok', status_label: 'OK' },
            { id: 12, sku: 'ET-WTS008-304', product_name: 'SS 304 Stud', category: 'Stud', material: '304', size: null, unit: null, is_active: true, opening_stock: '0', total_inward: '0', total_rejected: '0', accepted_inward: '0', total_outward: '0', live_balance: '0', reorder_level: null, status: 'low', status_label: 'LOW STOCK' },
        ], meta: meta(2) } }) : baseGet(url, c));
        renderWithAuth(<StockOverviewPage />, { user: makeUser(['stock-overview.view']) });

        expect(await screen.findByText('38,407')).toBeInTheDocument();
        for (const h of ['Opening Stock', 'Total Inward', 'Total Outward', 'Total Rejected', 'Live Stock Balance', 'Reorder Level']) expect(screen.getByText(h)).toBeInTheDocument();
        const rows = screen.getAllByRole('row');
        expect(within(rows[1]).getByText('OK')).toBeInTheDocument();
        expect(within(rows[2]).getByText('LOW STOCK')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Export' })).not.toBeInTheDocument();
    });
});

describe('inward form', () => {
    it('fills product details from the SKU, calculates accepted quantity and saves', async () => {
        post.mockResolvedValue({ data: { data: { id: 7, number: 'INW-000007' } } });
        renderWithAuth(
            <Routes><Route path="/new" element={<StockDocumentFormPage config={DOC_CONFIGS.inward} />} /><Route path="/inventory/inward/:id" element={<p>Details page</p>} /></Routes>,
            { route: '/new', user: makeUser(['inward.view', 'inward.create']) },
        );

        // Single factory is selected automatically; choose the storage location.
        await waitFor(() => expect(within(screen.getByLabelText('Storage location')).getAllByRole('option').length).toBe(2));
        await userEvent.selectOptions(screen.getByLabelText('Storage location'), '31');
        await userEvent.click(screen.getByLabelText('Vendor'));
        await userEvent.click(await screen.findByRole('option', { name: /Birju Buff/ }));
        await userEvent.click(screen.getByLabelText('SKU'));
        await userEvent.click(await screen.findByRole('option', { name: /ET-WTS008-316/ }));
        expect(screen.getByText('Product name').nextElementSibling).toHaveTextContent('SS 316 Tactile Stud Self Adhesive'); // auto-populated
        await userEvent.type(screen.getByLabelText(/^Received quantity/), '50');
        await userEvent.type(screen.getByLabelText(/^Rejected quantity/), '5');
        expect(screen.getByText('45 PCS')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Save & post' })).not.toBeInTheDocument(); // no post permission

        await userEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        await waitFor(() => expect(post).toHaveBeenCalledWith('/inward', expect.objectContaining({
            factory_id: '1', location_id: '31', vendor_id: 5, sku_id: 11, received_qty: '50', rejected_qty: '5',
        })));
        expect(await screen.findByText('Details page')).toBeInTheDocument();
    });
});

describe('outward form', () => {
    it('shows available stock and blocks posting more than is available', async () => {
        renderWithAuth(<StockDocumentFormPage config={DOC_CONFIGS.outward} />, { user: makeUser(['outward.view', 'outward.create', 'outward.post']) });
        await waitFor(() => expect(within(screen.getByLabelText('Storage location')).getAllByRole('option').length).toBe(2));
        await userEvent.selectOptions(screen.getByLabelText('Storage location'), '31');
        await userEvent.click(screen.getByLabelText('SKU'));
        await userEvent.click(await screen.findByRole('option', { name: /ET-WTS008-316/ }));
        expect(await screen.findByText('Available now: 25 PCS')).toBeInTheDocument();

        await userEvent.type(screen.getByLabelText(/^Issue quantity/), '30');
        expect(screen.getByText('Only 25 available at this location.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Save & post' })).toBeDisabled();
    });
});

const draft = {
    id: 7, type: 'inward', number: 'INW-000007', document_date: '2026-10-22', status: 'draft', factory: { id: 1, code: 'EEPL', name: 'Eminent Plant' },
    location: { id: 31, code: 'RACK-A', name: 'Rack A' }, sku, vendor: { id: 5, code: 'VEN-0002', name: 'Birju Buff' }, department: null, quantity: '0',
    received_qty: '50', rejected_qty: '5', accepted_qty: '45', recipient: null, job_no: null, reference: 'INV-1', remarks: null, vendor_mapped: false,
    vendor_check_reason: null, created_by: { id: 1, full_name: 'Store Keeper' }, created_at: '2026-10-22T10:00:00Z', posted_by: null, posted_at: null,
    reversed_by: null, reversed_at: null, reversal_reason: null, cancelled_by: null, cancelled_at: null, cancel_reason: null,
    allowed_actions: ['edit', 'post', 'cancel'], can_override_vendor_check: true, history: [],
};

describe('entry details', () => {
    it('posts an unmapped inward only with a reason, after confirmation', async () => {
        get.mockImplementation((url: string, c) => url === '/inward/7' ? Promise.resolve({ data: { data: draft } }) : baseGet(url, c));
        post.mockResolvedValue({ data: { message: 'INW-000007 posted. Stock updated.' } });
        renderWithAuth(<Routes><Route path="/i/:id" element={<StockDocumentDetailsPage config={DOC_CONFIGS.inward} />} /></Routes>,
            { route: '/i/7', user: makeUser(['inward.view', 'inward.post', 'inward.override-vendor-check']) });

        expect(await screen.findByText('This is a draft. It does not affect stock until it is posted.')).toBeInTheDocument();
        await userEvent.click(screen.getByRole('button', { name: 'Post' }));
        const dialog = screen.getByRole('dialog');
        expect(dialog).toHaveTextContent('45 of ET-WTS008-316 will be added to stock at RACK-A (5 rejected, not added)');
        await userEvent.type(within(dialog).getByLabelText(/Reason for posting without mapping/), 'New supplier');
        await userEvent.click(within(dialog).getByRole('button', { name: 'Post' }));
        expect(post).toHaveBeenCalledWith('/inward/7/post', { vendor_check_reason: 'New supplier' });
    });

    it('requires a reason to reverse a posted entry', async () => {
        const posted = { ...draft, status: 'posted', vendor_mapped: true, allowed_actions: ['reverse'] };
        get.mockImplementation((url: string, c) => url === '/inward/7' ? Promise.resolve({ data: { data: posted } }) : baseGet(url, c));
        post.mockRejectedValueOnce({ isAxiosError: true, response: { status: 422, data: { message: 'x', errors: { reason: ['Give a reason for the reversal.'] } } } });
        renderWithAuth(<Routes><Route path="/i/:id" element={<StockDocumentDetailsPage config={DOC_CONFIGS.inward} />} /></Routes>,
            { route: '/i/7', user: makeUser(['inward.view', 'inward.reverse']) });

        await userEvent.click(await screen.findByRole('button', { name: 'Reverse' }));
        await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Reverse' }));
        expect(await screen.findByText('Give a reason for the reversal.')).toBeInTheDocument();
    });
});

describe('SKU master', () => {
    it('validates and creates a SKU', async () => {
        get.mockImplementation((url: string, c) => url === '/skus' ? Promise.resolve({ data: { data: [], meta: meta(0), filters: { categories: [], materials: [] } } }) : baseGet(url, c));
        post.mockResolvedValue({ data: { message: 'SKU ET-NEW created.' } });
        renderWithAuth(<SkuMasterPage />, { user: makeUser(['skus.view', 'skus.create']) });

        await userEvent.click(await screen.findByRole('button', { name: 'Add SKU' }));
        await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Create SKU' }));
        expect(screen.getByText('SKU is required.')).toBeInTheDocument();
        expect(post).not.toHaveBeenCalled();

        await userEvent.type(screen.getByLabelText(/^SKU/), 'ET-NEW');
        await userEvent.type(screen.getByLabelText(/^Product name/), 'New Stud');
        await userEvent.type(screen.getByLabelText(/^Material/), '316');
        await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Create SKU' }));
        expect(post).toHaveBeenCalledWith('/skus', expect.objectContaining({ sku: 'ET-NEW', product_name: 'New Stud', material: '316', reorder_level: null }));
    });
});

describe('vendor-SKU mapping', () => {
    it('maps several SKUs to one vendor', async () => {
        get.mockImplementation((url: string, c) => url === '/vendor-skus' ? Promise.resolve({ data: { data: [], meta: meta(0) } }) : baseGet(url, c));
        post.mockResolvedValue({ data: { message: '1 SKU(s) mapped to Birju Buff.' } });
        renderWithAuth(<VendorMappingPage />, { user: makeUser(['vendors.view', 'vendor-skus.view', 'vendor-skus.create']) });

        await userEvent.click(await screen.findByRole('button', { name: 'Map SKUs to vendor' }));
        const dialog = screen.getByRole('dialog');
        await userEvent.click(within(dialog).getByLabelText('Vendor'));
        await userEvent.click(await within(dialog).findByRole('option', { name: /Birju Buff/ }));
        await userEvent.click(within(dialog).getByLabelText('Add SKU'));
        await userEvent.click(await within(dialog).findByRole('option', { name: /ET-WTS008-316/ }));
        await userEvent.click(within(dialog).getByRole('button', { name: /Save 1 mapping/ }));
        expect(post).toHaveBeenCalledWith('/vendor-skus', { vendor_id: 5, sku_ids: [11] });
    });
});
