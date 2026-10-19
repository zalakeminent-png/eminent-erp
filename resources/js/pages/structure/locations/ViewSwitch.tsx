import { Link } from 'react-router-dom';
import { List, Network } from 'lucide-react';
import { cx } from '@/lib/format';

export function ViewSwitch({ current }: { current: 'table' | 'tree' }) {
    const item = (view: 'table' | 'tree', to: string, label: string, Icon: typeof List) => (
        <Link
            to={to}
            aria-current={current === view ? 'page' : undefined}
            className={cx('inline-flex h-10 items-center gap-1.5 px-3 text-sm font-medium', current === view ? 'bg-brand-50 text-brand-700' : 'bg-white text-slate-600 hover:bg-slate-50')}
        >
            <Icon className="h-4 w-4" aria-hidden="true" /> {label}
        </Link>
    );
    return (
        <div className="inline-flex overflow-hidden rounded-md border border-slate-300 shadow-sm">
            {item('table', '/factory-setup/locations', 'Table', List)}
            {item('tree', '/factory-setup/locations/tree', 'Tree', Network)}
        </div>
    );
}
