import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { CheckCircle2, Download, Search, Upload, X } from 'lucide-react';
import { Badge } from '@/components/ui/Feedback';
import { Alert } from '@/components/ui/Feedback';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { Table, Td, Th } from '@/components/ui/Table';
import { useToast } from '@/components/ui/Toast';
import { useApi, useDebouncedValue } from '@/hooks/useApi';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { downloadFile } from '@/lib/download';
import { cx } from '@/lib/format';
import type { DocStatus, ImportResult, Lookups, Sku, StockStatus, Vendor } from './types';

/** "1250.5" → "1,250.5" without losing precision. */
export function formatQty(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') return '—';
    const negative = value.startsWith('-');
    const [int, frac] = value.replace('-', '').split('.');
    const grouped = int.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return `${negative ? '−' : ''}${grouped}${frac ? `.${frac}` : ''}`;
}

export function StockStatusBadge({ status, label }: { status: StockStatus; label?: string }) {
    const tone = status === 'ok' ? 'success' : status === 'low' ? 'danger' : 'neutral';
    return <Badge tone={tone}>{label ?? (status === 'ok' ? 'OK' : status === 'low' ? 'LOW STOCK' : 'Reorder level not set')}</Badge>;
}

const DOC_TONES: Record<DocStatus, 'neutral' | 'success' | 'warning' | 'danger'> = { draft: 'warning', posted: 'success', reversed: 'danger', cancelled: 'neutral' };

export function DocStatusBadge({ status }: { status: DocStatus }) {
    return <Badge tone={DOC_TONES[status]}>{status.charAt(0).toUpperCase() + status.slice(1)}</Badge>;
}

export function VendorCheckBadge({ mapped }: { mapped: boolean | null }) {
    if (mapped === null) return null;
    return mapped ? <Badge tone="success">Mapped</Badge> : <Badge tone="danger">⚠ Not mapped</Badge>;
}

export function useStockLookups() {
    return useApi<{ data: Lookups }>('/stock/lookups');
}

const fieldClass = (error?: string) => cx(
    'h-10 w-full rounded-md border bg-white px-3 text-sm focus:outline-none focus:ring-2 disabled:bg-slate-50 disabled:text-slate-500',
    error ? 'border-red-400 focus:ring-red-200' : 'border-slate-300 focus:border-brand-500 focus:ring-brand-100',
);

export function FormField({ label, required, error, hint, children }: { label: string; required?: boolean; error?: string; hint?: ReactNode; children: ReactNode }) {
    return (
        <div>
            <span className="mb-1.5 block text-sm font-medium text-slate-700">{label}{required && <span className="ml-0.5 text-red-500" aria-hidden="true">*</span>}</span>
            {children}
            {error ? <p className="mt-1.5 text-xs text-red-600">{error}</p> : hint ? <p className="mt-1.5 text-xs text-slate-500">{hint}</p> : null}
        </div>
    );
}

