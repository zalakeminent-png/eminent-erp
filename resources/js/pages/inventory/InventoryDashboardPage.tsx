import { useState } from 'react';
import { Link } from 'react-router-dom';
import { AlertTriangle, ArrowLeftRight, ClipboardCheck, PackageCheck, PackageOpen, Truck } from 'lucide-react';
import { useApi } from '@/hooks/useApi';
import { Card, CardHeader } from '@/components/ui/Card';
import { Alert, PageHeader } from '@/components/ui/Feedback';
import { LoadingBlock } from '@/components/ui/Spinner';
import { formatQty } from '@/pages/master-data/components/shared';
import { filterInputClass } from '@/pages/structure/components/ListControls';
import { ByUomList, MovementHistory, useLocations, useLookups } from './components';
import type { ByUom, LedgerRow } from './types';

interface Dashboard {
    active_items: number;
    items_with_available_stock: number;
    valuation: { known_value: string; unvalued_balances: number };
    stock_by_uom: ByUom[];
    groups: { key: string; label: string; skus: number; quantities: ByUom[]; known_value: string; unvalued_balances: number }[];
    rejected: ByUom[];
    scrap: ByUom[];
    qc_pending: ByUom[];
    low_stock: { count: number; items: { item_code: string; item_name: string; available: string; reorder_level: string | null; uom: string }[] };
    recent_movements: LedgerRow[];
    pending: { goods_receipts: number; transfers: number; material_issues: number; adjustments_awaiting_approval: number };
}

function Stat({ label, value, hint }: { label: string; value: React.ReactNode; hint?: string }) {
    return (
        <Card className="p-4">
            <p className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</p>
            <div className="mt-1 text-2xl font-semibold text-slate-900">{value}</div>
            {hint && <p className="mt-1 text-xs text-slate-500">{hint}</p>}
        </Card>
    );
}

