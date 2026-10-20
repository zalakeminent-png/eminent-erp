import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { CheckCircle2, Copy, Eye, FileText, Pencil, Plus, Power, Trash2 } from 'lucide-react';
import axios from 'axios';
import { useAuth } from '@/auth/AuthContext';
import { useApi, useDebouncedValue } from '@/hooks/useApi';
import { Card } from '@/components/ui/Card';
import { Alert, EmptyState, PageHeader } from '@/components/ui/Feedback';
import { Button, ButtonLink } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/Modal';
import { Pagination, SortableTh, Table, Td, Th } from '@/components/ui/Table';
import { LoadingBlock } from '@/components/ui/Spinner';
import { useToast } from '@/components/ui/Toast';
import { api, errorMessage } from '@/lib/api';
import { formatDate } from '@/lib/format';
import type { Paginated } from '@/types';
import { FilterBar, SearchInput, filterInputClass, useListParams } from '@/pages/structure/components/ListControls';
import { DocStatusBadge } from '../components/shared';
import type { VersionedDoc } from '../types';

export interface DocKind {
    /** API path, e.g. "/boms". */
    endpoint: string;
    /** UI base route, e.g. "/master-data/boms". */
    base: string;
    /** Permission module, e.g. "boms". */
    module: string;
    title: string;
    noun: string;
    description: string;
    versionLabel?: string;
}

export const BOM_KIND: DocKind = { endpoint: '/boms', base: '/master-data/boms', module: 'boms', title: 'Bills of Materials', noun: 'BOM', description: 'Planned material requirements per product. Approved versions are frozen; changes need a new revision.' };
export const ROUTING_KIND: DocKind = { endpoint: '/product-routings', base: '/master-data/routings', module: 'routings', title: 'Product Routings', noun: 'routing', description: 'Manufacturing operation sequences. No production jobs are created here.' };
export const QC_KIND: DocKind = { endpoint: '/qc-specifications', base: '/master-data/qc-specifications', module: 'qc-specifications', title: 'QC Specifications', noun: 'QC specification', description: 'Inspection parameters and acceptance limits per item. Inspections are recorded in the Quality Control module.', versionLabel: 'Rev' };

/** All validation messages, including the list under "approval". */
export function allErrors(error: unknown): string[] {
    if (axios.isAxiosError(error) && error.response?.status === 422) {
        const errs = (error.response.data as { errors?: Record<string, string[]> })?.errors ?? {};
        return Object.values(errs).flat();
    }
    return [errorMessage(error)];
}

