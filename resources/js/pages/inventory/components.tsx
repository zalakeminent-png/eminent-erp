import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowDownLeft, ArrowUpRight, CheckCircle2, Circle, Search, X } from 'lucide-react';
import { Badge } from '@/components/ui/Feedback';
import { useApi, useDebouncedValue } from '@/hooks/useApi';
import { cx, formatDateTime, humanize } from '@/lib/format';
import { formatQty } from '../master-data/components/shared';
import type { CodedRef, Uom } from '../master-data/types';
import type { DocumentEvent, LedgerRow, LocationRef, Lookups, ReasonCode, StockStatus } from './types';

export { formatQty };

const STATUS_LABELS: Record<string, string> = {
    available: 'Available', qc_pending: 'QC Pending', qc_hold: 'QC Hold', rework: 'Rework', rejected: 'Rejected', scrap: 'Scrap', in_transit: 'In Transit',
};

export function statusLabel(status: string | null | undefined): string {
    return status ? STATUS_LABELS[status] ?? humanize(status) : '—';
}

export function StockStatusBadge({ status }: { status: StockStatus | string | null }) {
    const tone = status === 'available' ? 'success' : status === 'qc_pending' || status === 'qc_hold' ? 'warning'
        : status === 'rejected' || status === 'scrap' ? 'danger' : status === 'in_transit' ? 'info' : 'neutral';
    return <Badge tone={tone}>{statusLabel(status)}</Badge>;
}

const DOC_STATUS_TONES: Record<string, 'neutral' | 'success' | 'warning' | 'danger' | 'info' | 'brand'> = {
    draft: 'neutral', submitted: 'warning', approved: 'info', in_transit: 'brand', partially_received: 'warning',
    received: 'success', posted: 'success', closed: 'neutral', cancelled: 'danger', counting: 'brand',
    uploaded: 'neutral', posting: 'brand', failed: 'danger', reversed: 'danger', active: 'success', partially_released: 'warning',
    released: 'neutral', expired: 'neutral',
};

export function DocumentStatusBadge({ status }: { status: string }) {
    return <Badge tone={DOC_STATUS_TONES[status] ?? 'neutral'}>{humanize(status)}</Badge>;
}

/** Quantity with its unit; never combined with other units. */
export function Qty({ value, uom, className }: { value: string | null | undefined; uom?: string | null; className?: string }) {
    return (
        <span className={cx('tabular-nums', className)}>
            {formatQty(value)}{uom && value !== null && value !== undefined ? <span className="ml-1 text-xs text-slate-500">{uom}</span> : null}
        </span>
    );
}

export function Money({ value }: { value: string | null | undefined }) {
    if (value === null || value === undefined) return <span className="text-xs text-slate-400" title="No cost recorded; not valued">Not valued</span>;
    return <span className="tabular-nums">{formatQty(Number(value).toFixed(2))}</span>;
}

/** Shared dropdown data for inventory screens (scoped to the user's factories). */
export function useLookups() {
    return useApi<{ data: Lookups }>('/inventory/lookups/structure');
}

export function useLocations(params: Record<string, unknown> | null) {
    return useApi<{ data: (LocationRef & { stock_purpose: string | null; is_active: boolean; factory_id: number })[] }>(params ? '/inventory/lookups/locations' : null, params ?? undefined);
}

export function useReasonCodes(category?: string) {
    return useApi<{ data: ReasonCode[] }>('/inventory/reason-codes', { category, active_only: 1 });
}

export function useUoms() {
    return useApi<{ data: Uom[] }>('/uoms', { all: 1, status: 'active' });
}

const selectClass = (error?: string, large?: boolean) => cx(
    'w-full rounded-md border bg-white px-3 text-sm focus:outline-none focus:ring-2 disabled:bg-slate-50',
    large ? 'h-11' : 'h-10',
    error ? 'border-red-400 focus:ring-red-200' : 'border-slate-300 focus:border-brand-500 focus:ring-brand-100',
);

export function FieldShell({ label, error, required, children, hint }: { label: string; error?: string; required?: boolean; children: React.ReactNode; hint?: string }) {
    return (
        <div>
            <span className="mb-1.5 block text-sm font-medium text-slate-700">{label}{required && <span className="text-red-500"> *</span>}</span>
            {children}
            {hint && !error && <p className="mt-1 text-xs text-slate-500">{hint}</p>}
            {error && <p className="mt-1 text-xs text-red-600">{error}</p>}
        </div>
    );
}

/** The user's only active factory (as a string id), or '' when there are several. */
export function useSingleFactoryId(): string {
    const { data } = useLookups();
    const active = (data?.data.factories ?? []).filter((f) => f.is_active);
    return active.length === 1 ? String(active[0].id) : '';
}

