import { useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ClipboardList, Download, Plus, Save } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useApi } from '@/hooks/useApi';
import { Card, CardBody, CardHeader, DescriptionList } from '@/components/ui/Card';
import { Alert, EmptyState, PageHeader } from '@/components/ui/Feedback';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { Pagination, Table, Td, Th } from '@/components/ui/Table';
import { LoadingBlock } from '@/components/ui/Spinner';
import { TextareaInput, Toggle } from '@/components/ui/Form';
import { useToast } from '@/components/ui/Toast';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { cx, formatDateTime, humanize } from '@/lib/format';
import type { PaginationMeta } from '@/types';
import { filterInputClass, useListParams } from '@/pages/structure/components/ListControls';
import { ItemPicker, downloadFile, type ItemOption } from '@/pages/master-data/components/shared';
import type { CodedRef, UomRef } from '@/pages/master-data/types';
import { DocumentStatusBadge, FieldShell, LocationSelect, Qty, QuantityInput, WarehouseSelect, statusLabel, useLocations } from './components';
import type { LocationRef, UserRef } from './types';

interface CountSession {
    id: number;
    count_number: string;
    warehouse: CodedRef | null;
    location_ids: number[] | null;
    status: string;
    lock_locations: boolean;
    cutoff_at: string | null;
    remarks: string | null;
    line_count: number | null;
    created_by: UserRef | null;
    approved_by: UserRef | null;
    posted_at: string | null;
    created_at: string;
    allowed_actions: string[];
    lines?: CountLine[];
}

interface CountLine {
    id: number;
    item: CodedRef | null;
    location: LocationRef | null;
    stock_status: string;
    uom: UomRef | null;
    system_quantity: string;
    counted_quantity: string | null;
    variance: string | null;
    remarks: string | null;
    counted_by: UserRef | null;
}

