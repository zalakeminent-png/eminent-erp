import { useEffect, useId, useRef, useState } from 'react';
import { Search, X } from 'lucide-react';
import { useApi, useDebouncedValue } from '@/hooks/useApi';
import { cx } from '@/lib/format';
import type { UserSummary } from '../types';

/**
 * Searchable single-user picker backed by /lookups/users (active users only).
 */
export function UserPicker({
    label,
    value,
    onChange,
    error,
    hint,
    disabled,
}: {
    label: string;
    value: UserSummary | null;
    onChange: (user: UserSummary | null) => void;
    error?: string;
    hint?: string;
    disabled?: boolean;
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const debounced = useDebouncedValue(search, 250);
    const wrapper = useRef<HTMLDivElement>(null);
    const { data, loading } = useApi<{ data: UserSummary[] }>(open ? '/lookups/users' : null, { search: debounced });

    useEffect(() => {
        if (!open) return;
        const close = (e: MouseEvent) => !wrapper.current?.contains(e.target as Node) && setOpen(false);
        document.addEventListener('mousedown', close);
        return () => document.removeEventListener('mousedown', close);
    }, [open]);

    return (
        <div ref={wrapper} className="relative">
            <label htmlFor={id} className="mb-1.5 block text-sm font-medium text-slate-700">{label}</label>
            {value ? (
                <div className={cx('flex h-10 items-center justify-between rounded-md border bg-white px-3 text-sm', error ? 'border-red-400' : 'border-slate-300', disabled && 'bg-slate-50')}>
                    <span className="truncate">
                        {value.full_name} <span className="text-slate-400">· {value.email}</span>
                        {!value.is_active && <span className="ml-2 text-xs text-red-600">(inactive)</span>}
                    </span>
                    {!disabled && (
                        <button type="button" onClick={() => onChange(null)} className="ml-2 text-slate-400 hover:text-slate-600" aria-label={`Clear ${label}`}>
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
                        placeholder="Search active users"
                        autoComplete="off"
                        aria-invalid={error ? true : undefined}
                        className={cx(
                            'h-10 w-full rounded-md border bg-white pl-9 pr-3 text-sm focus:outline-none focus:ring-2 disabled:bg-slate-50',
                            error ? 'border-red-400 focus:ring-red-200' : 'border-slate-300 focus:border-brand-500 focus:ring-brand-100',
                        )}
                    />
                </div>
            )}
            {open && !value && (
                <ul role="listbox" className="absolute z-20 mt-1 max-h-60 w-full overflow-auto rounded-md border border-slate-200 bg-white py-1 text-sm shadow-lg">
                    {loading && <li className="px-3 py-2 text-slate-500">Searching…</li>}
                    {!loading && data?.data.length === 0 && <li className="px-3 py-2 text-slate-500">No active users found.</li>}
                    {data?.data.map((u) => (
                        <li key={u.id}>
                            <button
                                type="button"
                                role="option"
                                aria-selected={false}
                                onClick={() => { onChange(u); setOpen(false); setSearch(''); }}
                                className="block w-full px-3 py-2 text-left hover:bg-brand-50"
                            >
                                <span className="font-medium text-slate-900">{u.full_name}</span>
                                <span className="block text-xs text-slate-500">{u.email}{u.employee_code ? ` · ${u.employee_code}` : ''}</span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            {error ? <p className="mt-1.5 text-xs text-red-600">{error}</p> : hint ? <p className="mt-1.5 text-xs text-slate-500">{hint}</p> : null}
        </div>
    );
}