export function DocumentListPage({ kind, extraColumns }: { kind: DocKind; extraColumns?: { label: string; render: (d: VersionedDoc & Record<string, unknown>) => React.ReactNode }[] }) {
    const { can } = useAuth();
    const list = useListParams({ sort: 'updated_at', direction: 'desc' });
    const [search, setSearch] = useState(list.get('search'));
    const debounced = useDebouncedValue(search);
    const { data, loading, error } = useApi<Paginated<VersionedDoc & Record<string, unknown>>>(kind.endpoint, {
        search: debounced, status: list.get('status'), sort: list.sort, direction: list.direction, page: list.page, per_page: 15,
    });
    const v = kind.versionLabel ?? 'v';

    return (
        <>
            <PageHeader title={kind.title} description={kind.description}
                actions={can(`${kind.module}.create`) && <ButtonLink to={`${kind.base}/new`} icon={<Plus className="h-4 w-4" />}>New {kind.noun}</ButtonLink>} />
            <Card>
                <FilterBar>
                    <SearchInput value={search} onChange={(val) => { setSearch(val); list.update({ search: val }); }} placeholder="Search number, item code or name" />
                    <select aria-label="Filter by status" value={list.get('status')} onChange={(e) => list.update({ status: e.target.value })} className={filterInputClass}>
                        <option value="">All statuses</option>
                        <option value="draft">Draft</option>
                        <option value="approved">Approved</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </FilterBar>
                {error ? <div className="p-4"><Alert tone="danger">{error}</Alert></div> : loading && !data ? <LoadingBlock /> : data && data.data.length === 0 ? (
                    <EmptyState icon={<FileText className="h-6 w-6" />} title={list.hasFilters ? `No ${kind.noun}s match your filters` : `No ${kind.noun}s yet`} />
                ) : data ? (
                    <div className={loading ? 'opacity-60' : undefined}>
                        <Table>
                            <thead>
                                <tr>
                                    <SortableTh label="Number" field={kind.endpoint === '/qc-specifications' ? 'spec_number' : kind.endpoint === '/boms' ? 'bom_number' : 'routing_number'} sort={list.sort} direction={list.direction} onSort={list.onSort} />
                                    <Th>Item</Th>
                                    <Th>{v === 'Rev' ? 'Revision' : 'Version'}</Th>
                                    <SortableTh label="Status" field="status" sort={list.sort} direction={list.direction} onSort={list.onSort} />
                                    <SortableTh label="Effective" field="effective_from" sort={list.sort} direction={list.direction} onSort={list.onSort} className="hidden md:table-cell" />
                                    {extraColumns?.map((c) => <Th key={c.label} className="hidden lg:table-cell">{c.label}</Th>)}
                                    <Th className="text-right"><span className="sr-only">Actions</span></Th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 bg-white">
                                {data.data.map((d) => (
                                    <tr key={d.id} className="hover:bg-slate-50">
                                        <Td><Link to={`${kind.base}/${d.id}`} className="font-mono text-xs font-semibold text-slate-900 hover:text-brand-700">{d.number}</Link></Td>
                                        <Td><span className="font-mono text-xs">{d.item?.code}</span><span className="block text-xs text-slate-500">{d.item?.name}</span></Td>
                                        <Td className="tabular-nums">{v === 'Rev' ? `Rev ${d.version}` : `v${d.version}`}</Td>
                                        <Td><DocStatusBadge status={d.status} /></Td>
                                        <Td className="hidden whitespace-nowrap text-xs md:table-cell">{formatDate(d.effective_from)} – {d.effective_to ? formatDate(d.effective_to) : 'open'}</Td>
                                        {extraColumns?.map((c) => <Td key={c.label} className="hidden lg:table-cell">{c.render(d)}</Td>)}
                                        <Td className="whitespace-nowrap text-right">
                                            <ButtonLink to={`${kind.base}/${d.id}`} variant="ghost" size="sm" aria-label={`View ${d.number}`} icon={<Eye className="h-4 w-4" />} />
                                            {d.is_editable && can(`${kind.module}.update`) && <ButtonLink to={`${kind.base}/${d.id}/edit`} variant="ghost" size="sm" aria-label={`Edit ${d.number}`} icon={<Pencil className="h-4 w-4" />} />}
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                        <Pagination meta={data.meta} onPage={(p) => list.update({ page: String(p) })} />
                    </div>
                ) : null}
            </Card>
        </>
    );
}

/** Approve / revise / activate-deactivate / delete-draft actions. */
export function DocumentActions({ kind, doc, onChanged }: { kind: DocKind; doc: VersionedDoc; onChanged: () => void }) {
    const { can } = useAuth();
    const toast = useToast();
    const navigate = useNavigate();
    const [pending, setPending] = useState<'approve' | 'revise' | 'status' | 'delete' | null>(null);
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<string[]>([]);

    const run = async () => {
        setBusy(true);
        setErrors([]);
        try {
            if (pending === 'approve') {
                await api.post(`${kind.endpoint}/${doc.id}/approve`);
                toast.success(`${kind.noun} ${doc.number} approved.`);
                onChanged();
            } else if (pending === 'revise') {
                const { data } = await api.post<{ data: VersionedDoc }>(`${kind.endpoint}/${doc.id}/revise`);
                toast.success(`Draft ${data.data.number} created.`);
                navigate(`${kind.base}/${data.data.id}/edit`);
            } else if (pending === 'status') {
                await api.patch(`${kind.endpoint}/${doc.id}/status`, { is_active: doc.status !== 'approved' });
                toast.success(doc.status === 'approved' ? 'Deactivated.' : 'Reactivated.');
                onChanged();
            } else if (pending === 'delete') {
                await api.delete(`${kind.endpoint}/${doc.id}`);
                toast.success('Draft deleted.');
                navigate(kind.base);
            }
            setPending(null);
        } catch (err) {
            setErrors(allErrors(err));
            setPending(null);
        } finally {
            setBusy(false);
        }
    };

    const messages: Record<string, { title: string; label: string; tone: 'primary' | 'danger'; text: string }> = {
        approve: { title: `Approve ${kind.noun}`, label: 'Approve', tone: 'primary', text: `Approving freezes ${doc.number} permanently. Later changes require a new revision.` },
        revise: { title: 'Create revision', label: 'Create revision', tone: 'primary', text: `A new draft version will be created from ${doc.number}. The current version stays unchanged in history.` },
        status: { title: doc.status === 'approved' ? 'Deactivate version' : 'Reactivate version', label: doc.status === 'approved' ? 'Deactivate' : 'Reactivate', tone: doc.status === 'approved' ? 'danger' : 'primary', text: doc.status === 'approved' ? 'The version is kept for history but is no longer effective.' : 'The version becomes effective again (if no other approved version overlaps its dates).' },
        delete: { title: 'Delete draft', label: 'Delete', tone: 'danger', text: `Delete draft ${doc.number}? Approved versions are never deleted.` },
    };

    return (
        <>
            <div className="flex flex-wrap gap-2">
                {doc.is_editable && can(`${kind.module}.update`) && <ButtonLink to={`${kind.base}/${doc.id}/edit`} variant="secondary" icon={<Pencil className="h-4 w-4" />}>Edit draft</ButtonLink>}
                {doc.is_editable && can(`${kind.module}.update`) && <Button variant="ghost" icon={<Trash2 className="h-4 w-4 text-red-500" />} onClick={() => setPending('delete')}>Delete</Button>}
                {doc.is_editable && can(`${kind.module}.approve`) && <Button icon={<CheckCircle2 className="h-4 w-4" />} onClick={() => setPending('approve')}>Approve</Button>}
                {!doc.is_editable && can(`${kind.module}.approve`) && <Button variant={doc.status === 'approved' ? 'danger' : 'secondary'} icon={<Power className="h-4 w-4" />} onClick={() => setPending('status')}>{doc.status === 'approved' ? 'Deactivate' : 'Reactivate'}</Button>}
                {can(`${kind.module}.create`) && <Button variant="secondary" icon={<Copy className="h-4 w-4" />} onClick={() => setPending('revise')}>Duplicate as revision</Button>}
            </div>
            {errors.length > 0 && (
                <Alert tone="danger" className="mt-4">
                    <p className="font-medium">The action could not be completed:</p>
                    <ul className="mt-1 list-disc pl-5">{errors.map((e) => <li key={e}>{e}</li>)}</ul>
                </Alert>
            )}
            {pending && (
                <ConfirmDialog open title={messages[pending].title} confirmLabel={messages[pending].label} tone={messages[pending].tone} loading={busy}
                    onCancel={() => setPending(null)} onConfirm={run} message={messages[pending].text} />
            )}
        </>
    );
}
