import type { ReactNode, ThHTMLAttributes, TdHTMLAttributes } from 'react';
import { ArrowDown, ArrowUp, ArrowUpDown, ChevronLeft, ChevronRight } from 'lucide-react';
import { cx } from '@/lib/format';
import type { PaginationMeta } from '@/types';

export function Table({ children, className }: { children: ReactNode; className?: string }) {
    return (
        <div className="overflow-x-auto">
            <table className={cx('min-w-full divide-y divide-slate-200 text-sm', className)}>{children}</table>
        </div>
    );
}

export function Th({ children, className, ...props }: ThHTMLAttributes<HTMLTableCellElement>) {
    return (
        <th scope="col" className={cx('bg-slate-50 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500', className)} {...props}>
            {children}
        </th>
    );
}

export function SortableTh({
    label,
    field,
    sort,
    direction,
    onSort,
    className,
}: {
    label: string;
    field: string;
    sort: string;
    direction: 'asc' | 'desc';
    onSort: (field: string) => void;
    className?: string;
}) {
    const active = sort === field;
    const Icon = !active ? ArrowUpDown : direction === 'asc' ? ArrowUp : ArrowDown;
    return (
        <Th className={className} aria-sort={active ? (direction === 'asc' ? 'ascending' : 'descending') : 'none'}>
            <button type="button" onClick={() => onSort(field)} className="inline-flex items-center gap-1 uppercase hover:text-slate-800">
                {label}
                <Icon className={cx('h-3.5 w-3.5', active ? 'text-brand-600' : 'text-slate-400')} aria-hidden="true" />
            </button>
        </Th>
    );
}

export function Td({ children, className, ...props }: TdHTMLAttributes<HTMLTableCellElement>) {
    return (
        <td className={cx('px-4 py-3 align-middle text-slate-700', className)} {...props}>
            {children}
        </td>
    );
}

export function Pagination({ meta, onPage }: { meta: PaginationMeta; onPage: (page: number) => void }) {
    if (meta.total === 0) return null;
    return (
        <div className="flex flex-col items-center justify-between gap-3 border-t border-slate-200 px-4 py-3 text-sm text-slate-600 sm:flex-row">
            <p>
                Showing <span className="font-medium text-slate-900">{meta.from}</span>–<span className="font-medium text-slate-900">{meta.to}</span> of{' '}
                <span className="font-medium text-slate-900">{meta.total}</span>
            </p>
            <div className="flex items-center gap-2">
                <button
                    type="button"
                    onClick={() => onPage(meta.current_page - 1)}
                    disabled={meta.current_page <= 1}
                    className="inline-flex h-8 items-center gap-1 rounded-md border border-slate-300 bg-white px-2.5 hover:bg-slate-50 disabled:opacity-50"
                >
                    <ChevronLeft className="h-4 w-4" aria-hidden="true" /> Prev
                </button>
                <span className="px-1">
                    Page {meta.current_page} of {meta.last_page}
                </span>
                <button
                    type="button"
                    onClick={() => onPage(meta.current_page + 1)}
                    disabled={meta.current_page >= meta.last_page}
                    className="inline-flex h-8 items-center gap-1 rounded-md border border-slate-300 bg-white px-2.5 hover:bg-slate-50 disabled:opacity-50"
                >
                    Next <ChevronRight className="h-4 w-4" aria-hidden="true" />
                </button>
            </div>
        </div>
    );
}
