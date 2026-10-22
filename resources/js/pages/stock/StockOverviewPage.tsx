import { useState } from 'react';
import { Link } from 'react-router-dom';
import { History, PackageSearch } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useApi, useDebouncedValue } from '@/hooks/useApi';
import { Card } from '@/components/ui/Card';
import { Alert, Badge, EmptyState, PageHeader } from '@/components/ui/Feedback';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { Pagination, SortableTh, Table, Td, Th } from '@/components/ui/Table';
import { LoadingBlock } from '@/components/ui/Spinner';
import { cx, formatDateTime } from '@/lib/format';
import type { PaginationMeta } from '@/types';
import { FilterBar, SearchInput, filterInputClass, useListParams } from '@/pages/structure/components/ListControls';
import { ExportButton, StockStatusBadge, formatQty, useStockLookups } from './components';
import type { StockRow } from './types';

export default function StockOverviewPage() {
    const { can } = useAuth();
    const list = useListParams({ sort: 'sku' });
    const [search, setSearch] = useState(list.get('search'));
    const debounced = useDebouncedValue(search);
    const lookups = useStockLookups();
    const params = {
        search: debounced, factory_id: list.get('factory_id'), location_id: list.get('location_id'), category: list.get('category'),
        material: list.get('material'), status: list.get('status'), include_inactive: list.get('include_inactive'), sort: list.sort, direction: list.direction,
    };
    const { data, loading, error } = useApi<{ data: StockRow[]; meta: PaginationMeta }>('/stock/overview', { ...params, page: list.page, per_page: 50 });
    const [history, setHistory] = useState<StockRow | null>(null);
    const l = lookups.data?.data;
    const locations = (l?.locations ?? []).filter((x) => !list.get('factory_id') || String(x.factory_id) === list.get('factory_id'));
    const sortTh = (label: string, field: string, className?: string) => (
        <SortableTh label={label} field={field} sort={list.sort} direction={list.direction} onSort={list.onSort} className={className} />
    );

    return (
        <>
            <PageHeader title="Stock Overview"
                description="Live Stock Balance = Opening Stock + Total Inward − Total Rejected − Total Outward. Calculated from posted entries only; it cannot be edited directly."
                actions={
                    <div className="flex flex-wrap gap-2">
                        {can('stock-overview.export') && <ExportButton url="/stock/overview" params={params} name="live-stock" />}
                        {can('opening-stock.view') && <ButtonLink to="/inventory/opening-stock" variant="secondary">Opening stock</ButtonLink>}
                    </div>
                } />
            <Card>
                <FilterBar>
                    <SearchInput value={search} onChange={(v) => { setSearch(v); list.update({ search: v }); }} placeholder="Search SKU or product name" />
                    {(l?.factories.length ?? 0) > 1 && (
                        <select aria-label="Factory" value={list.get('factory_id')} onChange={(e) => list.update({ factory_id: e.target.value, location_id: '' })} className={filterInputClass}>
                            <option value="">All factories</option>
                            {l?.factories.map((f) => <option key={f.id} value={f.id}>{f.name}</option>)}
                        </select>
                    )}
                    <select aria-label="Storage location" value={list.get('location_id')} onChange={(e) => list.update({ location_id: e.target.value })} className={filterInputClass}>
                        <option value="">All locations</option>
                        {locations.map((x) => <option key={x.id} value={x.id}>{x.code} — {x.name}</option>)}
                    </select>
                    <select aria-label="Category" value={list.get('category')} onChange={(e) => list.update({ category: e.target.value })} className={filterInputClass}>
                        <option value="">All categories</option>
                        {l?.categories.map((c) => <option key={c} value={c}>{c}</option>)}
                    </select>
                    <select aria-label="Material" value={list.get('material')} onChange={(e) => list.update({ material: e.target.value })} className={filterInputClass}>
                        <option value="">All materials</option>
                        {l?.materials.map((m) => <option key={m} value={m}>{m}</option>)}
                    </select>
                    <select aria-label="Stock status" value={list.get('status')} onChange={(e) => list.update({ status: e.target.value })} className={filterInputClass}>
                        <option value="">All statuses</option>
                        <option value="ok">OK</option>
                        <option value="low">LOW STOCK</option>
                        <option value="not_set">Reorder level not set</option>
                    </select>
                    <label className="flex items-center gap-2 text-sm text-slate-600">
                        <input type="checkbox" checked={list.get('include_inactive') === '1'} onChange={(e) => list.update({ include_inactive: e.target.checked ? '1' : '' })} /> Include inactive SKUs
                    </label>
                </FilterBar>
                {error ? <div className="p-4"><Alert tone="danger">{error}</Alert></div> : loading && !data ? <LoadingBlock /> : data && data.data.length === 0 ? (
                    <EmptyState icon={<PackageSearch className="h-6 w-6" />} title={list.hasFilters ? 'No SKUs match your filters' : 'No SKUs yet'}
                        description={list.hasFilters ? undefined : 'Add products in SKU Master; they appear here with zero stock until entries are posted.'} />
                ) : data ? (
                    <div className={loading ? 'opacity-60' : undefined}>
                        <Table>
                            <thead>
                                <tr>
                                    {sortTh('SKU', 'sku')}
                                    {sortTh('Product Name', 'product_name')}
                                    {sortTh('Opening Stock', 'opening_stock', 'hidden text-right md:table-cell')}
                                    {sortTh('Total Inward', 'total_inward', 'hidden text-right lg:table-cell')}
                                    {sortTh('Total Outward', 'total_outward', 'hidden text-right lg:table-cell')}
                                    {sortTh('Total Rejected', 'total_rejected', 'hidden text-right xl:table-cell')}
                                    {sortTh('Live Stock Balance', 'live_balance', 'text-right')}
                                    {sortTh('Reorder Level', 'reorder_level', 'hidden text-right sm:table-cell')}
                                    <Th>Status</Th>
                                    <Th className="text-right"><span className="sr-only">History</span></Th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 bg-white">
                                {data.data.map((r) => (
                                    <tr key={r.id} className={cx('hover:bg-slate-50', !r.is_active && 'text-slate-400')}>
                                        <Td className="whitespace-nowrap font-mono text-xs font-semibold text-brand-700">{r.sku}{!r.is_active && <Badge className="ml-1">Inactive</Badge>}</Td>
                                        <Td className="min-w-[14rem]">{r.product_name}<span className="block text-xs text-slate-500">{[r.category, r.material, r.size].filter(Boolean).join(' · ')}</span></Td>
                                        <Td className="hidden text-right tabular-nums md:table-cell">{formatQty(r.opening_stock)}</Td>
                                        <Td className="hidden text-right tabular-nums lg:table-cell">{formatQty(r.total_inward)}</Td>
                                        <Td className="hidden text-right tabular-nums lg:table-cell">{formatQty(r.total_outward)}</Td>
                                        <Td className="hidden text-right tabular-nums text-red-600 xl:table-cell">{formatQty(r.total_rejected)}</Td>
                                        <Td className="text-right font-semibold tabular-nums text-slate-900">{formatQty(r.live_balance)}{r.unit ? <span className="ml-1 text-xs font-normal text-slate-500">{r.unit}</span> : null}</Td>
                                        <Td className="hidden text-right tabular-nums sm:table-cell">{r.reorder_level ? formatQty(r.reorder_level) : <span className="text-xs text-slate-400">—</span>}</Td>
                                        <Td><StockStatusBadge status={r.status} label={r.status_label} /></Td>
                                        <Td className="text-right"><Button variant="ghost" size="sm" aria-label={`History of ${r.sku}`} icon={<History className="h-4 w-4" />} onClick={() => setHistory(r)} /></Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                        <Pagination meta={data.meta} onPage={(p) => list.update({ page: String(p) })} />
                    </div>
                ) : null}
            </Card>
            {history && <HistoryDialog row={history} filters={{ factory_id: list.get('factory_id'), location_id: list.get('location_id') }} onClose={() => setHistory(null)} />}
        </>
    );
}

interface HistoryEntry {
    id: number;
    posted_at: string;
    document: { id: number; type: 'inward' | 'outward' | 'opening'; number: string; date: string; status: string };
    is_reversal: boolean;
    location: { code: string; name: string };
    qty_in: string;
    qty_rejected: string;
    qty_out: string;
    balance_after: string;
    party: string | null;
    reference: string | null;
    user: string | null;
}

const DOC_ROUTES = { inward: '/inventory/inward', outward: '/inventory/outward', opening: '/inventory/opening-stock' };

function HistoryDialog({ row, filters, onClose }: { row: StockRow; filters: Record<string, string>; onClose: () => void }) {
    const { can } = useAuth();
    const [page, setPage] = useState(1);
    const { data, loading, error } = useApi<{ data: HistoryEntry[]; meta: PaginationMeta }>(`/stock/history/${row.id}`, { ...filters, page, per_page: 15 });

    return (
        <Modal open size="xl" title={`${row.sku} — stock history`} onClose={onClose} footer={
            <>
                {can('opening-stock.create') && <ButtonLink to={`/inventory/opening-stock/new?sku_id=${row.id}`} variant="secondary">Set opening stock</ButtonLink>}
                {can('inward.create') && <ButtonLink to={`/inventory/inward/new?sku_id=${row.id}`} variant="secondary">Record inward</ButtonLink>}
                {can('outward.create') && <ButtonLink to={`/inventory/outward/new?sku_id=${row.id}`} variant="secondary">Record outward</ButtonLink>}
                <Button variant="secondary" onClick={onClose}>Close</Button>
            </>
        }>
            <p className="mb-3">{row.product_name} · Live balance <strong>{formatQty(row.live_balance)}</strong>{row.unit ? ` ${row.unit}` : ''}</p>
            {error ? <Alert tone="danger">{error}</Alert> : loading && !data ? <LoadingBlock /> : data && data.data.length === 0 ? (
                <p className="text-slate-500">No posted movements yet.</p>
            ) : data ? (
                <div className="overflow-x-auto">
                    <Table>
                        <thead><tr><Th>Date</Th><Th>Entry</Th><Th className="hidden sm:table-cell">Location</Th><Th className="text-right">In</Th><Th className="text-right">Rejected</Th><Th className="text-right">Out</Th><Th className="text-right">Balance</Th><Th className="hidden md:table-cell">Party / ref.</Th></tr></thead>
                        <tbody className="divide-y divide-slate-100 bg-white">
                            {data.data.map((e) => (
                                <tr key={e.id}>
                                    <Td className="whitespace-nowrap text-xs">{formatDateTime(e.posted_at)}</Td>
                                    <Td className="text-xs">
                                        <Link to={`${DOC_ROUTES[e.document.type]}/${e.document.id}`} className="font-mono font-semibold text-brand-700 hover:underline" onClick={onClose}>{e.document.number}</Link>
                                        {e.is_reversal && <Badge tone="danger" className="ml-1">Reversal</Badge>}
                                    </Td>
                                    <Td className="hidden text-xs sm:table-cell">{e.location.code}</Td>
                                    <Td className="text-right tabular-nums text-emerald-700">{e.qty_in !== '0' ? formatQty(e.qty_in) : ''}</Td>
                                    <Td className="text-right tabular-nums text-red-600">{e.qty_rejected !== '0' ? formatQty(e.qty_rejected) : ''}</Td>
                                    <Td className="text-right tabular-nums">{e.qty_out !== '0' ? formatQty(e.qty_out) : ''}</Td>
                                    <Td className="text-right font-medium tabular-nums">{formatQty(e.balance_after)}</Td>
                                    <Td className="hidden text-xs md:table-cell">{e.party ?? '—'}{e.reference && <span className="block text-slate-500">{e.reference}</span>}</Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                    <Pagination meta={data.meta} onPage={setPage} />
                    <p className="mt-2 text-xs text-slate-500">"Balance" is the running balance of this SKU at that location after each movement.</p>
                </div>
            ) : null}
        </Modal>
    );
}
