import { useState } from 'react';
import { Pencil, Plus } from 'lucide-react';
import { useApi } from '@/hooks/useApi';
import { Card, CardHeader } from '@/components/ui/Card';
import { Alert, PageHeader, StatusBadge } from '@/components/ui/Feedback';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { Table, Td, Th } from '@/components/ui/Table';
import { LoadingBlock } from '@/components/ui/Spinner';
import { TextInput, Toggle } from '@/components/ui/Form';
import { useToast } from '@/components/ui/Toast';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { humanize } from '@/lib/format';
import { filterInputClass } from '@/pages/structure/components/ListControls';
import { useLocations, useLookups } from './components';
import type { ReasonCode } from './types';

const CATEGORIES = ['scrap', 'rejection', 'adjustment', 'return', 'qc', 'transfer', 'general'];

/** Reason codes and stock purposes of storage locations. */
export default function InventorySettingsPage() {
    return (
        <>
            <PageHeader title="Inventory Settings" description="Configurable reason codes and the stock purpose of each storage location (used for WIP, QC and scrap rules)." />
            <div className="grid gap-4 xl:grid-cols-2">
                <ReasonCodes />
                <Purposes />
            </div>
        </>
    );
}

function ReasonCodes() {
    const toast = useToast();
    const { data, loading, error, reload } = useApi<{ data: ReasonCode[] }>('/inventory/reason-codes');
    const [editing, setEditing] = useState<Partial<ReasonCode> | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    const save = async () => {
        if (!editing) return;
        setSaving(true);
        setErrors({});
        try {
            if (editing.id) await api.put(`/inventory/reason-codes/${editing.id}`, editing);
            else await api.post('/inventory/reason-codes', { ...editing, is_active: editing.is_active ?? true });
            toast.success('Reason code saved.');
            setEditing(null);
            reload();
        } catch (err) {
            const fe = fieldErrors(err);
            setErrors(Object.keys(fe).length ? fe : { form: errorMessage(err) });
        } finally {
            setSaving(false);
        }
    };

    return (
        <Card>
            <CardHeader title="Reason codes" actions={<Button size="sm" icon={<Plus className="h-4 w-4" />} onClick={() => setEditing({ category: 'scrap', is_active: true })}>Add</Button>} />
            {error ? <div className="p-4"><Alert tone="danger">{error}</Alert></div> : loading && !data ? <LoadingBlock /> : (
                <Table>
                    <thead><tr><Th>Code</Th><Th>Name</Th><Th>Category</Th><Th>Status</Th><Th><span className="sr-only">Edit</span></Th></tr></thead>
                    <tbody className="divide-y divide-slate-100 bg-white">
                        {data?.data.map((r) => (
                            <tr key={r.id}>
                                <Td className="font-mono text-xs font-semibold">{r.code}</Td>
                                <Td>{r.name}</Td>
                                <Td className="text-xs">{humanize(r.category)}</Td>
                                <Td><StatusBadge active={r.is_active} /></Td>
                                <Td className="text-right"><Button variant="ghost" size="sm" aria-label={`Edit ${r.code}`} icon={<Pencil className="h-4 w-4" />} onClick={() => setEditing(r)} /></Td>
                            </tr>
                        ))}
                    </tbody>
                </Table>
            )}
            {editing && (
                <Modal open title={editing.id ? `Edit ${editing.code}` : 'New reason code'} onClose={() => setEditing(null)}
                    footer={<><Button variant="secondary" onClick={() => setEditing(null)}>Cancel</Button><Button loading={saving} onClick={save}>Save</Button></>}>
                    <div className="space-y-4">
                        {errors.form && <Alert tone="danger">{errors.form}</Alert>}
                        <TextInput label="Code" required value={editing.code ?? ''} onChange={(e) => setEditing({ ...editing, code: e.target.value })} error={errors.code} />
                        <TextInput label="Name" required value={editing.name ?? ''} onChange={(e) => setEditing({ ...editing, name: e.target.value })} error={errors.name} />
                        <label className="block text-sm">
                            <span className="mb-1.5 block font-medium text-slate-700">Category</span>
                            <select aria-label="Category" value={editing.category ?? ''} onChange={(e) => setEditing({ ...editing, category: e.target.value })} className={filterInputClass}>
                                {CATEGORIES.map((c) => <option key={c} value={c}>{humanize(c)}</option>)}
                            </select>
                        </label>
                        <Toggle checked={editing.is_active ?? true} onChange={(v) => setEditing({ ...editing, is_active: v })} label="Active" />
                    </div>
                </Modal>
            )}
        </Card>
    );
}

function Purposes() {
    const toast = useToast();
    const lookups = useLookups();
    const [factoryId, setFactoryId] = useState('');
    const locations = useLocations({ factory_id: factoryId || undefined, include_inactive: 1 });
    const purposes = lookups.data?.data.stock_purposes ?? [];
    const factories = lookups.data?.data.factories ?? [];

    const save = async (id: number, value: string) => {
        try {
            await api.put(`/inventory/locations/${id}/purpose`, { stock_purpose: value || null });
            toast.success('Stock purpose saved.');
            locations.reload();
        } catch (err) {
            toast.error(errorMessage(err));
        }
    };

    return (
        <Card>
            <CardHeader title="Stock purpose of locations" description="What each storage location is used for. Material issues must go to Production / WIP locations; QC, rejected and scrap locations cannot be reserved."
                actions={factories.length > 1 && (
                    <select aria-label="Factory" value={factoryId} onChange={(e) => setFactoryId(e.target.value)} className={`${filterInputClass} h-9`}>
                        <option value="">All factories</option>
                        {factories.map((f) => <option key={f.id} value={f.id}>{f.name}</option>)}
                    </select>
                )} />
            {locations.loading && !locations.data ? <LoadingBlock /> : !locations.data?.data.length ? (
                <p className="px-4 pb-4 text-sm text-slate-500">No storage locations yet. Add them under Factory Setup → Storage Locations.</p>
            ) : (
                <Table>
                    <thead><tr><Th>Location</Th><Th>Purpose</Th></tr></thead>
                    <tbody className="divide-y divide-slate-100 bg-white">
                        {locations.data.data.map((l) => (
                            <tr key={l.id}>
                                <Td className="text-xs"><span className="font-mono font-semibold">{l.code}</span> {l.name}</Td>
                                <Td>
                                    <select aria-label={`Purpose of ${l.code}`} value={l.stock_purpose ?? ''} onChange={(e) => save(l.id, e.target.value)} className={`${filterInputClass} h-9`}>
                                        <option value="">Default ({humanize(l.purpose)})</option>
                                        {purposes.map((p) => <option key={p.value} value={p.value}>{p.label}</option>)}
                                    </select>
                                </Td>
                            </tr>
                        ))}
                    </tbody>
                </Table>
            )}
        </Card>
    );
}
