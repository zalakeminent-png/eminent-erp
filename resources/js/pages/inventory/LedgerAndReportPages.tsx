import { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { BookOpen, Download, FileBarChart } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useApi, useDebouncedValue } from '@/hooks/useApi';
import { Card } from '@/components/ui/Card';
import { Alert, EmptyState, PageHeader } from '@/components/ui/Feedback';
import { Button } from '@/components/ui/Button';
import { Pagination, Table, Td, Th } from '@/components/ui/Table';
import { LoadingBlock } from '@/components/ui/Spinner';
import { useToast } from '@/components/ui/Toast';
import { errorMessage } from '@/lib/api';
import { cx, formatDateTime, humanize } from '@/lib/format';
import type { PaginationMeta } from '@/types';
import { FilterBar, SearchInput, filterInputClass, useListParams } from '@/pages/structure/components/ListControls';
import { downloadFile, formatQty } from '@/pages/master-data/components/shared';
import { Qty, SourceLink, StockStatusBadge, statusLabel, useLocations, useLookups } from './components';
import type { LedgerRow } from './types';

const TRANSACTION_TYPES = ['opening_stock', 'grn', 'transfer', 'material_issue', 'material_return', 'adjustment', 'scrap', 'qc_disposition', 'stock_count', 'reversal'];

function useExport() {
    const toast = useToast();
    const [busy, setBusy] = useState(false);
    const run = async (url: string, params: Record<string, unknown>, name: string) => {
        setBusy(true);
        try {
            await downloadFile(url, params, name);
        } catch (err) {
            toast.error(errorMessage(err, 'Export failed.'));
        } finally {
            setBusy(false);
        }
    };
    return { busy, run };
}

