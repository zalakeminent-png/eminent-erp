import type { ReactNode } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Search } from 'lucide-react';

export const filterInputClass =
    'h-10 w-full rounded-md border border-slate-300 bg-white px-3 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100';

/**
 * URL-synced list state (search, filters, sort, page) so lists are
 * shareable and survive refreshes.
 */
export function useListParams(defaults: { sort: string; direction?: 'asc' | 'desc' }) {
    const [params, setParams] = useSearchParams();

    const get = (key: string, fallback = '') => params.get(key) ?? fallback;

    const update = (changes: Record<string, string>) => {
        const next = new URLSearchParams(params);
        Object.entries(changes).forEach(([k, v]) => (v ? next.set(k, v) : next.delete(k)));
        if (!('page' in changes)) next.delete('page');
        setParams(next, { replace: true });
    };

    const sort = get('sort', defaults.sort);
    const direction = (get('direction', defaults.direction ?? 'asc') as 'asc' | 'desc');

    return {
        get,
        update,
        sort,
        direction,
        page: get('page', '1'),
        onSort: (field: string) => update({ sort: field, direction: sort === field && direction === 'asc' ? 'desc' : 'asc' }),
        clear: () => setParams({}, { replace: true }),
        hasFilters: [...params.keys()].some((k) => !['sort', 'direction', 'page'].includes(k)),
    };
}

export function SearchInput({ value, onChange, placeholder }: { value: string; onChange: (v: string) => void; placeholder: string }) {
    return (
        <label className="relative block">
            <span className="sr-only">{placeholder}</span>
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden="true" />
            <input type="search" value={value} onChange={(e) => onChange(e.target.value)} placeholder={placeholder} className={`${filterInputClass} pl-9`} />
        </label>
    );
}

export function FilterBar({ children }: { children: ReactNode }) {
    return <div className="grid grid-cols-1 gap-3 border-b border-slate-200 p-4 sm:grid-cols-2 lg:flex lg:flex-wrap [&>*]:lg:min-w-[180px] [&>*:first-child]:lg:flex-1">{children}</div>;
}
