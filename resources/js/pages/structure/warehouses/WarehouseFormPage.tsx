import { useEffect, useState, type FormEvent } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useAuth } from '@/auth/AuthContext';
import { useApi } from '@/hooks/useApi';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Alert, PageHeader } from '@/components/ui/Feedback';
import { SelectInput, TextareaInput, TextInput, Toggle } from '@/components/ui/Form';
import { Button, ButtonLink } from '@/components/ui/Button';
import { LoadingBlock } from '@/components/ui/Spinner';
import { useToast } from '@/components/ui/Toast';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import type { Department, Factory, StructureType, UserSummary, Warehouse } from '../types';
import { UserPicker } from '../components/UserPicker';

export default function WarehouseFormPage() {
    const { id } = useParams();
    const [query] = useSearchParams();
    const isEdit = Boolean(id);
    const navigate = useNavigate();
    const toast = useToast();
    const { can } = useAuth();

    const existing = useApi<{ data: Warehouse }>(isEdit ? `/warehouses/${id}` : null);
    const factories = useApi<{ data: Factory[] }>('/factories', { all: 1 });
    const types = useApi<{ data: StructureType[] }>('/structure-types', { category: 'warehouse' });

    const [form, setForm] = useState({ factory_id: query.get('factory_id') ?? '', department_id: '', type_id: '', code: '', name: '', description: '', is_active: true });
    const [manager, setManager] = useState<UserSummary | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [formError, setFormError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    // Responsible department must be in the same factory.
    const departments = useApi<{ data: Department[] }>(form.factory_id ? '/departments' : null, { factory_id: form.factory_id, all: 1 });

    useEffect(() => {
        const w = existing.data?.data;
        if (w) {
            setForm({
                factory_id: String(w.factory_id), department_id: w.department_id ? String(w.department_id) : '', type_id: w.type_id ? String(w.type_id) : '',
                code: w.code, name: w.name, description: w.description ?? '', is_active: w.is_active,
            });
            setManager(w.manager ?? null);
        }
    }, [existing.data]);

    useEffect(() => {
        const active = factories.data?.data.filter((f) => f.is_active) ?? [];
        if (!isEdit && !form.factory_id && active.length === 1) setForm((f) => ({ ...f, factory_id: String(active[0].id) }));
    }, [factories.data, isEdit, form.factory_id]);

    if (existing.loading || factories.loading) return <LoadingBlock />;
    if (existing.error) return <Alert tone="danger">{existing.error}</Alert>;

    const current = existing.data?.data;
    const factoryOptions = (factories.data?.data ?? []).filter((f) => f.is_active || String(f.id) === form.factory_id);
    const typeOptions = (types.data?.data ?? []).filter((t) => t.is_active || t.id === current?.type_id);
    const deptOptions = (departments.data?.data ?? []).filter((d) => d.is_active || d.id === current?.department_id);

    const onSubmit = async (event: FormEvent) => {
        event.preventDefault();
        if (saving) return;
        const clientErrors: Record<string, string> = {};
        if (!form.factory_id) clientErrors.factory_id = 'Select a factory.';
        if (!form.code.trim()) clientErrors.code = 'Warehouse code is required.';
        if (!form.name.trim()) clientErrors.name = 'Warehouse name is required.';
        setErrors(clientErrors);
        if (Object.keys(clientErrors).length) return;

        setSaving(true);
        setFormError(null);
        const payload = {
            code: form.code.trim(), name: form.name.trim(), description: form.description.trim() || null,
            department_id: form.department_id ? Number(form.department_id) : null,
            type_id: form.type_id ? Number(form.type_id) : null,
            manager_id: manager?.id ?? null,
        };
        try {
            if (isEdit) {
                await api.put(`/warehouses/${id}`, payload);
                toast.success('Warehouse updated.');
                navigate(`/factory-setup/warehouses/${id}`);
            } else {
                const { data } = await api.post<{ data: Warehouse }>('/warehouses', { ...payload, factory_id: Number(form.factory_id), is_active: form.is_active });
                toast.success('Warehouse created.');
                navigate(`/factory-setup/warehouses/${data.data.id}`);
            }
        } catch (err) {
            const fields = fieldErrors(err);
            setErrors(fields);
            if (!Object.keys(fields).length) setFormError(errorMessage(err));
        } finally {
            setSaving(false);
        }
    };

    return (
        <form onSubmit={onSubmit} noValidate>
            <PageHeader
                title={isEdit ? `Edit warehouse: ${current?.name ?? ''}` : 'Add warehouse'}
                breadcrumb={<Link to="/factory-setup/warehouses" className="hover:text-slate-700">Warehouses</Link>}
                description="A warehouse is a storage area. It is not created automatically for departments."
            />
            {formError && <Alert tone="danger" className="mb-6">{formError}</Alert>}
            <fieldset disabled={saving} className="space-y-6">
                <Card>
                    <CardHeader title="Warehouse details" />
                    <CardBody className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <SelectInput label="Factory" value={form.factory_id} onChange={(e) => setForm({ ...form, factory_id: e.target.value, department_id: '' })} error={errors.factory_id} required disabled={isEdit} hint={isEdit ? 'A warehouse cannot move to another factory.' : undefined}>
                            <option value="">Select factory</option>
                            {factoryOptions.map((f) => <option key={f.id} value={f.id}>{f.name} ({f.code})</option>)}
                        </SelectInput>
                        <SelectInput label="Warehouse type" value={form.type_id} onChange={(e) => setForm({ ...form, type_id: e.target.value })} error={errors.type_id} hint={can('structure-types.create') ? <Link className="text-brand-600" to="/factory-setup/types">Manage types</Link> : undefined}>
                            <option value="">No type</option>
                            {typeOptions.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                        </SelectInput>
                        <TextInput label="Warehouse code" value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase() })} error={errors.code} required maxLength={20} hint="Unique within the factory." />
                        <TextInput label="Warehouse name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} error={errors.name} required maxLength={150} />
                        <SelectInput label="Responsible department (optional)" value={form.department_id} onChange={(e) => setForm({ ...form, department_id: e.target.value })} error={errors.department_id} disabled={!form.factory_id}>
                            <option value="">None</option>
                            {deptOptions.map((d) => <option key={d.id} value={d.id}>{d.name} ({d.code})</option>)}
                        </SelectInput>
                        <UserPicker label="Warehouse manager (optional)" value={manager} onChange={setManager} error={errors.manager_id} />
                        <TextareaInput label="Description" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} error={errors.description} wrapperClassName="sm:col-span-2" />
                        {!isEdit && (
                            <div className="sm:col-span-2"><Toggle label="Active" checked={form.is_active} onChange={(v) => setForm({ ...form, is_active: v })} /></div>
                        )}
                    </CardBody>
                </Card>
            </fieldset>
            <div className="mt-6 flex justify-end gap-2">
                <ButtonLink to={isEdit ? `/factory-setup/warehouses/${id}` : '/factory-setup/warehouses'} variant="secondary">Cancel</ButtonLink>
                <Button type="submit" loading={saving}>{isEdit ? 'Save changes' : 'Create warehouse'}</Button>
            </div>
        </form>
    );
}