export function LedgerPage() {
    const { can } = useAuth();
    const list = useListParams({ sort: 'posted_at', direction: 'desc' });
    const [search, setSearch] = useState(list.get('search'));
    const debounced = useDebouncedValue(search);
    const lookups = useLookups();
    const locations = useLocations(list.get('warehouse_id') ? { warehouse_id: list.get('warehouse_id'), include_inactive: 1 } : null);
    const exporter = useExport();
    const params = {
        search: debounced, item_id: list.get('item_id'), warehouse_id: list.get('warehouse_id'), location_id: list.get('location_id'),
        transaction_type: list.get('transaction_type'), status: list.get('status'), department_id: list.get('department_id'),
        date_from: list.get('date_from'), date_to: list.get('date_to'), order: list.direction,
    };
    const { data, loading, error } = useApi<{ data: LedgerRow[]; meta: PaginationMeta }>('/inventory/ledger', { ...params, page: list.page, per_page: 50 });

    return (
        <>
            <PageHeader title="Stock Ledger" description="Every posted movement, immutable. Running balance is per item, location and status, in posting order."
                actions={can('inventory.reports.export') && (
                    <Button variant="secondary" loading={exporter.busy} icon={<Download className="h-4 w-4" />} onClick={() => exporter.run('/inventory/ledger', { ...params, export: 'xlsx' }, 'stock-ledger.xlsx')}>Export</Button>
                )} />
            <Card>
                <FilterBar>
                    <SearchInput value={search} onChange={(v) => { setSearch(v); list.update({ search: v }); }} placeholder="Search item or location" />
                    <select aria-label="Warehouse" value={list.get('warehouse_id')} onChange={(e) => list.update({ warehouse_id: e.target.value, location_id: '' })} className={filterInputClass}>
                        <option value="">All warehouses</option>
                        {lookups.data?.data.warehouses.map((w) => <option key={w.id} value={w.id}>{w.code}</option>)}
                    </select>
                    <select aria-label="Location" value={list.get('location_id')} disabled={!list.get('warehouse_id')} onChange={(e) => list.update({ location_id: e.target.value })} className={filterInputClass}>
                        <option value="">All locations</option>
                        {locations.data?.data.map((l) => <option key={l.id} value={l.id}>{l.code}</option>)}
                    </select>
                    <select aria-label="Department" value={list.get('department_id')} onChange={(e) => list.update({ department_id: e.target.value })} className={filterInputClass}>
                        <option value="">All departments</option>
                        {lookups.data?.data.departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                    </select>
                    <select aria-label="Transaction type" value={list.get('transaction_type')} onChange={(e) => list.update({ transaction_type: e.target.value })} className={filterInputClass}>
                        <option value="">All types</option>
                        {TRANSACTION_TYPES.map((t) => <option key={t} value={t}>{humanize(t)}</option>)}
                    </select>
                    <select aria-label="Stock status" value={list.get('status')} onChange={(e) => list.update({ status: e.target.value })} className={filterInputClass}>
                        <option value="">All statuses</option>
                        {lookups.data?.data.stock_statuses.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
                    </select>
                    <input type="date" aria-label="From date" value={list.get('date_from')} onChange={(e) => list.update({ date_from: e.target.value })} className={filterInputClass} />
                    <input type="date" aria-label="To date" value={list.get('date_to')} onChange={(e) => list.update({ date_to: e.target.value })} className={filterInputClass} />
                    <select aria-label="Order" value={list.direction} onChange={(e) => list.update({ direction: e.target.value })} className={filterInputClass}>
                        <option value="desc">Newest first</option>
                        <option value="asc">Oldest first</option>
                    </select>
                </FilterBar>
                {list.get('item_id') && <p className="border-b border-slate-200 px-4 py-2 text-xs text-slate-600">Filtered to one item. <button type="button" className="text-brand-700 underline" onClick={() => list.update({ item_id: '' })}>Show all items</button></p>}
                {error ? <div className="p-4"><Alert tone="danger">{error}</Alert></div> : loading && !data ? <LoadingBlock /> : data && data.data.length === 0 ? (
                    <EmptyState icon={<BookOpen className="h-6 w-6" />} title="No ledger entries match" />
                ) : data ? (
                    <div className={loading ? 'opacity-60' : undefined}>
                        <Table>
                            <thead>
                                <tr>
                                    <Th>Date</Th><Th>Transaction</Th><Th>Item</Th><Th className="hidden md:table-cell">Location</Th><Th className="hidden sm:table-cell">Status</Th>
                                    <Th className="text-right">In</Th><Th className="text-right">Out</Th><Th className="text-right">Balance</Th>
                                    <Th className="hidden lg:table-cell">Reference</Th><Th className="hidden xl:table-cell">By</Th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 bg-white">
                                {data.data.map((r) => (
                                    <tr key={r.id} className="hover:bg-slate-50">
                                        <Td className="whitespace-nowrap text-xs">{formatDateTime(r.posted_at)}</Td>
                                        <Td className="text-xs"><span className="font-mono font-semibold">{r.transaction_number}</span><span className="block text-slate-500">{humanize(r.transaction_type)}{r.reversal_of_id ? ' (reversal)' : ''}</span></Td>
                                        <Td className="text-xs"><Link to={`/inventory/stock/${r.item?.id}`} className="font-mono font-semibold text-slate-900 hover:text-brand-700">{r.item?.code}</Link><span className="block text-slate-500">{r.item?.name}</span></Td>
                                        <Td className="hidden text-xs md:table-cell">{r.location?.warehouse_code}/{r.location?.code}</Td>
                                        <Td className="hidden sm:table-cell"><StockStatusBadge status={r.stock_status} /></Td>
                                        <Td className="text-right text-emerald-700">{r.quantity_in ? <Qty value={r.quantity_in} /> : ''}</Td>
                                        <Td className="text-right text-red-600">{r.quantity_out ? <Qty value={r.quantity_out} /> : ''}</Td>
                                        <Td className="text-right font-medium"><Qty value={r.running_balance} uom={r.uom?.code} /></Td>
                                        <Td className="hidden text-xs lg:table-cell"><SourceLink type={r.source_document_type} id={r.source_document_id} /></Td>
                                        <Td className="hidden text-xs xl:table-cell">{r.created_by?.full_name}</Td>
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

interface ReportResponse {
    report: { key: string; label: string };
    columns: { key: string; label: string }[];
    data: Record<string, string | number | null>[];
    summary: Record<string, string | number> | null;
    meta: PaginationMeta;
}

const NUMERIC = /quantity|qty|available|reorder|min_stock|shortfall|value|cost|variance|system|counted|received|outstanding|reserved|released|open|running_balance|^new$|base_quantity|age_days/i;

export function ReportsPage() {
    const { can } = useAuth();
    const [params, setParams] = useSearchParams();
    const report = params.get('report') ?? 'current-stock';
    const [filters, setFilters] = useState({ search: '', warehouse_id: '', category_id: '', status: '', date_from: '', date_to: '' });
    const [page, setPage] = useState(1);
    const debouncedSearch = useDebouncedValue(filters.search);
    const list = useApi<{ data: { key: string; label: string }[] }>('/inventory/reports');
    const lookups = useLookups();
    const categories = useApi<{ data: { id: number; name: string }[] }>('/item-categories', { all: 1 });
    const query = { ...filters, search: debouncedSearch };
    const { data, loading, error } = useApi<ReportResponse>(`/inventory/reports/${report}`, { ...query, page, per_page: 50 });
    const exporter = useExport();
    const set = (key: keyof typeof filters, value: string) => { setFilters((f) => ({ ...f, [key]: value })); setPage(1); };

    return (
        <>
            <PageHeader title="Inventory Reports" description="All reports are calculated live from posted stock data. Values appear only where costs were recorded." />
            <div className="grid gap-4 lg:grid-cols-[16rem_1fr]">
                <Card className="h-fit">
                    <nav aria-label="Reports" className="p-2">
                        {list.data?.data.map((r) => (
                            <button key={r.key} type="button" onClick={() => { setParams({ report: r.key }, { replace: true }); setPage(1); }}
                                className={cx('block w-full rounded-md px-3 py-2 text-left text-sm', r.key === report ? 'bg-brand-50 font-medium text-brand-700' : 'text-slate-700 hover:bg-slate-50')}>
                                {r.label}
                            </button>
                        ))}
                    </nav>
                </Card>
                <Card>
                    <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 p-4">
                        <h2 className="text-base font-semibold text-slate-900">{data?.report.label ?? '…'}</h2>
                        {can('inventory.reports.export') && (
                            <div className="flex gap-2">
                                <Button variant="secondary" size="sm" loading={exporter.busy} icon={<Download className="h-4 w-4" />} onClick={() => exporter.run(`/inventory/reports/${report}`, { ...query, export: 'xlsx' }, `${report}.xlsx`)}>Excel</Button>
                                <Button variant="secondary" size="sm" disabled={exporter.busy} onClick={() => exporter.run(`/inventory/reports/${report}`, { ...query, export: 'csv' }, `${report}.csv`)}>CSV</Button>
                            </div>
                        )}
                    </div>
                    <FilterBar>
                        <SearchInput value={filters.search} onChange={(v) => set('search', v)} placeholder="Search item / document" />
                        <select aria-label="Warehouse" value={filters.warehouse_id} onChange={(e) => set('warehouse_id', e.target.value)} className={filterInputClass}>
                            <option value="">All warehouses</option>
                            {lookups.data?.data.warehouses.map((w) => <option key={w.id} value={w.id}>{w.code}</option>)}
                        </select>
                        <select aria-label="Category" value={filters.category_id} onChange={(e) => set('category_id', e.target.value)} className={filterInputClass}>
                            <option value="">All categories</option>
                            {categories.data?.data.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                        </select>
                        <input type="date" aria-label="From date" value={filters.date_from} onChange={(e) => set('date_from', e.target.value)} className={filterInputClass} />
                        <input type="date" aria-label="To date" value={filters.date_to} onChange={(e) => set('date_to', e.target.value)} className={filterInputClass} />
                    </FilterBar>
                    {data?.summary && (
                        <div className="border-b border-slate-200 bg-slate-50 px-4 py-3 text-sm">
                            {Object.entries(data.summary).map(([k, v]) => (
                                <p key={k}><span className="text-slate-500">{humanize(k)}:</span> <span className="font-medium">{typeof v === 'string' && /^-?\d/.test(v) ? formatQty(v) : String(v)}</span></p>
                            ))}
                        </div>
                    )}
                    {error ? <div className="p-4"><Alert tone="danger">{error}</Alert></div> : loading && !data ? <LoadingBlock /> : data && data.data.length === 0 ? (
                        <EmptyState icon={<FileBarChart className="h-6 w-6" />} title="No data for these filters" />
                    ) : data ? (
                        <div className={loading ? 'opacity-60' : undefined}>
                            <Table>
                                <thead><tr>{data.columns.map((c) => <Th key={c.key} className={NUMERIC.test(c.key) ? 'text-right' : undefined}>{c.label}</Th>)}</tr></thead>
                                <tbody className="divide-y divide-slate-100 bg-white">
                                    {data.data.map((row, i) => (
                                        <tr key={i}>
                                            {data.columns.map((c) => {
                                                const v = row[c.key];
                                                const numeric = NUMERIC.test(c.key);
                                                return (
                                                    <Td key={c.key} className={cx('text-xs', numeric && 'text-right tabular-nums')}>
                                                        {v === null || v === undefined || v === '' ? (c.key === 'value' ? <span className="text-slate-400">Not valued</span> : '—')
                                                            : c.key === 'stock_status' || c.key === 'to_status' || c.key === 'from_status' ? statusLabel(String(v))
                                                                : numeric && typeof v === 'string' ? formatQty(v) : String(v)}
                                                    </Td>
                                                );
                                            })}
                                        </tr>
                                    ))}
                                </tbody>
                            </Table>
                            <Pagination meta={data.meta} onPage={setPage} />
                        </div>
                    ) : null}
                </Card>
            </div>
        </>
    );
}