export function StockCountListPage() {
    const { can } = useAuth();
    const navigate = useNavigate();
    const list = useListParams({ sort: 'created_at', direction: 'desc' });
    const { data, loading, error } = useApi<{ data: CountSession[]; meta: PaginationMeta }>('/inventory/stock-counts', { status: list.get('status'), page: list.page, per_page: 15 });
    const [creating, setCreating] = useState(false);

    return (
        <>
            <PageHeader title="Stock Counting" description="Physical counts with a cutoff snapshot. Counted locations are locked against other postings until the count is posted or cancelled."
                actions={can('inventory.counts.create') && <Button icon={<Plus className="h-4 w-4" />} onClick={() => setCreating(true)}>New count</Button>} />
            <Card>
                <div className="border-b border-slate-200 p-4">
                    <select aria-label="Filter by status" value={list.get('status')} onChange={(e) => list.update({ status: e.target.value })} className={`${filterInputClass} max-w-xs`}>
                        <option value="">All statuses</option>
                        {['draft', 'counting', 'submitted', 'approved', 'posted', 'cancelled'].map((s) => <option key={s} value={s}>{humanize(s)}</option>)}
                    </select>
                </div>
                {error ? <div className="p-4"><Alert tone="danger">{error}</Alert></div> : loading && !data ? <LoadingBlock /> : data && data.data.length === 0 ? (
                    <EmptyState icon={<ClipboardList className="h-6 w-6" />} title="No stock counts yet" />
                ) : data ? (
                    <>
                        <Table>
                            <thead><tr><Th>Count</Th><Th>Warehouse</Th><Th className="hidden md:table-cell">Lines</Th><Th>Status</Th><Th className="hidden md:table-cell">Cutoff</Th><Th className="hidden lg:table-cell">Created by</Th></tr></thead>
                            <tbody className="divide-y divide-slate-100 bg-white">
                                {data.data.map((s) => (
                                    <tr key={s.id} className="hover:bg-slate-50">
                                        <Td><Link to={`/inventory/stock-counts/${s.id}`} className="font-mono text-xs font-semibold text-slate-900 hover:text-brand-700">{s.count_number}</Link></Td>
                                        <Td className="text-xs">{s.warehouse?.code} — {s.warehouse?.name}{s.location_ids && <span className="block text-slate-500">{s.location_ids.length} location(s)</span>}</Td>
                                        <Td className="hidden tabular-nums md:table-cell">{s.line_count}</Td>
                                        <Td><DocumentStatusBadge status={s.status} /></Td>
                                        <Td className="hidden text-xs md:table-cell">{formatDateTime(s.cutoff_at)}</Td>
                                        <Td className="hidden text-xs lg:table-cell">{s.created_by?.full_name}</Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                        <Pagination meta={data.meta} onPage={(p) => list.update({ page: String(p) })} />
                    </>
                ) : null}
            </Card>
            {creating && <CreateCount onClose={() => setCreating(false)} onCreated={(id) => navigate(`/inventory/stock-counts/${id}`)} />}
        </>
    );
}

function CreateCount({ onClose, onCreated }: { onClose: () => void; onCreated: (id: number) => void }) {
    const [warehouseId, setWarehouseId] = useState('');
    const [locationIds, setLocationIds] = useState<number[]>([]);
    const [lock, setLock] = useState(true);
    const [remarks, setRemarks] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const locations = useLocations(warehouseId ? { warehouse_id: warehouseId } : null);

    const save = async () => {
        setSaving(true);
        setError(null);
        try {
            const { data } = await api.post<{ data: CountSession }>('/inventory/stock-counts', { warehouse_id: warehouseId, location_ids: locationIds, lock_locations: lock, remarks: remarks || null });
            onCreated(data.data.id);
        } catch (err) {
            const fe = fieldErrors(err);
            setError(Object.values(fe)[0] ?? errorMessage(err));
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal open title="New stock count" onClose={onClose} footer={<><Button variant="secondary" onClick={onClose}>Cancel</Button><Button loading={saving} disabled={!warehouseId} onClick={save}>Create</Button></>}>
            <div className="space-y-4">
                {error && <Alert tone="danger">{error}</Alert>}
                <WarehouseSelect required value={warehouseId} onChange={(v) => { setWarehouseId(v); setLocationIds([]); }} />
                {warehouseId && (
                    <FieldShell label="Locations" hint="Leave all unticked to count the whole warehouse.">
                        <div className="max-h-48 space-y-1 overflow-auto rounded-md border border-slate-200 p-2">
                            {locations.data?.data.map((l) => (
                                <label key={l.id} className="flex items-center gap-2 text-sm">
                                    <input type="checkbox" checked={locationIds.includes(l.id)} onChange={(e) => setLocationIds((ids) => (e.target.checked ? [...ids, l.id] : ids.filter((x) => x !== l.id)))} />
                                    {l.code} — {l.name}
                                </label>
                            ))}
                        </div>
                    </FieldShell>
                )}
                <Toggle checked={lock} onChange={setLock} label="Lock counted locations" description="Recommended. Blocks other postings in these locations from start until the count is posted or cancelled." />
                <TextareaInput label="Remarks" rows={2} value={remarks} onChange={(e) => setRemarks(e.target.value)} />
            </div>
        </Modal>
    );
}

export function StockCountDetailsPage() {
    const { id } = useParams();
    const { can } = useAuth();
    const toast = useToast();
    const { data, loading, error, reload } = useApi<{ data: CountSession }>(`/inventory/stock-counts/${id}`);
    const [entries, setEntries] = useState<Record<number, string>>({});
    const [filter, setFilter] = useState('');
    const [busy, setBusy] = useState<string | null>(null);
    const [actionError, setActionError] = useState<string | null>(null);
    const [found, setFound] = useState(false);
    const session = data?.data;

    const lines = useMemo(() => {
        const all = session?.lines ?? [];
        const term = filter.trim().toLowerCase();
        return term ? all.filter((l) => `${l.item?.code} ${l.item?.name} ${l.location?.code}`.toLowerCase().includes(term)) : all;
    }, [session, filter]);

    if (loading && !session) return <LoadingBlock />;
    if (error || !session) return <Alert tone="danger">{error ?? 'Not found.'}</Alert>;
    const counting = session.status === 'counting' && session.allowed_actions.includes('record');
    const dirty = Object.keys(entries).length > 0;

    const saveCounts = async () => {
        setBusy('save');
        setActionError(null);
        try {
            await api.put(`/inventory/stock-counts/${session.id}/counts`, { entries: Object.entries(entries).map(([lineId, q]) => ({ line_id: Number(lineId), counted_quantity: q })) });
            toast.success('Counts saved.');
            setEntries({});
            reload();
        } catch (err) {
            const fe = fieldErrors(err);
            setActionError(Object.values(fe)[0] ?? errorMessage(err));
        } finally {
            setBusy(null);
        }
    };

    const act = async (action: string) => {
        setBusy(action);
        setActionError(null);
        try {
            await api.post(`/inventory/stock-counts/${session.id}/${action}`);
            toast.success(`Count ${humanize(action).toLowerCase()} done.`);
            reload();
        } catch (err) {
            const fe = fieldErrors(err);
            setActionError(Object.values(fe)[0] ?? errorMessage(err));
        } finally {
            setBusy(null);
        }
    };

    const counted = (session.lines ?? []).filter((l) => l.counted_quantity !== null).length;
    const variances = (session.lines ?? []).filter((l) => l.variance && l.variance !== '0').length;

    return (
        <>
            <PageHeader title={`Stock count ${session.count_number}`} breadcrumb={<Link to="/inventory/stock-counts" className="hover:underline">Stock Counting</Link>}
                actions={
                    <div className="flex flex-wrap gap-2">
                        {can('inventory.reports.export') && (
                            <Button variant="secondary" icon={<Download className="h-4 w-4" />} onClick={() => downloadFile(`/inventory/stock-counts/${session.id}`, { export: 'xlsx' }, `${session.count_number}.xlsx`)}>Reconciliation</Button>
                        )}
                        {session.allowed_actions.filter((a) => a !== 'record').map((a) => (
                            <Button key={a} variant={a === 'cancel' ? 'danger' : 'primary'} loading={busy === a} disabled={dirty && a !== 'cancel'} onClick={() => act(a)}>
                                {{ start: 'Start count (snapshot)', submit: 'Submit count', approve: 'Approve variances', post: 'Post reconciliation', cancel: 'Cancel count' }[a] ?? humanize(a)}
                            </Button>
                        ))}
                    </div>
                } />
            {actionError && <Alert tone="danger" className="mb-4">{actionError}</Alert>}
            {dirty && <Alert tone="warning" className="mb-4">You have unsaved counts. Save them before submitting.</Alert>}

            <Card className="mb-4">
                <CardBody>
                    <DescriptionList items={[
                        { label: 'Status', value: <DocumentStatusBadge status={session.status} /> },
                        { label: 'Warehouse', value: `${session.warehouse?.code} — ${session.warehouse?.name}` },
                        { label: 'Cutoff (snapshot)', value: formatDateTime(session.cutoff_at, 'Not started') },
                        { label: 'Location lock', value: session.lock_locations ? 'Locked while counting' : 'Not locked (variance vs. snapshot)' },
                        { label: 'Progress', value: `${counted} of ${session.lines?.length ?? 0} counted · ${variances} variance(s)` },
                        ...(session.remarks ? [{ label: 'Remarks', value: session.remarks }] : []),
                    ]} />
                </CardBody>
            </Card>

            {session.status === 'draft' ? (
                <Alert tone="info">Start the count to take the system snapshot{session.lock_locations ? ' and lock the locations' : ''}. Then enter the physically counted quantities.</Alert>
            ) : (
                <Card>
                    <CardHeader title={counting ? 'Enter counted quantities' : 'Count lines'} description={counting ? 'Quantities are in each item’s base unit. Enter 0 when an item is not found.' : undefined}
                        actions={counting && (
                            <div className="flex gap-2">
                                <Button variant="secondary" size="sm" onClick={() => setFound(true)}>Add found item</Button>
                                <Button size="sm" icon={<Save className="h-4 w-4" />} loading={busy === 'save'} disabled={!dirty} onClick={saveCounts}>Save counts</Button>
                            </div>
                        )} />
                    <div className="border-b border-slate-200 p-4">
                        <input type="search" aria-label="Find line" value={filter} onChange={(e) => setFilter(e.target.value)} placeholder="Find item or location" className={filterInputClass} />
                    </div>
                    {/* Card layout: large touch targets on phones/tablets. */}
                    <ul className="divide-y divide-slate-100">
                        {lines.map((l) => {
                            const value = entries[l.id] ?? l.counted_quantity ?? '';
                            const variance = value !== '' ? (Number(value) - Number(l.system_quantity)) : null;
                            return (
                                <li key={l.id} className="grid grid-cols-1 gap-3 p-4 sm:grid-cols-[1fr_auto] sm:items-center">
                                    <div>
                                        <p className="text-sm"><span className="font-mono text-xs font-semibold">{l.item?.code}</span> <span className="text-slate-700">{l.item?.name}</span></p>
                                        <p className="text-xs text-slate-500">{l.location?.code} · {statusLabel(l.stock_status)} · system <Qty value={l.system_quantity} uom={l.uom?.code} />{l.counted_by && ` · counted by ${l.counted_by.full_name}`}</p>
                                    </div>
                                    <div className="flex items-center gap-3">
                                        {counting ? (
                                            <div className="w-36"><QuantityInput large label={`Counted ${l.item?.code} at ${l.location?.code}`} value={value} onChange={(v) => setEntries((e) => ({ ...e, [l.id]: v }))} /></div>
                                        ) : <span className="w-28 text-right font-medium"><Qty value={l.counted_quantity} uom={l.uom?.code} /></span>}
                                        <span className={cx('w-24 text-right text-sm tabular-nums', variance === null ? 'text-slate-400' : variance === 0 ? 'text-emerald-700' : 'font-semibold text-amber-700')}>
                                            {variance === null ? '—' : counting && entries[l.id] !== undefined ? `${variance > 0 ? '+' : ''}${variance}` : l.variance ?? '—'}
                                        </span>
                                    </div>
                                </li>
                            );
                        })}
                        {lines.length === 0 && <li className="p-4 text-sm text-slate-500">No lines. {counting && 'Use “Add found item” for stock that is physically present.'}</li>}
                    </ul>
                </Card>
            )}
            {found && <FoundItem session={session} onClose={() => setFound(false)} onSaved={() => { setFound(false); reload(); }} />}
        </>
    );
}

function FoundItem({ session, onClose, onSaved }: { session: CountSession; onClose: () => void; onSaved: () => void }) {
    const [item, setItem] = useState<ItemOption | null>(null);
    const [locationId, setLocationId] = useState('');
    const [qty, setQty] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    const save = async () => {
        setSaving(true);
        setError(null);
        try {
            await api.put(`/inventory/stock-counts/${session.id}/counts`, { entries: [{ item_id: item?.id, location_id: locationId, counted_quantity: qty, stock_status: 'available' }] });
            onSaved();
        } catch (err) {
            const fe = fieldErrors(err);
            setError(Object.values(fe)[0] ?? errorMessage(err));
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal open title="Add found item" onClose={onClose} footer={<><Button variant="secondary" onClick={onClose}>Cancel</Button><Button loading={saving} onClick={save}>Add</Button></>}>
            <div className="space-y-4">
                {error && <Alert tone="danger">{error}</Alert>}
                <p className="text-sm text-slate-600">For stock physically present but not in the system snapshot (system quantity 0).</p>
                <ItemPicker label="Item" value={item} onChange={setItem} />
                <LocationSelect required warehouseId={session.warehouse?.id} value={locationId} onChange={setLocationId} />
                <FieldShell label="Counted quantity (base unit)" required><QuantityInput large label="Counted quantity" value={qty} onChange={setQty} /></FieldShell>
            </div>
        </Modal>
    );
}
