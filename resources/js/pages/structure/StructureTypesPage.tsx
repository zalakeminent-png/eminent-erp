import { useState, type FormEvent } from 'react';
import { Pencil, Plus } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useApi } from '@/hooks/useApi';
import { Card, CardHeader } from '@/components/ui/Card';
import { Alert, PageHeader, StatusBadge } from '@/components/ui/Feedback';
import { TextInput, Toggle } from '@/components/ui/Form';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { Table, Td, Th } from '@/components/ui/Table';
import { LoadingBlock } from '@/components/ui/Spinner';
import { useToast } from '@/components/ui/Toast';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import type { StructureType } from './types';

type Category = StructureType['category'];
type Editing = { category: Category; type: StructureType | null } | null;

/** Configurable department and warehouse types. */
export default function StructureTypesPage() {
    const { can } = useAuth();
    const toast = useToast();
    const { data, loading, error, reload } = useApi<{ data: StructureType[] }>('/structure-types');
    const [editing, setEditing] = useState<Editing>(null);
    const [form, setForm] = useState({ name: '', description: '', sort_order: '1', is_active: true });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    const open = (category: Category, type: StructureType | null) => {
        setEditing({ category, type });
        setErrors({});
        // New types default to the next sequential position in their category.
        const nextOrder = Math.max(0, ...(data?.data ?? []).filter((t) => t.category === category).map((t) => t.sort_order)) + 1;
        setForm(type ? { name: type.name, description: type.description ?? '', sort_order: String(type.sort_order), is_active: type.is_active } : { name: '', description: '', sort_order: String(nextOrder), is_active: true });
    };

    const save = async (event: FormEvent) => {
        event.preventDefault();
        if (!editing || saving) return;
        setSaving(true);
        const payload = { name: form.name.trim(), description: form.description.trim() || null, sort_order: form.sort_order.trim() === '' ? null : Number(form.sort_order), is_active: form.is_active };
        try {
            if (editing.type) await api.put(`/structure-types/${editing.type.id}`, payload);
            else await api.post('/structure-types', { ...payload, category: editing.category });
            toast.success('Type saved.');
            setEditing(null);
            reload();
        } catch (err) {
            setErrors(fieldErrors(err));
            if (!Object.keys(fieldErrors(err)).length) toast.error(errorMessage(err));
        } finally {
            setSaving(false);
        }
    };

    const section = (category: Category, title: string) => {
        const rows = (data?.data ?? []).filter((t) => t.category === category);
        return (
            <Card>
                <CardHeader
                    title={title}
                    description="Suggestions only — no departments or warehouses are created from these."
                    actions={can('structure-types.create') && <Button size="sm" icon={<Plus className="h-4 w-4" />} onClick={() => open(category, null)}>Add type</Button>}
                />
                <Table>
                    <thead><tr><Th>Name</Th><Th className="hidden sm:table-cell">Description</Th><Th className="text-right">In use</Th><Th>Status</Th><Th><span className="sr-only">Actions</span></Th></tr></thead>
                    <tbody className="divide-y divide-slate-100">
                        {rows.map((t) => (
                            <tr key={t.id}>
                                <Td className="font-medium text-slate-900">{t.name}</Td>
                                <Td className="hidden text-slate-500 sm:table-cell">{t.description ?? '—'}</Td>
                                <Td className="text-right tabular-nums">{t.usage_count ?? 0}</Td>
                                <Td><StatusBadge active={t.is_active} /></Td>
                                <Td className="text-right">
                                    {can('structure-types.update') && <Button variant="ghost" size="sm" aria-label={`Edit ${t.name}`} icon={<Pencil className="h-4 w-4" />} onClick={() => open(category, t)} />}
                                </Td>
                            </tr>
                        ))}
                    </tbody>
                </Table>
            </Card>
        );
    };

    return (
        <>
            <PageHeader title="Department & Warehouse Types" description="Configurable classifications. Types in use can be deactivated but not deleted." />
            {error ? <Alert tone="danger">{error}</Alert> : loading ? <LoadingBlock /> : (
                <div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
                    {section('department', 'Department types')}
                    {section('warehouse', 'Warehouse types')}
                </div>
            )}
            <Modal
                open={editing !== null}
                onClose={() => setEditing(null)}
                title={`${editing?.type ? 'Edit' : 'Add'} ${editing?.category ?? ''} type`}
            >
                <form id="type-form" onSubmit={save} className="space-y-4">
                    <TextInput label="Name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} error={errors.name} required maxLength={100} autoFocus />
                    <TextInput label="Description" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} error={errors.description} maxLength={255} />
                    <TextInput label="Sort order" type="number" value={form.sort_order} onChange={(e) => setForm({ ...form, sort_order: e.target.value })} error={errors.sort_order} />
                    <Toggle label="Active" description="Inactive types cannot be selected for new records." checked={form.is_active} onChange={(v) => setForm({ ...form, is_active: v })} />
                    <div className="flex justify-end gap-2 pt-2">
                        <Button variant="secondary" onClick={() => setEditing(null)} disabled={saving}>Cancel</Button>
                        <Button type="submit" loading={saving} disabled={!form.name.trim()}>Save</Button>
                    </div>
                </form>
            </Modal>
        </>
    );
}
