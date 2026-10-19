import { EmptyState } from '@/components/ui/Feedback';
import { formatDateTime } from '@/lib/format';
import type { ActivityLog } from '@/types';

export function actionTone(action: string): string {
    if (/failed|blocked|deactivated|deleted/.test(action)) return 'bg-red-500';
    if (/created|activated|login$/.test(action)) return 'bg-emerald-500';
    if (/permission|role/.test(action)) return 'bg-brand-500';
    return 'bg-slate-400';
}

export function ActivityFeed({ logs }: { logs: ActivityLog[] }) {
    if (logs.length === 0) return <EmptyState title="No activity yet" description="Administrative actions will appear here." />;

    return (
        <ul className="divide-y divide-slate-100">
            {logs.map((log) => (
                <li key={log.id} className="flex items-start gap-3 px-5 py-3">
                    <span className={`mt-1.5 h-2 w-2 shrink-0 rounded-full ${actionTone(log.action)}`} aria-hidden="true" />
                    <div className="min-w-0 flex-1">
                        <p className="text-sm text-slate-800">
                            <span className="font-medium">{log.actor?.full_name ?? 'System'}</span>{' '}
                            <span className="text-slate-600">{log.description ?? log.action}</span>
                        </p>
                        <p className="mt-0.5 text-xs text-slate-500">
                            <code className="rounded bg-slate-100 px-1 py-0.5 text-[11px]">{log.action}</code> · {formatDateTime(log.created_at)}
                        </p>
                    </div>
                </li>
            ))}
        </ul>
    );
}