/**
 * Factory selector. Warehouses are internal, so stock is organised by factory
 * and location. Hidden when the user has access to only one factory (unless
 * `alwaysShow`), since there is nothing to choose.
 */
export function FactorySelect({ value, onChange, label = 'Factory', error, required, allowAll, alwaysShow }: {
    value: number | string | null | undefined; onChange: (id: string) => void; label?: string; error?: string; required?: boolean; allowAll?: string; alwaysShow?: boolean;
}) {
    const { data } = useLookups();
    const factories = (data?.data.factories ?? []).filter((f) => f.is_active || String(f.id) === String(value));
    if (!alwaysShow && factories.length <= 1) return null;
    return (
        <FieldShell label={label} error={error} required={required}>
            <select aria-label={label} value={value ?? ''} onChange={(e) => onChange(e.target.value)} className={selectClass(error, true)}>
                <option value="">{allowAll ?? 'Select factory'}</option>
                {factories.map((f) => <option key={f.id} value={f.id}>{f.name} ({f.code})</option>)}
            </select>
        </FieldShell>
    );
}

/** Storage location selector (optionally limited to a factory and/or stock purposes). */
export function LocationSelect({ value, onChange, label = 'Location', factoryId, purpose, error, required, compact, placeholder, disabled }: {
    value: number | string | null | undefined; onChange: (id: string, location?: LocationRef) => void; label?: string;
    factoryId?: number | string | null; purpose?: string; error?: string; required?: boolean; compact?: boolean; placeholder?: string; disabled?: boolean;
}) {
    const { data, loading } = useLocations({ factory_id: factoryId || undefined, purpose });
    const select = (
        <select aria-label={label} value={value ?? ''} disabled={disabled} onChange={(e) => onChange(e.target.value, data?.data.find((l) => String(l.id) === e.target.value))}
            className={compact ? cx('h-9 w-full rounded-md border bg-white px-2 text-sm', error ? 'border-red-400' : 'border-slate-300') : selectClass(error, true)}>
            <option value="">{loading ? 'Loading…' : placeholder ?? 'Select location'}</option>
            {data?.data.map((l) => <option key={l.id} value={l.id}>{l.code} — {l.name}</option>)}
        </select>
    );
    if (compact) return <div>{select}{error && <p className="mt-1 text-xs text-red-600">{error}</p>}</div>;
    return <FieldShell label={label} error={error} required={required}>{select}</FieldShell>;
}
export function UomSelect({ value, onChange, baseUomId, error, label = 'UOM' }: { value: number | string | null | undefined; onChange: (id: string) => void; baseUomId?: number | null; error?: string; label?: string }) {
    const { data } = useUoms();
    const uoms = [...(data?.data ?? [])].sort((a, b) => (a.id === baseUomId ? -1 : b.id === baseUomId ? 1 : a.code.localeCompare(b.code)));
    return (
        <div>
            <select aria-label={label} value={value ?? ''} onChange={(e) => onChange(e.target.value)}
                className={cx('h-9 w-full rounded-md border bg-white px-2 text-sm', error ? 'border-red-400' : 'border-slate-300')}>
                <option value="">UOM</option>
                {uoms.map((u) => <option key={u.id} value={u.id}>{u.code}{u.id === baseUomId ? ' (base)' : ''}</option>)}
            </select>
            {error && <p className="mt-1 text-xs text-red-600">{error}</p>}
        </div>
    );
}

export function ReasonSelect({ value, onChange, category, error, required, label = 'Reason' }: { value: number | string | null | undefined; onChange: (id: string) => void; category?: string; error?: string; required?: boolean; label?: string }) {
    const { data } = useReasonCodes(category);
    return (
        <FieldShell label={label} error={error} required={required}>
            <select aria-label={label} value={value ?? ''} onChange={(e) => onChange(e.target.value)} className={selectClass(error, true)}>
                <option value="">Select reason</option>
                {data?.data.map((r) => <option key={r.id} value={r.id}>{r.name} ({r.code})</option>)}
            </select>
        </FieldShell>
    );
}

export function StatusSelect({ value, onChange, options, label = 'Stock status', compact, error }: { value: string | null | undefined; onChange: (v: string) => void; options: string[]; label?: string; compact?: boolean; error?: string }) {
    return (
        <div>
            <select aria-label={label} value={value ?? ''} onChange={(e) => onChange(e.target.value)}
                className={compact ? cx('h-9 w-full rounded-md border bg-white px-2 text-sm', error ? 'border-red-400' : 'border-slate-300') : selectClass(error, true)}>
                {options.map((s) => <option key={s} value={s}>{statusLabel(s)}</option>)}
            </select>
            {error && <p className="mt-1 text-xs text-red-600">{error}</p>}
        </div>
    );
}

