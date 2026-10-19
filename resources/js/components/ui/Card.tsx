import type { ReactNode } from 'react';
import { cx } from '@/lib/format';

export function Card({ children, className }: { children: ReactNode; className?: string }) {
    return <section className={cx('rounded-lg border border-slate-200 bg-white shadow-sm', className)}>{children}</section>;
}

export function CardHeader({ title, description, actions }: { title: ReactNode; description?: ReactNode; actions?: ReactNode }) {
    return (
        <div className="flex flex-col gap-3 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 className="text-base font-semibold text-slate-900">{title}</h2>
                {description && <p className="mt-0.5 text-sm text-slate-500">{description}</p>}
            </div>
            {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
        </div>
    );
}

export function CardBody({ children, className }: { children: ReactNode; className?: string }) {
    return <div className={cx('px-5 py-5', className)}>{children}</div>;
}

export function DescriptionList({ items }: { items: { label: string; value: ReactNode }[] }) {
    return (
        <dl className="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
            {items.map((item) => (
                <div key={item.label}>
                    <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">{item.label}</dt>
                    <dd className="mt-1 text-sm break-words text-slate-900">{item.value ?? '—'}</dd>
                </div>
            ))}
        </dl>
    );
}
