import { useEffect, useId, useRef, useState } from 'react';
import { Search, X } from 'lucide-react';
import { Badge } from '@/components/ui/Feedback';
import { useApi, useDebouncedValue } from '@/hooks/useApi';
import { api } from '@/lib/api';
import { cx } from '@/lib/format';
import type { DocStatus, Item } from '../types';

export function DocStatusBadge({ status }: { status: DocStatus }) {
    const tone = status === 'approved' ? 'success' : status === 'draft' ? 'warning' : 'neutral';
    return <Badge tone={tone}>{status === 'approved' ? 'Approved' : status === 'draft' ? 'Draft' : 'Inactive'}</Badge>;
}

/** "1000.5" → "1,000.5" without losing precision (string-based). */
export function formatQty(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') return '—';
    const [int, frac] = value.split('.');
    const grouped = int.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return frac ? `${grouped}.${frac}` : grouped;
}

/** Triggers a browser download for an authenticated GET (cookie session). */
export async function downloadFile(url: string, params?: Record<string, unknown>, fallbackName = 'download'): Promise<void> {
    const response = await api.get(url, { params, responseType: 'blob' });
    const disposition = String(response.headers['content-disposition'] ?? '');
    const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition);
    const href = URL.createObjectURL(response.data as Blob);
    const a = document.createElement('a');
    a.href = href;
    a.download = match ? decodeURIComponent(match[1]) : fallbackName;
    a.click();
    URL.revokeObjectURL(href);
}

type ItemOption = Pick<Item, 'id' | 'code' | 'name' | 'item_type' | 'is_active'> & { base_uom?: Item['base_uom'] };

/** Searchable item picker (server-side search on code, name, drawing no.). */
export function ItemPicker({
    label,
    value,
    onChange,
    error,
    params,
    disabled,
    placeholder = 'Search item code or name',
    compact = false,
}: {
    label?: string;
    value: ItemOption | null;
    onChange: (item: ItemOption | null) => void;
    error?: string;
    params?: Record<string, unknown>;
    disabled?: boolean;
    placeholder?: string;
    compact?: boolean;
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const debounced = useDebouncedValue(search, 250);
    const ref = useRef<HTMLDivElement>(null);
    const { data, loading } = useApi<{ data: ItemOption[] }>(open ? '/items' : null, { search: debounced, status: 'active', per_page: 20, ...params });

    useEffect(() => {
        if (!open) return;
        const close = (e: MouseEvent) => !ref.current?.contains(e.target as Node) && setOpen(false);
        document.addEventListener('mousedown', close);
        return () => document.removeEventListener('mousedown', close);
    }, [open]);

    const height = compact ? 'h-9' : 'h-10';

    return (
        <div ref={ref} className="relative">
            {label && <label htmlFor={id} className="mb-1.5 block text-sm font-medium text-slate-700">{label}</label>}
            {value ? (
                <div className={cx('flex items-center justify-between rounded-md border bg-white px-3 text-sm', height, error ? 'border-red-400' : 'border-slate-300', disabled && 'bg-slate-50')}>
                    <span className="min-w-0 truncate">
                        <span className="font-mono text-xs font-semibold">{value.code}</span> <span className="text-slate-600">{value.name}</span>
                    </span>
                    {!disabled && (
                        <button type="button" onClick={() => onChange(null)} className="ml-2 text-slate-400 hover:text-slate-600" aria-label={`Clear ${label ?? 'item'}`}>
                            <X className="h-4 w-4" />
                        </button>
                    )}
                </div>
            ) : (
                <div className="relative">
                    <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden="true" />
                    <input
                        id={id}
                        type="search"
                        disabled={disabled}
                        value={search}
                        onFocus={() => setOpen(true)}
                        onChange={(e) => { setSearch(e.target.value); setOpen(true); }}
                        placeholder={placeholder}
                        autoComplete="off"
                        aria-label={label ?? 'Item'}
                        className={cx('w-full rounded-md border bg-white pl-9 pr-3 text-sm focus:outline-none focus:ring-2', height, error ? 'border-red-400 focus:ring-red-200' : 'border-slate-300 focus:border-brand-500 focus:ring-brand-100')}
                    />
                </div>
            )}
            {open && !value && (
                <ul role="listbox" className="absolute z-30 mt-1 max-h-64 w-full min-w-[16rem] overflow-auto rounded-md border border-slate-200 bg-white py-1 text-sm shadow-lg">
                    {loading && <li className="px-3 py-2 text-slate-500">Searching…</li>}
                    {!loading && data?.data.length === 0 && <li className="px-3 py-2 text-slate-500">No matching active items.</li>}
                    {data?.data.map((item) => (
                        <li key={item.id}>
                            <button type="button" role="option" aria-selected={false} onClick={() => { onChange(item); setOpen(false); setSearch(''); }} className="block w-full px-3 py-2 text-left hover:bg-brand-50">
                                <span className="font-mono text-xs font-semibold text-slate-900">{item.code}</span>
                                <span className="ml-2 text-slate-700">{item.name}</span>
                                {item.base_uom && <span className="ml-2 text-xs text-slate-400">{item.base_uom.code}</span>}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            {error && <p className="mt-1 text-xs text-red-600">{error}</p>}
        </div>
    );
}

/** Small native select for line editors. */
export function MiniSelect({ value, onChange, options, label, error, placeholder, disabled }: {
    value: string | number | null | undefined;
    onChange: (value: string) => void;
    options: { value: string | number; label: string; disabled?: boolean }[];
    label: string;
    error?: string;
    placeholder?: string;
    disabled?: boolean;
}) {
    return (
        <div>
            <select
                aria-label={label}
                value={value ?? ''}
                disabled={disabled}
                onChange={(e) => onChange(e.target.value)}
                className={cx('h-9 w-full rounded-md border bg-white px-2 text-sm focus:outline-none focus:ring-2 disabled:bg-slate-50', error ? 'border-red-400 focus:ring-red-200' : 'border-slate-300 focus:border-brand-500 focus:ring-brand-100')}
            >
                {placeholder !== undefined && <option value="">{placeholder}</option>}
                {options.map((o) => <option key={o.value} value={o.value} disabled={o.disabled}>{o.label}</option>)}
            </select>
            {error && <p className="mt-1 text-xs text-red-600">{error}</p>}
        </div>
    );
}

export function MiniInput({ value, onChange, label, error, inputMode = 'decimal', placeholder, className, type = 'text' }: {
    value: string | number | null | undefined;
    onChange: (value: string) => void;
    label: string;
    error?: string;
    inputMode?: 'decimal' | 'numeric' | 'text';
    placeholder?: string;
    className?: string;
    type?: string;
}) {
    return (
        <div className={className}>
            <input
                aria-label={label}
                type={type}
                inputMode={inputMode}
                value={value ?? ''}
                placeholder={placeholder}
                onChange={(e) => onChange(e.target.value)}
                className={cx('h-9 w-full rounded-md border bg-white px-2 text-sm focus:outline-none focus:ring-2', error ? 'border-red-400 focus:ring-red-200' : 'border-slate-300 focus:border-brand-500 focus:ring-brand-100')}
            />
            {error && <p className="mt-1 text-xs text-red-600">{error}</p>}
        </div>
    );
}

/** Lists approval/validation errors returned under "approval" or other keys. */
export function errorList(errors: Record<string, string>): string[] {
    return Object.values(errors);
}