/** Large, touch-friendly quantity entry (decimal keyboard on mobile). */
export function QuantityInput({ value, onChange, label, error, allowNegative, large }: { value: string; onChange: (v: string) => void; label: string; error?: string; allowNegative?: boolean; large?: boolean }) {
    return (
        <div>
            <input
                aria-label={label}
                inputMode="decimal"
                value={value}
                onChange={(e) => {
                    const v = e.target.value.replace(',', '.');
                    if (v === '' || (allowNegative ? /^-?\d*\.?\d*$/ : /^\d*\.?\d*$/).test(v)) onChange(v);
                }}
                className={cx('w-full rounded-md border bg-white px-2 text-right tabular-nums focus:outline-none focus:ring-2', large ? 'h-12 text-lg' : 'h-9 text-sm',
                    error ? 'border-red-400 focus:ring-red-200' : 'border-slate-300 focus:border-brand-500 focus:ring-brand-100')}
            />
            {error && <p className="mt-1 text-xs text-red-600">{error}</p>}
        </div>
    );
}

/** Live on-hand / available indicator for an item at a location. */
export function StockAvailability({ itemId, locationId, status = 'available', need }: { itemId?: number | null; locationId?: number | string | null; status?: string; need?: string | null }) {
    const { data, loading } = useApi<{ data: { on_hand: string; reserved: string; available: string; locked_by_count: string | null } }>(
        itemId && locationId ? '/inventory/stock/availability' : null, { item_id: itemId, location_id: locationId, status },
    );
    if (!itemId || !locationId) return null;
    if (loading && !data) return <span className="text-xs text-slate-400">Checking stock…</span>;
    if (!data) return null;
    const short = need && Number(need) > Number(data.data.available);
    return (
        <span className={cx('text-xs', short ? 'font-medium text-red-600' : 'text-slate-500')}>
            {data.data.locked_by_count ? `Locked by count ${data.data.locked_by_count}` : `Available ${formatQty(data.data.available)}`}
            {data.data.reserved !== '0' && ` (reserved ${formatQty(data.data.reserved)})`}
        </span>
    );
}

/** Searchable supplier picker (scoped inventory lookup). */
export function SupplierPicker({ value, onChange, error }: { value: CodedRef | null; onChange: (s: CodedRef | null) => void; error?: string }) {
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const debounced = useDebouncedValue(search, 250);
    const ref = useRef<HTMLDivElement>(null);
    const { data, loading } = useApi<{ data: CodedRef[] }>(open ? '/inventory/lookups/suppliers' : null, { search: debounced });

    useEffect(() => {
        if (!open) return;
        const close = (e: MouseEvent) => !ref.current?.contains(e.target as Node) && setOpen(false);
        document.addEventListener('mousedown', close);
        return () => document.removeEventListener('mousedown', close);
    }, [open]);

    return (
        <div ref={ref} className="relative">
            <span className="mb-1.5 block text-sm font-medium text-slate-700">Supplier <span className="text-red-500">*</span></span>
            {value ? (
                <div className={cx('flex h-11 items-center justify-between rounded-md border bg-white px-3 text-sm', error ? 'border-red-400' : 'border-slate-300')}>
                    <span className="truncate"><span className="font-mono text-xs font-semibold">{value.code}</span> {value.name}</span>
                    <button type="button" onClick={() => onChange(null)} aria-label="Clear supplier" className="text-slate-400 hover:text-slate-600"><X className="h-4 w-4" /></button>
                </div>
            ) : (
                <div className="relative">
                    <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden="true" />
                    <input type="search" aria-label="Supplier" value={search} onFocus={() => setOpen(true)} onChange={(e) => { setSearch(e.target.value); setOpen(true); }}
                        placeholder="Search supplier" className={cx('h-11 w-full rounded-md border bg-white pl-9 pr-3 text-sm', error ? 'border-red-400' : 'border-slate-300')} />
                </div>
            )}
            {open && !value && (
                <ul role="listbox" className="absolute z-30 mt-1 max-h-64 w-full overflow-auto rounded-md border border-slate-200 bg-white py-1 text-sm shadow-lg">
                    {loading && <li className="px-3 py-2 text-slate-500">Searching…</li>}
                    {!loading && data?.data.length === 0 && <li className="px-3 py-2 text-slate-500">No active suppliers found.</li>}
                    {data?.data.map((s) => (
                        <li key={s.id}><button type="button" role="option" aria-selected={false} onClick={() => { onChange(s); setOpen(false); }} className="block w-full px-3 py-2 text-left hover:bg-brand-50">
                            <span className="font-mono text-xs font-semibold">{s.code}</span> <span className="ml-1">{s.name}</span>
                        </button></li>
                    ))}
                </ul>
            )}
            {error && <p className="mt-1 text-xs text-red-600">{error}</p>}
        </div>
    );
}

