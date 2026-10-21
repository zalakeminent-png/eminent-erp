import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { Download, Package } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useApi, useDebouncedValue } from '@/hooks/useApi';
import { Card, CardBody, CardHeader, DescriptionList } from '@/components/ui/Card';
import { Alert, EmptyState, PageHeader } from '@/components/ui/Feedback';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Pagination, SortableTh, Table, Td, Th } from '@/components/ui/Table';
import { LoadingBlock } from '@/components/ui/Spinner';
import { useToast } from '@/components/ui/Toast';
import { errorMessage } from '@/lib/api';
import { formatDate, formatDateTime } from '@/lib/format';
import type { Paginated } from '@/types';
import { FilterBar, SearchInput, filterInputClass, useListParams } from '@/pages/structure/components/ListControls';
import { downloadFile } from '@/pages/master-data/components/shared';
import type { CodedRef, UomRef } from '@/pages/master-data/types';
import { DocumentStatusBadge, Money, MovementHistory, Qty, StockStatusBadge, statusLabel, useLookups } from './components';
import type { BalanceRow, LedgerRow, LocationRef } from './types';

export interface StockView {
    preset: 'stock' | 'wip' | 'finished-goods' | 'qc' | 'scrap';
    endpoint: string;
    title: string;
    description: string;
    statuses: string[];
    action?: { label: string; to: string; permission: string | string[] };
    related?: { label: string; to: string; permission: string };
}

export const STOCK_VIEWS: Record<StockView['preset'], StockView> = {
    stock: {
        preset: 'stock', endpoint: '/inventory/stock', title: 'Stock Overview',
        description: 'On-hand stock per item, location and status, from posted transactions. Quantities are shown in each item’s base unit.',
        statuses: ['available', 'qc_pending', 'qc_hold', 'rework', 'rejected', 'scrap', 'in_transit'],
    },
    wip: {
        preset: 'wip', endpoint: '/inventory/wip', title: 'WIP Inventory',
        description: 'Material physically held at production / WIP locations. Movements here are physical only — no production transformation.',
        statuses: ['available', 'rework', 'rejected', 'qc_hold', 'in_transit'],
        action: { label: 'Transfer between departments', to: '/inventory/transfers/new', permission: 'inventory.transfers.create' },
    },
    'finished-goods': {
        preset: 'finished-goods', endpoint: '/inventory/finished-goods', title: 'Finished Goods',
        description: 'Finished product stock with QC status, reservations and availability.',
        statuses: ['available', 'qc_pending', 'qc_hold', 'rework', 'rejected', 'scrap', 'in_transit'],
    },
    qc: {
        preset: 'qc', endpoint: '/inventory/qc-stock', title: 'QC Stock',
        description: 'Stock awaiting QC or on hold. It is not available for issue until released through a QC disposition.',
        statuses: ['qc_pending', 'qc_hold'],
        related: { label: 'Disposition history', to: '/inventory/qc-stock/dispositions', permission: 'inventory.qc-disposition.view' },
        action: { label: 'Record QC decision', to: '/inventory/qc-stock/dispositions/new', permission: ['inventory.qc-disposition.release', 'inventory.qc-disposition.hold', 'inventory.qc-disposition.reject'] },
    },
    scrap: {
        preset: 'scrap', endpoint: '/inventory/scrap-stock', title: 'Scrap, Rejected & Rework Stock',
        description: 'Isolated stock that can never be issued or dispatched as approved goods. Recovery needs an authorised document.',
        statuses: ['scrap', 'rejected', 'rework'],
        related: { label: 'Scrap documents', to: '/inventory/scrap', permission: 'inventory.scrap.view' },
        action: { label: 'New scrap / rejection', to: '/inventory/scrap/new', permission: 'inventory.scrap.create' },
    },
};