export function SelectField({ label, value, onChange, options, placeholder, required, error, hint, disabled }: {
    label: string; value: string | number | null | undefined; onChange: (v: string) => void; options: { value: string | number; label: string }[];
    placeholder?: string; required?: boolean; error?: string; hint?: ReactNode; disabled?: boolean;
}) {
    return (
        <FormField label={label} required={required} error={error} hint={hint}>
            <select aria-label={label} value={value ?? ''} onChange={(e) => onChange(e.target.value)} disabled={disabled} className={fieldClass(error)}>
                <option value="">{placeholder ?? 'Select…'}</option>
                {options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
            </select>
        </FormField>
    );
}

/**
 * Searchable picker backed by an API "options" endpoint. The selected item
 * is shown with a clear button; typing searches the server.
 */
function SearchPicker<T extends { id: number }>({ label, endpoint, params, value, onChange, render, renderSelected, placeholder, required, error, disabled }: {
    label: string; endpoint: string; params?: Record<string, unknown>; value: T | null; onChange: (item: T | null) => void;
    render: (item: T) => ReactNode; renderSelected: (item: T) => ReactNode; placeholder: string; required?: boolean; error?: string; disabled?: boolean;
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const debounced = useDebouncedValue(search, 250);
    const ref = useRef<HTMLDivElement>(null);
    const { data, loading } = useApi<{ data: T[] }>(open ? endpoint : null, { search: debounced, ...params });

    useEffect(() => {
        if (!open) return;
        const close = (e: MouseEvent) => !ref.current?.contains(e.target as Node) && setOpen(false);
        document.addEventListener('mousedown', close);
        return () => document.removeEventListener('mousedown', close);
    }, [open]);

    return (
        <div ref={ref} className="relative">
            <label htmlFor={id} className="mb-1.5 block text-sm font-medium text-slate-700">{label}{required && <span className="ml-0.5 text-red-500" aria-hidden="true">*</span>}</label>
            {value ? (
                <div className={cx('flex min-h-10 items-center justify-between gap-2 rounded-md border bg-white px-3 py-1.5 text-sm', error ? 'border-red-400' : 'border-slate-300', disabled && 'bg-slate-50')}>
                    <span className="min-w-0">{renderSelected(value)}</span>
                    {!disabled && (
                        <button type="button" onClick={() => onChange(null)} className="shrink-0 text-slate-400 hover:text-slate-600" aria-label={`Clear ${label}`}>
                            <X className="h-4 w-4" />
                        </button>
                    )}
                </div>
            ) : (
                <div className="relative">
                    <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden="true" />
                    <input id={id} type="search" disabled={disabled} value={search} onFocus={() => setOpen(true)} onChange={(e) => { setSearch(e.target.value); setOpen(true); }}
                        placeholder={placeholder} autoComplete="off" aria-label={label} className={cx(fieldClass(error), 'pl-9')} />
                </div>
            )}
            {open && !value && (
                <ul role="listbox" className="absolute z-30 mt-1 max-h-72 w-full overflow-auto rounded-md border border-slate-200 bg-white py-1 text-sm shadow-lg">
                    {loading && <li className="px-3 py-2 text-slate-500">Searching…</li>}
                    {!loading && data?.data.length === 0 && <li className="px-3 py-2 text-slate-500">Nothing found.</li>}
                    {data?.data.map((item) => (
                        <li key={item.id}>
                            <button type="button" role="option" aria-selected={false} onClick={() => { onChange(item); setOpen(false); setSearch(''); }} className="block w-full px-3 py-2 text-left hover:bg-brand-50">
                                {render(item)}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            {error && <p className="mt-1.5 text-xs text-red-600">{error}</p>}
        </div>
    );
}

export function SkuPicker(props: { value: Sku | null; onChange: (s: Sku | null) => void; vendorId?: number | null; required?: boolean; error?: string; disabled?: boolean; label?: string }) {
    return (
        <SearchPicker<Sku>
            label={props.label ?? 'SKU'} endpoint="/skus/options" params={{ vendor_id: props.vendorId || undefined }} value={props.value} onChange={props.onChange}
            required={props.required} error={props.error} disabled={props.disabled} placeholder="Search SKU code or product name"
            render={(s) => (
                <span className="flex items-start justify-between gap-2">
                    <span><span className="font-mono text-xs font-semibold text-slate-900">{s.sku}</span><span className="block text-xs text-slate-600">{s.product_name}</span></span>
                    {s.mapped === true && <Badge tone="success">Supplied</Badge>}
                </span>
            )}
            renderSelected={(s) => <><span className="font-mono text-xs font-semibold">{s.sku}</span> <span className="text-slate-600">{s.product_name}</span></>}
        />
    );
}

export function VendorPicker(props: { value: Vendor | null; onChange: (v: Vendor | null) => void; skuId?: number | null; required?: boolean; error?: string; label?: string }) {
    return (
        <SearchPicker<Vendor>
            label={props.label ?? 'Vendor'} endpoint="/vendors/options" params={{ sku_id: props.skuId || undefined }} value={props.value} onChange={props.onChange}
            required={props.required} error={props.error} placeholder="Search vendor name or code"
            render={(v) => (
                <span className="flex items-start justify-between gap-2">
                    <span><span className="font-medium text-slate-900">{v.name}</span><span className="block text-xs text-slate-500">{v.code}{v.city ? ` · ${v.city}` : ''}</span></span>
                    {v.mapped === true && <Badge tone="success">Supplies this SKU</Badge>}
                </span>
            )}
            renderSelected={(v) => <><span className="font-medium">{v.name}</span> <span className="text-xs text-slate-500">{v.code}</span></>}
        />
    );
}

/** Product details that appear automatically once a SKU is chosen. */
export function SkuDetails({ sku }: { sku: Sku | null }) {
    if (!sku) return null;
    return (
        <dl className="grid grid-cols-2 gap-3 rounded-md border border-slate-200 bg-slate-50 p-3 text-sm sm:grid-cols-4">
            <div className="col-span-2"><dt className="text-xs text-slate-500">Product name</dt><dd className="font-medium text-slate-900">{sku.product_name}</dd></div>
            <div><dt className="text-xs text-slate-500">Category</dt><dd>{sku.category ?? '—'}</dd></div>
            <div><dt className="text-xs text-slate-500">Material</dt><dd>{sku.material ?? '—'}{sku.size ? ` · ${sku.size}` : ''}</dd></div>
        </dl>
    );
}

export function ExportButton({ url, params, name, label = 'Export' }: { url: string; params?: Record<string, unknown>; name: string; label?: string }) {
    const toast = useToast();
    const [busy, setBusy] = useState(false);
    return (
        <Button variant="secondary" loading={busy} icon={<Download className="h-4 w-4" />} onClick={async () => {
            setBusy(true);
            try {
                await downloadFile(url, { ...params, export: 'csv' }, `${name}.csv`);
            } catch (err) {
                toast.error(errorMessage(err, 'Export failed.'));
            } finally {
                setBusy(false);
            }
        }}>{label}</Button>
    );
}

/**
 * CSV import in two steps: "Check file" validates every row without saving;
 * "Import" saves the valid rows (existing records are skipped, never changed).
 */
export function ImportDialog({ title, endpoint, templateUrl, columns, onClose, onImported }: {
    title: string; endpoint: string; templateUrl: string; columns: string; onClose: () => void; onImported: () => void;
}) {
    const toast = useToast();
    const [file, setFile] = useState<File | null>(null);
    const [result, setResult] = useState<ImportResult | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const send = async (dryRun: boolean) => {
        if (!file) return;
        setBusy(true);
        setError(null);
        try {
            const form = new FormData();
            form.append('file', file);
            if (dryRun) form.append('dry_run', '1');
            const { data } = await api.post<ImportResult>(endpoint, form);
            setResult(data);
            if (!dryRun) {
                toast.success(data.message);
                onImported();
            }
        } catch (err) {
            const fe = fieldErrors(err);
            setError(fe.file ?? Object.values(fe)[0] ?? errorMessage(err));
        } finally {
            setBusy(false);
        }
    };

    const done = result && !result.dry_run;

    return (
        <Modal open size="lg" title={title} onClose={onClose} footer={
            <>
                <Button variant="secondary" onClick={onClose}>{done ? 'Close' : 'Cancel'}</Button>
                {!done && <Button variant="secondary" disabled={!file} loading={busy && !result} onClick={() => send(true)}>Check file</Button>}
                {!done && <Button disabled={!result || result.summary.ready === 0} loading={busy && !!result} onClick={() => send(false)}>Import {result ? result.summary.ready : ''} row(s)</Button>}
            </>
        }>
            <div className="space-y-4">
                <p>Columns: <span className="font-medium text-slate-800">{columns}</span>. Save your Excel sheet as <strong>CSV</strong> (File → Save As → CSV).</p>
                <Button variant="ghost" size="sm" icon={<Download className="h-4 w-4" />} onClick={() => downloadFile(templateUrl, undefined, 'template.csv')}>Download template</Button>
                <label className="flex cursor-pointer items-center gap-3 rounded-md border-2 border-dashed border-slate-300 p-4 hover:border-brand-400">
                    <Upload className="h-5 w-5 text-slate-400" aria-hidden="true" />
                    <span>{file ? file.name : 'Choose a .csv file'}</span>
                    <input type="file" accept=".csv,text/csv" className="sr-only" aria-label="Import file" onChange={(e) => { setFile(e.target.files?.[0] ?? null); setResult(null); }} />
                </label>
                {error && <Alert tone="danger">{error}</Alert>}
                {result && (
                    <>
                        <Alert tone={result.summary.failed ? 'warning' : 'success'}>{result.message}</Alert>
                        <div className="max-h-72 overflow-auto rounded-md border border-slate-200">
                            <Table>
                                <thead><tr><Th>Row</Th><Th>Result</Th><Th>Message</Th></tr></thead>
                                <tbody className="divide-y divide-slate-100 bg-white">
                                    {result.rows.map((r) => (
                                        <tr key={r.row}>
                                            <Td className="tabular-nums">{r.row}</Td>
                                            <Td>{r.status === 'ok' ? <span className="inline-flex items-center gap-1 text-emerald-700"><CheckCircle2 className="h-4 w-4" />{done ? 'Imported' : 'Ready'}</span>
                                                : <Badge tone={r.status === 'failed' ? 'danger' : 'neutral'}>{r.status === 'failed' ? 'Error' : 'Skipped'}</Badge>}</Td>
                                            <Td className="text-xs">{r.message}</Td>
                                        </tr>
                                    ))}
                                </tbody>
                            </Table>
                        </div>
                    </>
                )}
            </div>
        </Modal>
    );
}
