import { Fragment, useState } from 'react';
import { ChevronDown, ChevronRight, History, Search } from 'lucide-react';
import { useApi, useDebouncedValue } from '@/hooks/useApi';
import { Card } from '@/components/ui/Card';
import { Alert, EmptyState, PageHeader } from '@/components/ui/Feedback';
import { Button } from '@/components/ui/Button';
import { Pagination, Table, Td, Th } from '@/components/ui/Table';
import { LoadingBlock } from '@/components/ui/Spinner';
import { formatDateTime } from '@/lib/format';
import type { ActivityLog, Paginated } from '@/types';
import { actionTone } from './ActivityFeed';

const inputClass = 'h-10 w-full rounded-md border border-slate-300 bg-white px-3 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-100';

export default function ActivityLogPage() {
    const [search, setSearch] = useState('');
    const [action, setAction] = useState('');
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const [page, setPage] = useState(1);
    const [expanded, setExpanded] = useState<number | null>(null);
    const debounced = useDebouncedValue(search);

    const { data, loading, error } = useApi<Paginated<ActivityLog> & { meta: { actions: string[] } }>('/activity-logs', {
        search: debounced, action, from, to, page, per_page: 25,
    });

    const reset = () => {
        setSearch('');
        setAction('');
        setFrom('');
        setTo('');
        setPage(1);
    };
    const filtered = Boolean(debounced || action || from || to);

    return (
        <>
            <PageHeader title="Activity Logs" description="Read-only audit trail of sign-ins and administrative changes." />
            <Card>
                <div className="grid grid-cols-1 gap-3 border-b border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-[1fr_220px_160px_160px_auto]">
                    <label className="relative block">
                        <span className="sr-only">Search</span>
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden="true" />
                        <input type="search" value={search} onChange={(e) => { setSearch(e.target.value); setPage(1); }} placeholder="Search description or action" className={`${inputClass} pl-9`} />
                    </label>
                    <select aria-label="Filter by action" value={action} onChange={(e) => { setAction(e.target.value); setPage(1); }} className={inputClass}>
                        <option value="">All actions</option>
                        {data?.meta.actions?.map((a) => <option key={a} value={a}>{a}</option>)}
                    </select>
                    <input type="date" aria-label="From date" value={from} max={to || undefined} onChange={(e) => { setFrom(e.target.value); setPage(1); }} className={inputClass} />
                    <input type="date" aria-label="To date" value={to} min={from || undefined} onChange={(e) => { setTo(e.target.value); setPage(1); }} className={inputClass} />
                    <Button variant="ghost" onClick={reset} disabled={!filtered}>Reset</Button>
                </div>

                {error ? (
                    <div className="p-4"><Alert tone="danger">{error}</Alert></div>
                ) : loading && !data ? (
                    <LoadingBlock />
                ) : data && data.data.length === 0 ? (
                    <EmptyState icon={<History className="h-6 w-6" />} title={filtered ? 'No matching activity' : 'No activity recorded yet'} />
                ) : data ? (
                    <div className={loading ? 'opacity-60' : undefined}>
                        <Table>
                            <thead>
                                <tr>
                                    <Th className="w-8"><span className="sr-only">Expand</span></Th>
                                    <Th>When</Th>
                                    <Th>Actor</Th>
                                    <Th>Action</Th>
                                    <Th className="hidden md:table-cell">Description</Th>
                                    <Th className="hidden lg:table-cell">IP address</Th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 bg-white">
                                {data.data.map((log) => {
                                    const open = expanded === log.id;
                                    return (
                                        <Fragment key={log.id}>
                                            <tr className="cursor-pointer hover:bg-slate-50" onClick={() => setExpanded(open ? null : log.id)}>
                                                <Td>
                                                    <button type="button" aria-expanded={open} aria-label="Toggle details" className="text-slate-400">
                                                        {open ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
                                                    </button>
                                                </Td>
                                                <Td className="whitespace-nowrap text-xs text-slate-500">{formatDateTime(log.created_at)}</Td>
                                                <Td className="whitespace-nowrap">{log.actor?.full_name ?? <span className="text-slate-400">System / guest</span>}</Td>
                                                <Td>
                                                    <span className="inline-flex items-center gap-1.5 whitespace-nowrap font-mono text-xs">
                                                        <span className={`h-1.5 w-1.5 rounded-full ${actionTone(log.action)}`} aria-hidden="true" />
                                                        {log.action}
                                                    </span>
                                                </Td>
                                                <Td className="hidden md:table-cell">{log.description ?? '—'}</Td>
                                                <Td className="hidden font-mono text-xs lg:table-cell">{log.ip_address ?? '—'}</Td>
                                            </tr>
                                            {open && (
                                                <tr className="bg-slate-50">
                                                    <td colSpan={6} className="px-4 py-4">
                                                        <dl className="grid grid-cols-1 gap-4 text-xs sm:grid-cols-2">
                                                            <div className="md:hidden">
                                                                <dt className="font-semibold text-slate-500">Description</dt>
                                                                <dd className="mt-1 text-slate-800">{log.description ?? '—'}</dd>
                                                            </div>
                                                            <div>
                                                                <dt className="font-semibold text-slate-500">Entity</dt>
                                                                <dd className="mt-1 font-mono text-slate-800">{log.entity_type ? `${log.entity_type} #${log.entity_id}` : '—'}</dd>
                                                            </div>
                                                            <div>
                                                                <dt className="font-semibold text-slate-500">User agent</dt>
                                                                <dd className="mt-1 break-all text-slate-800">{log.user_agent || '—'}</dd>
                                                            </div>
                                                            <div className="sm:col-span-2">
                                                                <dt className="font-semibold text-slate-500">Details</dt>
                                                                <dd className="mt-1">
                                                                    {log.metadata ? (
                                                                        <pre className="max-h-64 overflow-auto rounded-md border border-slate-200 bg-white p-3 text-[11px] leading-relaxed text-slate-700">{JSON.stringify(log.metadata, null, 2)}</pre>
                                                                    ) : (
                                                                        <span className="text-slate-400">No additional details.</span>
                                                                    )}
                                                                </dd>
                                                            </div>
                                                        </dl>
                                                    </td>
                                                </tr>
                                            )}
                                        </Fragment>
                                    );
                                })}
                            </tbody>
                        </Table>
                        <Pagination meta={data.meta} onPage={setPage} />
                    </div>
                ) : null}
            </Card>
        </>
    );
}