export function StockListPage({ view }: { view: StockView }) {
    const { can } = useAuth();
    const toast = useToast();
    const list = useListParams({ sort: 'item' });
    const [search, setSearch] = useState(list.get('search'));
    const debounced = useDebouncedValue(search);
    const lookups = useLookups();
    const params = {
        search: debounced, factory_id: list.get('factory_id'), status: list.get('status'), department_id: list.get('department_id'),
        sort: list.sort, direction: list.direction,
    };
    const { data, loading, error } = useApi<Paginated<BalanceRow>>(view.endpoint, { ...params, page: list.page, per_page: 25 });
    const [exporting, setExporting] = useState(false);
    const showReserved = view.preset === 'stock' || view.preset === 'finished-goods';

    const exportFile = async () => {
        setExporting(true);
        try {
            await downloadFile(view.endpoint, { ...params, export: 'xlsx' }, `${view.preset}.xlsx`);
        } catch (err) {
            toast.error(errorMessage(err, 'Export failed.'));
        } finally {
            setExporting(false);
        }
    };

    return (
        <>
            <PageHeader title={view.title} description={view.description} actions={
                <div className="flex flex-wrap gap-2">
                    {can('inventory.reports.export') && <Button variant="secondary" loading={exporting} icon={<Download className="h-4 w-4" />} onClick={exportFile}>Export</Button>}
                    {view.related && can(view.related.permission) && <ButtonLink variant="secondary" to={view.related.to}>{view.related.label}</ButtonLink>}
                    {view.action && can(view.action.permission) && <ButtonLink to={view.action.to}>{view.action.label}</ButtonLink>}
                </div>
            } />
            <Card>
                <FilterBar>
                    <SearchInput value={search} onChange={(v) => { setSearch(v); list.update({ search: v }); }} placeholder="Search item code, name or location" />
                    <select aria-label="Filter by factory" value={list.get('factory_id')} onChange={(e) => list.update({ factory_id: e.target.value })} className={filterInputClass}>
                        <option value="">All factories</option>
                        {lookups.data?.data.factories.map((f) => <option key={f.id} value={f.id}>{f.name}</option>)}
                    </select>
                    {view.preset === 'wip' && (
                        <select aria-label="Filter by department" value={list.get('department_id')} onChange={(e) => list.update({ department_id: e.target.value })} className={filterInputClass}>
                            <option value="">All departments</option>
                            {lookups.data?.data.departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                        </select>
                    )}
                    <select aria-label="Filter by stock status" value={list.get('status')} onChange={(e) => list.update({ status: e.target.value })} className={filterInputClass}>
                        <option value="">All statuses</option>
                        {view.statuses.map((s) => <option key={s} value={s}>{statusLabel(s)}</option>)}
                    </select>
                </FilterBar>
                {error ? <div className="p-4"><Alert tone="danger">{error}</Alert></div> : loading && !data ? <LoadingBlock /> : data && data.data.length === 0 ? (
                    <EmptyState icon={<Package className="h-6 w-6" />} title={list.hasFilters ? 'No stock matches your filters' : 'No stock recorded here yet'}
                        description="Stock appears after opening stock, receipts, transfers or adjustments are posted." />
                ) : data ? (
                    <div className={loading ? 'opacity-60' : undefined}>
                        <Table>
                            <thead>
                                <tr>
                                    <SortableTh label="Item" field="item" sort={list.sort} direction={list.direction} onSort={list.onSort} />
                                    <SortableTh label="Location" field="location" sort={list.sort} direction={list.direction} onSort={list.onSort} className="hidden sm:table-cell" />
                                    {view.preset === 'wip' && <Th className="hidden md:table-cell">Department</Th>}
                                    <Th>Status</Th>
                                    <SortableTh label="Quantity" field="quantity" sort={list.sort} direction={list.direction} onSort={list.onSort} className="text-right" />
                                    {showReserved && <Th className="hidden text-right lg:table-cell">Reserved / Available to promise</Th>}
                                    <Th className="hidden text-right lg:table-cell">Value</Th>
                                    <SortableTh label="Last movement" field="last_movement_at" sort={list.sort} direction={list.direction} onSort={list.onSort} className="hidden xl:table-cell" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 bg-white">
                                {data.data.map((b) => (
                                    <tr key={b.id} className="hover:bg-slate-50">
                                        <Td>
                                            {can('inventory.stock.view') ? (
                                                <Link to={`/inventory/stock/${b.item.id}`} className="font-mono text-xs font-semibold text-slate-900 hover:text-brand-700">{b.item.code}</Link>
                                            ) : <span className="font-mono text-xs font-semibold">{b.item.code}</span>}
                                            <span className="block text-xs text-slate-500">{b.item.name}</span>
                                            <span className="block text-xs text-slate-500 sm:hidden">{b.location?.code}</span>
                                        </Td>
                                        <Td className="hidden text-xs sm:table-cell">{b.location?.code}<span className="block text-slate-500">{b.location?.name}</span></Td>
                                        {view.preset === 'wip' && <Td className="hidden text-xs md:table-cell">{b.department?.name ?? '—'}</Td>}
                                        <Td><StockStatusBadge status={b.stock_status} /></Td>
                                        <Td className="text-right font-medium"><Qty value={b.quantity} uom={b.uom?.code} /></Td>
                                        {showReserved && (
                                            <Td className="hidden text-right text-xs lg:table-cell">
                                                {b.stock_status === 'available' ? <><Qty value={b.warehouse_reserved} /> / <Qty value={b.warehouse_available_to_promise} /></> : <span className="text-slate-400">—</span>}
                                            </Td>
                                        )}
                                        <Td className="hidden text-right text-xs lg:table-cell"><Money value={b.value} /></Td>
                                        <Td className="hidden whitespace-nowrap text-xs xl:table-cell">{formatDateTime(b.last_movement_at)}</Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                        <Pagination meta={data.meta} onPage={(p) => list.update({ page: String(p) })} />
                    </div>
                ) : null}
            </Card>
        </>
    );
}

interface Summary {
    physical: string;
    by_status: Record<string, string>;
    reserved: string;
    available_to_promise: string;
}

interface StockCard {
    item: CodedRef & { item_type: string | null; category: CodedRef | null; requires_qc: boolean; reorder_level: string | null; min_stock_level: string | null };
    uom: UomRef | null;
    total: Summary | null;
    warehouses: (Summary & { warehouse: CodedRef; factory: CodedRef | null })[];
    balances: BalanceRow[];
    reservations: { id: number; reservation_number: string; warehouse: CodedRef | null; factory: CodedRef | null; location: LocationRef | null; open_base_quantity: string; reference_type: string; reference_number: string | null; status: string; expires_on: string | null }[];
    recent_movements: LedgerRow[];
}

export function ItemStockPage() {
    const { itemId } = useParams();
    const { can } = useAuth();
    const { data, loading, error } = useApi<{ data: StockCard }>(`/inventory/stock/${itemId}`);
    if (loading && !data) return <LoadingBlock />;
    if (error || !data) return <Alert tone="danger">{error ?? 'Not found.'}</Alert>;
    const card = data.data;
    const uom = card.uom?.code;

    return (
        <>
            <PageHeader title={`${card.item.code} — ${card.item.name}`} description="Stock card: balances, reservations and recent movements (all from posted transactions)."
                breadcrumb={<Link to="/inventory/stock" className="hover:underline">Stock Overview</Link>}
                actions={can('inventory.ledger.view') && <ButtonLink variant="secondary" to={`/inventory/ledger?item_id=${card.item.id}`}>Full ledger</ButtonLink>} />
            <div className="grid gap-4 lg:grid-cols-3">
                <Card>
                    <CardHeader title="Item" />
                    <CardBody>
                        <DescriptionList items={[
                            { label: 'Base unit', value: uom ?? '—' },
                            { label: 'Category', value: card.item.category?.name ?? '—' },
                            { label: 'Requires QC', value: card.item.requires_qc ? 'Yes' : 'No' },
                            { label: 'Reorder level', value: card.item.reorder_level ? <Qty value={card.item.reorder_level} uom={uom} /> : '—' },
                            ...(card.total ? [
                                { label: 'Physical on hand', value: <Qty value={card.total.physical} uom={uom} /> },
                                { label: 'Reserved', value: <Qty value={card.total.reserved} uom={uom} /> },
                                { label: 'Available to promise', value: <Qty value={card.total.available_to_promise} uom={uom} className="font-semibold" /> },
                            ] : []),
                        ]} />
                    </CardBody>
                </Card>
                <Card className="lg:col-span-2">
                    <CardHeader title="By factory" />
                    {card.warehouses.length === 0 ? <p className="px-4 pb-4 text-sm text-slate-500">No stock.</p> : (
                        <Table>
                            <thead><tr><Th>Factory</Th><Th className="text-right">Available</Th><Th className="hidden text-right sm:table-cell">QC</Th><Th className="hidden text-right md:table-cell">Rejected / Scrap</Th><Th className="text-right">Reserved</Th><Th className="text-right">ATP</Th></tr></thead>
                            <tbody className="divide-y divide-slate-100 bg-white">
                                {card.warehouses.map((w) => (
                                    <tr key={w.warehouse.id}>
                                        <Td className="text-xs">{w.factory?.name ?? '—'}</Td>
                                        <Td className="text-right"><Qty value={w.by_status.available} /></Td>
                                        <Td className="hidden text-right sm:table-cell"><Qty value={String(Number(w.by_status.qc_pending) + Number(w.by_status.qc_hold))} /></Td>
                                        <Td className="hidden text-right md:table-cell"><Qty value={w.by_status.rejected} /> / <Qty value={w.by_status.scrap} /></Td>
                                        <Td className="text-right"><Qty value={w.reserved} /></Td>
                                        <Td className="text-right font-semibold"><Qty value={w.available_to_promise} uom={uom} /></Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    )}
                </Card>
                <Card className="lg:col-span-2">
                    <CardHeader title="Balances by location" />
                    <Table>
                        <thead><tr><Th>Location</Th><Th>Status</Th><Th className="text-right">Quantity</Th><Th className="hidden text-right md:table-cell">Value</Th><Th className="hidden md:table-cell">Last movement</Th></tr></thead>
                        <tbody className="divide-y divide-slate-100 bg-white">
                            {card.balances.map((b) => (
                                <tr key={b.id}>
                                    <Td className="text-xs">{b.location?.code}<span className="block text-slate-500">{b.location?.name}</span></Td>
                                    <Td><StockStatusBadge status={b.stock_status} /></Td>
                                    <Td className="text-right"><Qty value={b.quantity} uom={b.uom?.code} /></Td>
                                    <Td className="hidden text-right text-xs md:table-cell"><Money value={b.value} /></Td>
                                    <Td className="hidden text-xs md:table-cell">{formatDateTime(b.last_movement_at)}</Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </Card>
                <Card>
                    <CardHeader title="Open reservations" />
                    {card.reservations.length === 0 ? <p className="px-4 pb-4 text-sm text-slate-500">None.</p> : (
                        <ul className="divide-y divide-slate-100">
                            {card.reservations.map((r) => (
                                <li key={r.id} className="px-4 py-2 text-sm">
                                    <Link to={`/inventory/reservations?search=${r.reservation_number}`} className="font-mono text-xs font-semibold text-brand-700">{r.reservation_number}</Link>
                                    <span className="ml-2"><Qty value={r.open_base_quantity} uom={uom} /></span> <DocumentStatusBadge status={r.status} />
                                    <span className="block text-xs text-slate-500">{r.location ? r.location.code : r.factory?.name} · {r.reference_type} {r.reference_number}{r.expires_on && ` · expires ${formatDate(r.expires_on)}`}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
                <Card className="lg:col-span-3">
                    <CardHeader title="Recent movements" />
                    <MovementHistory rows={card.recent_movements} />
                </Card>
            </div>
        </>
    );
}