export default function InventoryDashboardPage() {
    const lookups = useLookups();
    const [filters, setFilters] = useState({ factory_id: '', department_id: '', warehouse_id: '', location_id: '', category_id: '', date_from: '', date_to: '' });
    const locations = useLocations(filters.warehouse_id ? { warehouse_id: filters.warehouse_id } : null);
    const categories = useApi<{ data: { id: number; code: string; name: string }[] }>('/item-categories', { all: 1 });
    const { data, loading, error } = useApi<{ data: Dashboard }>('/inventory/dashboard', filters);
    const set = (key: keyof typeof filters, value: string) => setFilters((f) => ({ ...f, [key]: value, ...(key === 'warehouse_id' ? { location_id: '' } : {}) }));
    const d = data?.data;
    const money = (v: string) => formatQty(Number(v).toFixed(2));

    return (
        <>
            <PageHeader title="Inventory Dashboard" description="Live figures from posted stock. Quantities are grouped by unit and never added across different units; values include only stock with recorded costs." />
            <Card className="mb-4">
                <div className="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
                    <select aria-label="Factory" value={filters.factory_id} onChange={(e) => set('factory_id', e.target.value)} className={filterInputClass}>
                        <option value="">All factories</option>
                        {lookups.data?.data.factories.map((f) => <option key={f.id} value={f.id}>{f.name}</option>)}
                    </select>
                    <select aria-label="Department" value={filters.department_id} onChange={(e) => set('department_id', e.target.value)} className={filterInputClass}>
                        <option value="">All departments</option>
                        {lookups.data?.data.departments.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
                    </select>
                    <select aria-label="Warehouse" value={filters.warehouse_id} onChange={(e) => set('warehouse_id', e.target.value)} className={filterInputClass}>
                        <option value="">All warehouses</option>
                        {lookups.data?.data.warehouses.map((w) => <option key={w.id} value={w.id}>{w.code} — {w.name}</option>)}
                    </select>
                    <select aria-label="Location" value={filters.location_id} disabled={!filters.warehouse_id} onChange={(e) => set('location_id', e.target.value)} className={filterInputClass}>
                        <option value="">All locations</option>
                        {locations.data?.data.map((l) => <option key={l.id} value={l.id}>{l.code}</option>)}
                    </select>
                    <select aria-label="Item category" value={filters.category_id} onChange={(e) => set('category_id', e.target.value)} className={filterInputClass}>
                        <option value="">All categories</option>
                        {categories.data?.data.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </select>
                    <input type="date" aria-label="Movements from" value={filters.date_from} onChange={(e) => set('date_from', e.target.value)} className={filterInputClass} />
                    <input type="date" aria-label="Movements to" value={filters.date_to} onChange={(e) => set('date_to', e.target.value)} className={filterInputClass} />
                </div>
            </Card>

            {error ? <Alert tone="danger">{error}</Alert> : loading && !d ? <LoadingBlock /> : d ? (
                <div className={loading ? 'space-y-4 opacity-60' : 'space-y-4'}>
                    <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                        <Stat label="Active stock items" value={d.active_items} />
                        <Stat label="Items with available stock" value={d.items_with_available_stock} />
                        <Stat label="Inventory value (costed stock)" value={money(d.valuation.known_value)}
                            hint={d.valuation.unvalued_balances ? `${d.valuation.unvalued_balances} balance(s) have no recorded cost and are not valued` : 'All stock has recorded costs'} />
                        <Stat label="Low-stock alerts" value={<Link to="/inventory/reports?report=low-stock" className="hover:text-brand-700">{d.low_stock.count}</Link>} />
                    </div>

                    <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
                        <PendingTile icon={<PackageCheck className="h-5 w-5" />} label="Pending goods receipts" value={d.pending.goods_receipts} to="/inventory/goods-receipts?status=submitted" />
                        <PendingTile icon={<ArrowLeftRight className="h-5 w-5" />} label="Open transfers" value={d.pending.transfers} to="/inventory/transfers" />
                        <PendingTile icon={<Truck className="h-5 w-5" />} label="Pending material issues" value={d.pending.material_issues} to="/inventory/material-issues?status=submitted" />
                        <PendingTile icon={<ClipboardCheck className="h-5 w-5" />} label="Adjustments awaiting approval" value={d.pending.adjustments_awaiting_approval} to="/inventory/adjustments?status=submitted" />
                    </div>

                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                        {d.groups.map((g) => (
                            <Card key={g.key} className="p-4">
                                <p className="text-sm font-medium text-slate-700">{g.label}</p>
                                <p className="mt-0.5 text-xs text-slate-500">{g.skus} SKU(s) in stock</p>
                                <div className="mt-2"><ByUomList rows={g.quantities} /></div>
                                <p className="mt-2 text-xs text-slate-500">Value {money(g.known_value)}{g.unvalued_balances ? ` (+${g.unvalued_balances} not valued)` : ''}</p>
                            </Card>
                        ))}
                    </div>

                    <div className="grid gap-4 md:grid-cols-3">
                        <Card className="p-4"><p className="mb-2 text-sm font-medium text-slate-700">QC pending / hold</p><ByUomList rows={d.qc_pending} /></Card>
                        <Card className="p-4"><p className="mb-2 text-sm font-medium text-slate-700">Rejected stock</p><ByUomList rows={d.rejected} /></Card>
                        <Card className="p-4"><p className="mb-2 text-sm font-medium text-slate-700">Scrap</p><ByUomList rows={d.scrap} /></Card>
                    </div>

                    <div className="grid gap-4 lg:grid-cols-2">
                        <Card>
                            <CardHeader title="Low-stock alerts" description="Available stock at or below the item’s reorder (or minimum) level." />
                            {d.low_stock.items.length === 0 ? <p className="px-4 pb-4 text-sm text-slate-500">No alerts.</p> : (
                                <ul className="divide-y divide-slate-100">
                                    {d.low_stock.items.map((i) => (
                                        <li key={i.item_code} className="flex items-center justify-between px-4 py-2 text-sm">
                                            <span className="flex items-center gap-2"><AlertTriangle className="h-4 w-4 text-amber-500" aria-hidden="true" /><span className="font-mono text-xs font-semibold">{i.item_code}</span> {i.item_name}</span>
                                            <span className="text-xs tabular-nums text-slate-600">{formatQty(i.available)} / {formatQty(i.reorder_level)} {i.uom}</span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                        <Card>
                            <CardHeader title="Recent stock movements" actions={<Link to="/inventory/ledger" className="text-sm text-brand-700 hover:underline">Ledger</Link>} />
                            <MovementHistory rows={d.recent_movements} />
                        </Card>
                    </div>
                    {d.stock_by_uom.length === 0 && (
                        <Alert tone="info"><PackageOpen className="mr-1 inline h-4 w-4" aria-hidden="true" />No stock has been posted yet. Start with Opening Stock or a Goods Receipt.</Alert>
                    )}
                </div>
            ) : null}
        </>
    );
}

function PendingTile({ icon, label, value, to }: { icon: React.ReactNode; label: string; value: number; to: string }) {
    return (
        <Link to={to} className="flex items-center gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm hover:border-brand-300">
            <span className="rounded-md bg-brand-50 p-2 text-brand-700">{icon}</span>
            <span><span className="block text-xl font-semibold text-slate-900">{value}</span><span className="text-xs text-slate-500">{label}</span></span>
        </Link>
    );
}
