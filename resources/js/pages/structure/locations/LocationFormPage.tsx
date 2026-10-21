import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useApi } from '@/hooks/useApi';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Alert, PageHeader } from '@/components/ui/Feedback';
import { Checkbox, SelectInput, TextareaInput, TextInput, Toggle } from '@/components/ui/Form';
import { Button, ButtonLink } from '@/components/ui/Button';
import { LoadingBlock } from '@/components/ui/Spinner';
import { useToast } from '@/components/ui/Toast';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { LOCATION_TYPES, type Factory, type Location, type LocationNode } from '../types';

/** Flattens the tree into indented options; excludes `excludeId` and its descendants (no cycles). */
function parentOptions(nodes: LocationNode[], excludeId: number | null, depth = 0): { id: number; label: string; active: boolean }[] {
    return nodes.flatMap((n) =>
        n.id === excludeId
            ? []
            : [{ id: n.id, label: `${'  '.repeat(depth)}${depth ? '└ ' : ''}${n.code} — ${n.name}`, active: n.is_active }, ...parentOptions(n.children, excludeId, depth + 1)],
    );
}

export default function LocationFormPage() {
    const { id } = useParams();
    const [query] = useSearchParams();
    const isEdit = Boolean(id);
    const navigate = useNavigate();
    const toast = useToast();

    const existing = useApi<{ data: Location }>(isEdit ? `/locations/${id}` : null);
    const factories = useApi<{ data: Factory[] }>('/factories', { all: 1 });

    const [form, setForm] = useState({
        factory_id: query.get('factory_id') ?? '', parent_id: query.get('parent_id') ?? '', code: '', name: '', type: 'rack',
        description: '', is_virtual: false, is_pickable: true, is_receivable: true, is_active: true,
    });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [formError, setFormError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    const tree = useApi<{ data: { tree: LocationNode[]; max_depth: number } }>(form.factory_id ? `/factories/${form.factory_id}/locations/tree` : null);

    useEffect(() => {
        const l = existing.data?.data;
        if (l) {
            setForm({
                factory_id: String(l.factory?.id ?? l.warehouse?.factory_id ?? ''), parent_id: l.parent_id ? String(l.parent_id) : '', code: l.code, name: l.name, type: l.type,
                description: l.description ?? '', is_virtual: l.is_virtual, is_pickable: l.is_pickable, is_receivable: l.is_receivable, is_active: l.is_active,
            });
        }
    }, [existing.data]);

    const options = useMemo(() => parentOptions(tree.data?.data.tree ?? [], isEdit ? Number(id) : null), [tree.data, isEdit, id]);

    if (existing.loading || factories.loading) return <LoadingBlock />;
    if (existing.error) return <Alert tone="danger">{existing.error}</Alert>;

    const currentParent = existing.data?.data.parent_id;
    const factoryOptions = (factories.data?.data ?? []).filter((f) => f.is_active || String(f.id) === form.factory_id);

    const onSubmit = async (event: FormEvent) => {
        event.preventDefault();
        if (saving) return;
        const clientErrors: Record<string, string> = {};
        if (!form.factory_id) clientErrors.factory_id = 'Select a factory.';
        if (!form.code.trim()) clientErrors.code = 'Location code is required.';
        if (!form.name.trim()) clientErrors.name = 'Location name is required.';
        setErrors(clientErrors);
        if (Object.keys(clientErrors).length) return;

        setSaving(true);
        setFormError(null);
        const { is_active, factory_id, ...rest } = form;
        const payload = { ...rest, code: rest.code.trim(), name: rest.name.trim(), description: rest.description.trim() || null, parent_id: rest.parent_id ? Number(rest.parent_id) : null };
        try {
            if (isEdit) {
                await api.put(`/locations/${id}`, payload);
                toast.success('Location updated.');
                navigate(`/factory-setup/locations/${id}`);
            } else {
                const { data } = await api.post<{ data: Location }>('/locations', { ...payload, factory_id: Number(factory_id), is_active });
                toast.success('Location created.');
                navigate(`/factory-setup/locations/${data.data.id}`);
            }
        } catch (err) {
            const fields = fieldErrors(err);
            if (fields.warehouse_id) fields.factory_id = fields.warehouse_id;
            setErrors(fields);
            if (!Object.keys(fields).length) setFormError(errorMessage(err));
        } finally {
            setSaving(false);
        }
    };

    return (
        <form onSubmit={onSubmit} noValidate>
            <PageHeader
                title={isEdit ? `Edit location: ${existing.data?.data.code ?? ''}` : 'Add location'}
                breadcrumb={<Link to="/factory-setup/locations" className="hover:text-slate-700">Storage Locations</Link>}
            />
            {formError && <Alert tone="danger" className="mb-6">{formError}</Alert>}
            <fieldset disabled={saving} className="space-y-6">
                <Card>
                    <CardHeader title="Location details" description={tree.data ? `Locations can be nested up to ${tree.data.data.max_depth} levels.` : undefined} />
                    <CardBody className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <SelectInput label="Factory" value={form.factory_id} onChange={(e) => setForm({ ...form, factory_id: e.target.value, parent_id: '' })} error={errors.factory_id} required disabled={isEdit} hint={isEdit ? 'A location cannot move to another factory.' : undefined}>
                            <option value="">Select factory</option>
                            {factoryOptions.map((f) => <option key={f.id} value={f.id}>{f.name} ({f.code})</option>)}
                        </SelectInput>
                        <SelectInput label="Parent location" value={form.parent_id} onChange={(e) => setForm({ ...form, parent_id: e.target.value })} error={errors.parent_id} disabled={!form.factory_id} hint="Leave empty for a top-level location.">
                            <option value="">None (top level)</option>
                            {options.map((o) => (
                                <option key={o.id} value={o.id} disabled={!o.active && o.id !== currentParent}>
                                    {o.label}{o.active ? '' : ' (inactive)'}
                                </option>
                            ))}
                        </SelectInput>
                        <TextInput label="Location code" value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase() })} error={errors.code} required maxLength={30} hint="Unique within the factory, e.g. RACK-A or A1-03." />
                        <TextInput label="Location name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} error={errors.name} required maxLength={150} />
                        <SelectInput label="Location type" value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })} error={errors.type} required>
                            {LOCATION_TYPES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                        </SelectInput>
                        <TextareaInput label="Description" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} error={errors.description} wrapperClassName="sm:col-span-2" />
                    </CardBody>
                </Card>
                <Card>
                    <CardHeader title="Configuration flags" description="Used by future inventory workflows. No stock is stored on locations." />
                    <CardBody className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <Checkbox label="Virtual location" description="Not a physical place (e.g. in transit, adjustments)." checked={form.is_virtual} onChange={(e) => setForm({ ...form, is_virtual: e.target.checked })} />
                        <Checkbox label="Pickable" description="Material may be issued from here." checked={form.is_pickable} onChange={(e) => setForm({ ...form, is_pickable: e.target.checked })} />
                        <Checkbox label="Receivable" description="Material may be received into here." checked={form.is_receivable} onChange={(e) => setForm({ ...form, is_receivable: e.target.checked })} />
                        {!isEdit && <div className="sm:col-span-3"><Toggle label="Active" checked={form.is_active} onChange={(v) => setForm({ ...form, is_active: v })} /></div>}
                    </CardBody>
                </Card>
            </fieldset>
            <div className="mt-6 flex justify-end gap-2">
                <ButtonLink to={isEdit ? `/factory-setup/locations/${id}` : '/factory-setup/locations'} variant="secondary">Cancel</ButtonLink>
                <Button type="submit" loading={saving}>{isEdit ? 'Save changes' : 'Create location'}</Button>
            </div>
        </form>
    );
}
