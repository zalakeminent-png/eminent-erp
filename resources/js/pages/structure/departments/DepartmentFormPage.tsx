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
import type { Department, Factory, StructureType, UserSummary } from '../types';
import { UserPicker } from '../components/UserPicker';

export default function DepartmentFormPage() {
    const { id } = useParams();
    const [query] = useSearchParams();
    const isEdit = Boolean(id);
    const navigate = useNavigate();
    const toast = useToast();
    const { can } = useAuth();

    const existing = useApi<{ data: Department }>(isEdit ? `/departments/${id}` : null);
    const factories = useApi<{ data: Factory[] }>('/factories', { all: 1 });
    const types = useApi<{ data: StructureType[] }>('/structure-types', { category: 'department' });

    const [form, setForm] = useState({ factory_id: query.get('factory_id') ?? '', type_id: '', code: '', name: '', description: '', is_active: true });
    const [manager, setManager] = useState<UserSummary | null>(null);
    const [supervisor, setSupervisor] = useState<UserSummary | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [formError, setFormError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        const d = existing.data?.data;
        if (d) setForm({ factory_id: String(d.factory_id), type_id: d.type_id ? String(d.type_id) : '', code: d.code, name: d.name, description: d.description ?? '', is_active: d.is_active });
    }, [existing.data]);

    // Default to the only active factory (single-factory setups).
    useEffect(() => {
        const active = factories.data?.data.filter((f) => f.is_active) ?? [];
        if (!isEdit && !form.factory_id && active.length === 1) setForm((f) => ({ ...f, factory_id: String(active[0].id) }));
    }, [factories.data, isEdit, form.factory_id]);

    if (existing.loading || factories.loading) return <LoadingBlock />;
    if (existing.error) return <Alert tone="danger">{existing.error}</Alert>;

    const currentType = existing.data?.data.type_id;
    const typeOptions = (types.data?.data ?? []).filter((t) => t.is_active || t.id === currentType);
    const factoryOptions = (factories.data?.data ?? []).filter((f) => f.is_active || String(f.id) === form.factory_id);

    const onSubmit = async (event: FormEvent) => {
        event.preventDefault();
        if (saving) return;
        const clientErrors: Record<string, string> = {};
        if (!form.factory_id) clientErrors.factory_id = 'Select a factory.';
        if (!form.code.trim()) clientErrors.code = 'Department code is required.';
        if (!form.name.trim()) clientErrors.name = 'Department name is required.';
        setErrors(clientErrors);
        if (Object.keys(clientErrors).length) return;

        setSaving(true);
        setFormError(null);
        const payload: Record<string, unknown> = {
            code: form.code.trim(), name: form.name.trim(), description: form.description.trim() || null,
            type_id: form.type_id ? Number(form.type_id) : null,
        };
        try {
            if (isEdit) {
                await api.put(`/departments/${id}`, payload);
                toast.success('Department updated.');
                navigate(`/factory-setup/departments/${id}`);
            } else {
                const { data } = await api.post<{ data: Department }>('/departments', {
                    ...payload, factory_id: Number(form.factory_id), is_active: form.is_active,
                    ...(can('departments.assign-managers') ? { manager_id: manager?.id ?? null, supervisor_id: supervisor?.id ?? null } : {}),
                });
                toast.success('Department created.');
                navigate(`/factory-setup/departments/${data.data.id}`);
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
                title={isEdit ? `Edit department: ${existing.data?.data.name ?? ''}` : 'Add department'}
                breadcrumb={<Link to="/factory-setup/departments" className="hover:text-slate-700">Departments</Link>}
            />
            {formError && <Alert tone="danger" className="mb-6">{formError}</Alert>}
            {!isEdit && factoryOptions.length === 0 && <Alert tone="warning" className="mb-6">No active factory is available. Create or activate a factory first.</Alert>}

            <fieldset disabled={saving} className="space-y-6">
                <Card>
                    <CardHeader title="Department details" />
                    <CardBody className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <SelectInput label="Factory" value={form.factory_id} onChange={(e) => setForm({ ...form, factory_id: e.target.value })} error={errors.factory_id} required disabled={isEdit} hint={isEdit ? 'A department cannot move to another factory.' : undefined}>
                            <option value="">Select factory</option>
                            {factoryOptions.map((f) => <option key={f.id} value={f.id}>{f.name} ({f.code})</option>)}
                        </SelectInput>
                        <SelectInput label="Department type" value={form.type_id} onChange={(e) => setForm({ ...form, type_id: e.target.value })} error={errors.type_id} hint={can('structure-types.create') ? <Link className="text-brand-600" to="/factory-setup/types">Manage types</Link> : undefined}>
                            <option value="">No type</option>
                            {typeOptions.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                        </SelectInput>
                        <TextInput label="Department code" value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase() })} error={errors.code} required maxLength={20} hint="Unique within the factory." />
                        <TextInput label="Department name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} error={errors.name} required maxLength={150} />
                        <TextareaInput label="Description" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} error={errors.description} wrapperClassName="sm:col-span-2" />
                        {!isEdit && (
                            <div className="sm:col-span-2">
                                <Toggle label="Active" checked={form.is_active} onChange={(v) => setForm({ ...form, is_active: v })} />
                            </div>
                        )}
                    </CardBody>
                </Card>

                {!isEdit && can('departments.assign-managers') && (
                    <Card>
                        <CardHeader title="Manager & supervisor" description="Optional. Only active users can be selected." />
                        <CardBody className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <UserPicker label="Department manager" value={manager} onChange={setManager} error={errors.manager_id} />
                            <UserPicker label="Department supervisor" value={supervisor} onChange={setSupervisor} error={errors.supervisor_id} />
                        </CardBody>
                    </Card>
                )}
            </fieldset>

            <div className="mt-6 flex justify-end gap-2">
                <ButtonLink to={isEdit ? `/factory-setup/departments/${id}` : '/factory-setup/departments'} variant="secondary">Cancel</ButtonLink>
                <Button type="submit" loading={saving}>{isEdit ? 'Save changes' : 'Create department'}</Button>
            </div>
        </form>
    );
}