/** Approval / workflow timeline of a stock document. */
export function ApprovalTimeline({ events }: { events: DocumentEvent[] }) {
    if (!events.length) return <p className="text-sm text-slate-500">No activity yet.</p>;
    return (
        <ol className="space-y-4">
            {events.map((e, i) => (
                <li key={e.id} className="flex gap-3">
                    <div className="flex flex-col items-center">
                        {i === events.length - 1 ? <CheckCircle2 className="h-5 w-5 text-brand-600" aria-hidden="true" /> : <Circle className="h-5 w-5 text-slate-300" aria-hidden="true" />}
                        {i < events.length - 1 && <span className="mt-1 w-px flex-1 bg-slate-200" />}
                    </div>
                    <div className="pb-1 text-sm">
                        <p className="font-medium text-slate-900">{humanize(e.action)}{e.to_status && e.to_status !== e.from_status && <span className="font-normal text-slate-500"> → {humanize(e.to_status)}</span>}</p>
                        <p className="text-xs text-slate-500">{e.user?.full_name ?? 'System'} · {formatDateTime(e.created_at)}</p>
                        {e.transaction_number && <p className="mt-0.5 font-mono text-xs text-slate-600">Stock transaction {e.transaction_number}</p>}
                        {e.remarks && <p className="mt-1 text-slate-700">{e.remarks}</p>}
                    </div>
                </li>
            ))}
        </ol>
    );
}

/** Ledger movement list (stock card, WIP history). */
export function MovementHistory({ rows }: { rows: LedgerRow[] }) {
    if (!rows.length) return <p className="p-4 text-sm text-slate-500">No posted movements.</p>;
    return (
        <ul className="divide-y divide-slate-100">
            {rows.map((r) => {
                const inbound = r.quantity_in !== null;
                return (
                    <li key={r.id} className="flex items-start gap-3 px-4 py-3 text-sm">
                        {inbound ? <ArrowDownLeft className="mt-0.5 h-4 w-4 text-emerald-600" aria-label="In" /> : <ArrowUpRight className="mt-0.5 h-4 w-4 text-red-500" aria-label="Out" />}
                        <div className="min-w-0 flex-1">
                            <p className="font-medium text-slate-900">
                                {inbound ? '+' : '−'}<Qty value={r.quantity_in ?? r.quantity_out} uom={r.uom?.code} /> <span className="font-normal text-slate-500">{r.item?.code}</span>
                            </p>
                            <p className="text-xs text-slate-500">
                                {r.location?.code} · {statusLabel(r.stock_status)} · {humanize(r.transaction_type)} {r.transaction_number}
                            </p>
                        </div>
                        <div className="text-right text-xs text-slate-500">
                            <p>Balance <Qty value={r.running_balance} /></p>
                            <p>{formatDateTime(r.posted_at)}</p>
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}

/** Link to the source document of a ledger entry, when it is a stock document. */
export function SourceLink({ type, id }: { type: string | null; id: number | null }) {
    const base = type ? SOURCE_ROUTES[type] : undefined;
    if (!type || !id) return <span className="text-slate-400">—</span>;
    return base ? <Link to={`${base}/${id}`} className="text-brand-700 hover:underline">{humanize(type)} #{id}</Link> : <span>{humanize(type)} #{id}</span>;
}

export const SOURCE_ROUTES: Record<string, string> = {
    grn: '/inventory/goods-receipts',
    transfer: '/inventory/transfers',
    material_issue: '/inventory/material-issues',
    material_return: '/inventory/material-returns',
    adjustment: '/inventory/adjustments',
    scrap: '/inventory/scrap',
    qc_disposition: '/inventory/qc-stock/dispositions',
    opening_stock: '/inventory/opening-stock',
    stock_count: '/inventory/stock-counts',
};

/** Shows quantities grouped by UOM (never summed across units). */
export function ByUomList({ rows, empty = 'None' }: { rows: { uom: string; quantity: string }[]; empty?: string }) {
    if (!rows.length) return <span className="text-sm text-slate-400">{empty}</span>;
    return (
        <ul className="space-y-0.5">
            {rows.map((r) => <li key={r.uom}><Qty value={r.quantity} uom={r.uom} className="font-semibold text-slate-900" /></li>)}
        </ul>
    );
}

/** Random client key for repeatable actions (safe retries). */
export function requestKey(): string {
    const random = typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`;
    return random.replace(/[^A-Za-z0-9_-]/g, '').slice(0, 40);
}
