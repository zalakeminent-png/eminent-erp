import { useState } from 'react';
import { Lock, Plus } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useApi, useDebouncedValue } from '@/hooks/useApi';
import { Card } from '@/components/ui/Card';
import { Alert, EmptyState, PageHeader } from '@/components/ui/Feedback';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { Pagination, Table, Td, Th } from '@/components/ui/Table';
import { LoadingBlock } from '@/components/ui/Spinner';
import { TextInput, TextareaInput } from '@/components/ui/Form';
import { useToast } from '@/components/ui/Toast';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { formatDate, humanize } from '@/lib/format';
import type { PaginationMeta } from '@/types';
import { FilterBar, SearchInput, filterInputClass, useListParams } from '@/pages/structure/components/ListControls';
import { ItemPicker, type ItemOption } from '@/pages/master-data/components/shared';
import type { CodedRef, UomRef } from '@/pages/master-data/types';
import { DocumentStatusBadge, FieldShell, LocationSelect, Qty, QuantityInput, StockAvailability, UomSelect, WarehouseSelect, requestKey } from './components';
import type { LocationRef, UserRef } from './types';

interface Reservation {
    id: number;
    reservation_number: string;
    item: CodedRef | null;
    warehouse: CodedRef | null;
    location: LocationRef | null;
    quantity: string;
    uom: UomRef | null;
    base_quantity: string;
    released_base_quantity: string;
    open_base_quantity: string;
    base_uom: UomRef | null;
    reference_type: string;
    reference_number: string | null;
    status: string;
    expires_on: string | null;
    remarks: string | null;
    reserved_by: UserRef | null;
    created_at: string;
}

const REFERENCE_TYPES = ['manual', 'sales_order', 'production_order', 'dispatch'];

