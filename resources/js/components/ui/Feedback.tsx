import type { ReactNode } from 'react';
import { AlertCircle, CheckCircle2, Info, Inbox } from 'lucide-react';
import { cx } from '@/lib/format';

type Tone = 'neutral' | 'success' | 'warning' | 'danger' | 'info' | 'brand';

const badgeTones: Record<Tone, string> = {
    neutral: 'bg-slate-100 text-slate-700 ring-slate-200',
    success: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    warning: 'bg-amber-50 text-amber-800 ring-amber-200',
    danger: 'bg-red-50 text-red-700 ring-red-200',
    info: 'bg-sky-50 text-sky-700 ring-sky-200',
    brand: 'bg-brand-50 text-brand-700 ring-brand-200',
};

export function Badge({ tone = 'neutral', children, className }: { tone?: Tone; children: ReactNode; className?: string }) {
    return (
        <span className={cx('inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset', badgeTones[tone], className)}>
            {children}
        </span>
    );
}

export function StatusBadge({ active }: { active: boolean }) {
    return <Badge tone={active ? 'success' : 'neutral'}>{active ? 'Active' : 'Inactive'}</Badge>;
}

const alertTones = {
    success: { box: 'border-emerald-200 bg-emerald-50 text-emerald-800', Icon: CheckCircle2 },
    danger: { box: 'border-red-200 bg-red-50 text-red-800', Icon: AlertCircle },
    warning: { box: 'border-amber-200 bg-amber-50 text-amber-900', Icon: AlertCircle },
    info: { box: 'border-sky-200 bg-sky-50 text-sky-800', Icon: Info },
};

export function Alert({ tone = 'info', children, className }: { tone?: keyof typeof alertTones; children: ReactNode; className?: string }) {
    const { box, Icon } = alertTones[tone];
    return (
        <div role={tone === 'danger' ? 'alert' : 'status'} className={cx('flex gap-3 rounded-md border px-4 py-3 text-sm', box, className)}>
            <Icon className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            <div>{children}</div>
        </div>
    );
}

export function EmptyState({ title, description, action, icon }: { title: string; description?: string; action?: ReactNode; icon?: ReactNode }) {
    return (
        <div className="flex flex-col items-center justify-center px-6 py-14 text-center">
            <div className="mb-3 rounded-full bg-slate-100 p-3 text-slate-400">{icon ?? <Inbox className="h-6 w-6" aria-hidden="true" />}</div>
            <h3 className="text-sm font-semibold text-slate-900">{title}</h3>
            {description && <p className="mt-1 max-w-sm text-sm text-slate-500">{description}</p>}
            {action && <div className="mt-4">{action}</div>}
        </div>
    );
}

export function PageHeader({ title, description, actions, breadcrumb }: { title: string; description?: ReactNode; actions?: ReactNode; breadcrumb?: ReactNode }) {
    return (
        <div className="mb-6">
            {breadcrumb && <div className="mb-2 text-sm text-slate-500">{breadcrumb}</div>}
            <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div className="min-w-0">
                    <h1 className="truncate text-2xl font-semibold tracking-tight text-slate-900">{title}</h1>
                    {description && <p className="mt-1 text-sm text-slate-500">{description}</p>}
                </div>
                {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
            </div>
        </div>
    );
}
