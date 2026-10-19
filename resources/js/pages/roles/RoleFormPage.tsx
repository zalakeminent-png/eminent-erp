import { useEffect, useState, type FormEvent } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useAuth } from '@/auth/AuthContext';
import { useApi } from '@/hooks/useApi';
import { Card, CardBody } from '@/components/ui/Card';
import { Alert, PageHeader } from '@/components/ui/Feedback';
import { TextareaInput, TextInput, Toggle } from '@/components/ui/Form';
import { Button, ButtonLink } from '@/components/ui/Button';
import { LoadingBlock } from '@/components/ui/Spinner';
import { useToast } from '@/components/ui/Toast';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import type { Role } from '@/types';

export default function RoleFormPage() {
    const { id } = useParams();
    const isEdit = Boolean(id);
    const navigate = useNavigate();
    const toast = useToast();
    const { can } = useAuth();
    const existing = useApi<{ data: Role }>(isEdit ? `/roles/${id}` : null);

    const [form, setForm] = useState({ name: '', description: '', is_active: true });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [formError, setFormError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        const role = existing.data?.data;
        if (role) setForm({ name: role.name, description: role.description ?? '', is_active: role.is_active });
    }, [existing.data]);

    if (existing.loading) return <LoadingBlock />;
    if (existing.error) return <Alert tone="danger">{existing.error}</Alert>;
    const role = existing.data?.data;

    const onSubmit = async (event: FormEvent) => {
        event.preventDefault();
        if (form.name.trim().length < 2) {
            setErrors({ name: 'Role name must be at least 2 characters.' });
            return;
        }
        setSaving(true);
        setErrors({});
        setFormError(null);
        try {
            const payload = { name: form.name.trim(), description: form.description.trim() || null, is_active: form.is_active };
            if (isEdit) {
                await api.put(`/roles/${id}`, payload);
                toast.success('Role updated.');
                navigate(`/settings/roles/${id}`);
            } else {
                const { data } = await api.post<{ data: Role }>('/roles', payload);
                toast.success('Role created. Now choose its permissions.');
                navigate(can('roles.assign-permissions') ? `/settings/roles/${data.data.id}/permissions` : `/settings/roles/${data.data.id}`);
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
                title={isEdit ? `Edit role: ${role?.name ?? ''}` : 'Add role'}
                breadcrumb={<Link to="/settings/roles" className="hover:text-slate-700">Roles & Permissions</Link>}
            />
            {formError && <Alert tone="danger" className="mb-6">{formError}</Alert>}
            {role?.is_system && <Alert tone="info" className="mb-6">This is a core role. Its name cannot be changed and it cannot be deleted.</Alert>}
            <Card className="max-w-2xl">
                <CardBody className="space-y-5">
                    <TextInput label="Role name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} error={errors.name} required disabled={role?.is_system} maxLength={60} autoFocus={!isEdit} />
                    <TextareaInput label="Description" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} error={errors.description} maxLength={500} />
                    <TextInput label="Guard" value="web" disabled hint="Roles apply to the web/SPA authentication guard." />
                    <Toggle
                        label="Active"
                        description="Permissions from an inactive role are not granted to its users."
                        checked={form.is_active}
                        onChange={(v) => setForm({ ...form, is_active: v })}
                        disabled={role?.is_primary_admin}
                    />
                    {errors.is_active && <p className="text-xs text-red-600">{errors.is_active}</p>}
                </CardBody>
                <div className="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
                    <ButtonLink to={isEdit ? `/settings/roles/${id}` : '/settings/roles'} variant="secondary">Cancel</ButtonLink>
                    <Button type="submit" loading={saving}>{isEdit ? 'Save changes' : 'Create role'}</Button>
                </div>
            </Card>
        </form>
    );
}