export default function ReservationsPage() {
    const { can } = useAuth();
    const toast = useToast();
    const list = useListParams({ sort: 'created_at', direction: 'desc' });
    const [search, setSearch] = useState(list.get('search'));
    const debounced = useDebouncedValue(search);
    const { data, loading, error, reload } = useApi<{ data: Reservation[]; meta: PaginationMeta }>('/inventory/reservations', {
        search: debounced, status: list.get('status'), page: list.page, per_page: 15,
    });
    const [creating, setCreating] = useState(false);
    const [releasing, setReleasing] = useState<Reservation | null>(null);

    return (
        <>
            <PageHeader title="Stock Reservations" description="Logical allocation of available stock. Reserved stock stays physically in place but cannot be used by other postings."
                actions={can('inventory.reservations.create') && <Button icon={<Plus className="h-4 w-4" />} onClick={() => setCreating(true)}>New reservation</Button>} />
            <Card>
                <FilterBar>
                    <SearchInput value={search} onChange={(v) => { setSearch(v); list.update({ search: v }); }} placeholder="Search number, reference or item" />
                    <select aria-label="Filter by status" value={list.get('status')} onChange={(e) => list.update({ status: e.target.value })} className={filterInputClass}>
                        <option value="">All statuses</option>
                        {['active', 'partially_released', 'released', 'cancelled', 'expired'].map((s) => <option key={s} value={s}>{humanize(s)}</option>)}
                    </select>
                </FilterBar>
                {error ? <div className="p-4"><Alert tone="danger">{error}</Alert></div> : loading && !data ? <LoadingBlock /> : data && data.data.length === 0 ? (
                    <EmptyState icon={<Lock className="h-6 w-6" />} title={list.hasFilters ? 'No reservations match' : 'No reservations yet'} />
                ) : data ? (
                    <div className={loading ? 'opacity-60' : undefined}>
                        <Table>
                            <thead><tr><Th>Number</Th><Th>Item</Th><Th className="hidden md:table-cell">Where</Th><Th className="text-right">Open</Th><Th className="hidden lg:table-cell">Reference</Th><Th>Status</Th><Th className="hidden lg:table-cell">Expires</Th><Th className="text-right"><span className="sr-only">Actions</span></Th></tr></thead>
                            <tbody className="divide-y divide-slate-100 bg-white">
                                {data.data.map((r) => (
                                    <tr key={r.id}>
                                        <Td className="font-mono text-xs font-semibold">{r.reservation_number}</Td>
                                        <Td className="text-xs"><span className="font-mono font-semibold">{r.item?.code}</span><span className="block text-slate-500">{r.item?.name}</span></Td>
                                        <Td className="hidden text-xs md:table-cell">{r.warehouse?.code}{r.location ? `/${r.location.code}` : ' (any location)'}</Td>
                                        <Td className="text-right"><Qty value={r.open_base_quantity} uom={r.base_uom?.code} /><span className="block text-xs text-slate-500">of {r.base_quantity}</span></Td>
                                        <Td className="hidden text-xs lg:table-cell">{humanize(r.reference_type)} {r.reference_number}</Td>
                                        <Td><DocumentStatusBadge status={r.status} /></Td>
                                        <Td className="hidden text-xs lg:table-cell">{formatDate(r.expires_on)}</Td>
                                        <Td className="text-right">
                                            {can('inventory.reservations.release') && ['active', 'partially_released'].includes(r.status) && (
                                                <Button variant="secondary" size="sm" onClick={() => setReleasing(r)}>Release</Button>
                                            )}
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                        <Pagination meta={data.meta} onPage={(p) => list.update({ page: String(p) })} />
                    </div>
                ) : null}
            </Card>
            {creating && <CreateReservation onClose={() => setCreating(false)} onSaved={(n) => { setCreating(false); toast.success(`Reservation ${n} created.`); reload(); }} />}
            {releasing && <ReleaseReservation reservation={releasing} onClose={() => setReleasing(null)} onSaved={() => { setReleasing(null); toast.success('Reservation updated.'); reload(); }} />}
        </>
    );
}

function CreateReservation({ onClose, onSaved }: { onClose: () => void; onSaved: (number: string) => void }) {
    const [item, setItem] = useState<ItemOption | null>(null);
    const [form, setForm] = useState({ warehouse_id: '', location_id: '', quantity: '', uom_id: '', reference_type: 'manual', reference_number: '', expires_on: '', remarks: '' });
    const [key] = useState(requestKey());
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [message, setMessage] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const set = (k: keyof typeof form, v: string) => setForm((f) => ({ ...f, [k]: v }));

    const save = async () => {
        setSaving(true);
        setErrors({});
        setMessage(null);
        try {
            const { data } = await api.post<{ data: Reservation }>('/inventory/reservations', {
                ...form, item_id: item?.id, uom_id: form.uom_id || item?.base_uom?.id, location_id: form.location_id || null, expires_on: form.expires_on || null, idempotency_key: key,
            });
            onSaved(data.data.reservation_number);
        } catch (err) {
            const fe = fieldErrors(err);
            setErrors(fe);
            setMessage(Object.keys(fe).length ? null : errorMessage(err));
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal open title="New reservation" onClose={onClose} footer={<><Button variant="secondary" onClick={onClose}>Cancel</Button><Button loading={saving} onClick={save}>Reserve</Button></>}>
            <div className="space-y-4">
                {message && <Alert tone="danger">{message}</Alert>}
                <ItemPicker label="Item" value={item} onChange={(i) => { setItem(i); set('uom_id', i?.base_uom ? String(i.base_uom.id) : ''); }} error={errors.item_id} />
                <WarehouseSelect required value={form.warehouse_id} onChange={(v) => { set('warehouse_id', v); set('location_id', ''); }} error={errors.warehouse_id} />
                <LocationSelect label="Location (optional)" placeholder="Any location in the warehouse" warehouseId={form.warehouse_id || undefined} value={form.location_id} onChange={(v) => set('location_id', v)} error={errors.location_id} />
                <div className="grid grid-cols-3 gap-3">
                    <div className="col-span-2">
                        <FieldShell label="Quantity" required error={errors.quantity}><QuantityInput large label="Quantity" value={form.quantity} onChange={(v) => set('quantity', v)} /></FieldShell>
                        {item && form.location_id && <StockAvailability itemId={item.id} locationId={form.location_id} need={form.quantity} />}
                    </div>
                    <FieldShell label="UOM" required><UomSelect value={form.uom_id} baseUomId={item?.base_uom?.id} onChange={(v) => set('uom_id', v)} error={errors.uom_id} /></FieldShell>
                </div>
                <div className="grid grid-cols-2 gap-3">
                    <FieldShell label="Reference type" required>
                        <select aria-label="Reference type" value={form.reference_type} onChange={(e) => set('reference_type', e.target.value)} className={`${filterInputClass} h-11`}>
                            {REFERENCE_TYPES.map((t) => <option key={t} value={t}>{humanize(t)}</option>)}
                        </select>
                    </FieldShell>
                    <TextInput label="Reference number" required={form.reference_type === 'manual'} value={form.reference_number} onChange={(e) => set('reference_number', e.target.value)} error={errors.reference_number} />
                </div>
                <TextInput label="Expires on (optional)" type="date" value={form.expires_on} onChange={(e) => set('expires_on', e.target.value)} error={errors.expires_on} />
                <TextareaInput label="Remarks" rows={2} value={form.remarks} onChange={(e) => set('remarks', e.target.value)} />
            </div>
        </Modal>
    );
}

function ReleaseReservation({ reservation, onClose, onSaved }: { reservation: Reservation; onClose: () => void; onSaved: () => void }) {
    const [qty, setQty] = useState('');
    const [remarks, setRemarks] = useState('');
    const [cancel, setCancel] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    const save = async () => {
        setSaving(true);
        setError(null);
        try {
            await api.post(`/inventory/reservations/${reservation.id}/release`, { base_quantity: qty || null, remarks: remarks || null, cancel });
            onSaved();
        } catch (err) {
            const fe = fieldErrors(err);
            setError(Object.values(fe)[0] ?? errorMessage(err));
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal open title={`Release ${reservation.reservation_number}`} onClose={onClose}
            footer={<><Button variant="secondary" onClick={onClose}>Back</Button><Button loading={saving} onClick={save}>{cancel ? 'Cancel reservation' : 'Release'}</Button></>}>
            <div className="space-y-4">
                {error && <Alert tone="danger">{error}</Alert>}
                <p className="text-sm text-slate-600">Open quantity: <Qty value={reservation.open_base_quantity} uom={reservation.base_uom?.code} className="font-semibold" />. Leave the quantity blank to release everything.</p>
                <FieldShell label={`Quantity to release (${reservation.base_uom?.code ?? 'base unit'})`}><QuantityInput large label="Quantity to release" value={qty} onChange={setQty} /></FieldShell>
                <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={cancel} onChange={(e) => setCancel(e.target.checked)} /> Mark as cancelled (not fulfilled)</label>
                <TextareaInput label="Remarks" rows={2} value={remarks} onChange={(e) => setRemarks(e.target.value)} />
            </div>
        </Modal>
    );
}
