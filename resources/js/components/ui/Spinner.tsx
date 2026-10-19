import { cx } from '@/lib/format';

export function Spinner({ className }: { className?: string }) {
    return (
        <svg className={cx('animate-spin text-current', className ?? 'h-5 w-5')} viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
        </svg>
    );
}

export function FullPageSpinner() {
    return (
        <div className="flex h-full min-h-screen items-center justify-center text-brand-600" role="status" aria-label="Loading">
            <Spinner className="h-8 w-8" />
        </div>
    );
}

export function LoadingBlock({ label = 'Loading…' }: { label?: string }) {
    return (
        <div className="flex items-center justify-center gap-3 py-16 text-sm text-slate-500" role="status">
            <Spinner className="h-5 w-5 text-brand-600" />
            {label}
        </div>
    );
}
