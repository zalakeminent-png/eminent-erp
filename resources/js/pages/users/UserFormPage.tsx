import { useEffect, useState, type FormEvent } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useAuth } from '@/auth/AuthContext';
import { useApi } from '@/hooks/useApi';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Alert, Badge, PageHeader } from '@/components/ui/Feedback';
import { Checkbox, TextInput, Toggle } from '@/components/ui/Form';
import { Button, ButtonLink } from '@/components/ui/Button';
import { LoadingBlock } from '@/components/ui/Spinner';
import { useToast } from '@/components/ui/Toast';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import type { Role, User } from '@/types';

interface FormState {
    first_name: string;
    last_name: string;
    email: string;
    employee_code: string;
    mobile: string;
    role_ids: number[];
    is_active: boolean;
}

const blank: FormState = { first_name: '', last_name: '', email: '', employee_code: '', mobile: '', role_ids: [], is_active: true };

export default function UserFormPage() {
    const { id } = useParams();
    const isEdit = Boolean(id);
    const navigate = useNavigate();
    const toast = useToast();
    const { user: me } = useAuth();
    const isSelf = isEdit && Number(id) === me?.id;

    const existing = useApi<{ data: User }>(isEdit ? `/users/${id}` : null);
    const roles = useApi<{ data: Role[] }>('/roles', { all: 1 });

    const [form, setForm] = useState<FormState>(blank);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [formError, setFormError] = useState<string | null>(null);

    useEffect(() => {
        const u = existing.data?.data;
        if (u) {
            setForm({
                first_name: u.first_name, last_name: u.last_name, email: u.email, employee_code: u.employee_code ?? '',
                mobile: u.mobile ?? '', role_ids: u.roles?.map((r) => r.id) ?? [], is_active: u.is_active,
            });
        }
    }, [existing.data]);

    if (existing.loading || roles.loading) return <LoadingBlock />;
    if (existing.error) return <Alert tone="danger">{existing.error}</Alert>;

    // Inactive roles cannot be newly assigned, but stay visible if already held.
    const roleOptions = (roles.data?.data ?? []).filter((r) => r.is_active || form.role_ids.includes(r.id));

    const toggleRole = (roleId: number) =>
        setForm((f) => ({ ...f, role_ids: f.role_ids.includes(roleId) ? f.role_ids.filter((r) => r !== roleId) : [...f.role_ids, roleId] }));

    const onSubmit = async (event: FormEvent) => {
        event.preventDefault();
        const clientErrors: Record<string, string> = {};
        if (!form.first_name.trim()) clientErrors.first_name = 'First name is required.';
        if (!form.last_name.trim()) clientErrors.last_name = 'Last name is required.';
        if (!/^\S+@\S+\.\S+$/.test(form.email)) clientErrors.email = 'Enter a valid email address.';
        if (form.role_ids.length === 0) clientErrors.role_ids = 'Assign at least one role.';
        setErrors(clientErrors);
        if (Object.keys(clientErrors).length) return;

        setSaving(true);
        setFormError(null);
        const payload = {
            first_name: form.first_name.trim(),
            last_name: form.last_name.trim(),
            email: form.email.trim(),
            employee_code: form.employee_code.trim() || null,
            mobile: form.mobile.trim() || null,
            role_ids: form.role_ids,
            ...(isEdit ? {} : { is_active: form.is_active }),
        };
        try {
            if (isEdit) {
                await api.put(`/users/${id}`, payload);
                toast.success('User updated.');
                navigate(`/settings/users/${id}`);
            } else {
                const { data } = await api.post<{ data: User; message: string }>('/users', payload);
                toast.success(data.message);
                navigate(`/settings/users/${data.data.id}`);
            }
        } catch (err) {
            const fields = fieldErrors(err);
            const roleError = Object.entries(fields).find(([k]) => k.startsWith('role_ids'))?.[1];
            setErrors({ ...fields, ...(roleError ? { role_ids: roleError } : {}) });
            if (!Object.keys(fields).length) setFormError(errorMessage(err));
        } finally {
            setSaving(false);
        }
    };

    const set = (key: keyof FormState) => (e: React.ChangeEvent<HTMLInputElement>) => setForm((f) => ({ ...f, [key]: e.target.value }));

    return (
        <form onSubmit={onSubmit} noValidate>
            <PageHeader
                title={isEdit ? 'Edit user' : 'Add user'}
                breadcrumb={<Link to="/settings/users" className="hover:text-slate-700">Users</Link>}
                description={isEdit ? existing.data?.data.email : 'The user receives an email link to set their own password.'}
            />
            {formError && <Alert tone="danger" className="mb-6">{formError}</Alert>}

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader title="User details" />
                    <CardBody className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <TextInput label="First name" value={form.first_name} onChange={set('first_name')} error={errors.first_name} required autoFocus={!isEdit} />
                        <TextInput label="Last name" value={form.last_name} onChange={set('last_name')} error={errors.last_name} required />
                        <TextInput label="Email" type="email" value={form.email} onChange={set('email')} error={errors.email} required autoComplete="off" />
                        <TextInput label="Mobile number" type="tel" value={form.mobile} onChange={set('mobile')} error={errors.mobile} placeholder="+91 98765 43210" />
                        <TextInput label="Employee code" value={form.employee_code} onChange={set('employee_code')} error={errors.employee_code} hint="Optional. Must be unique." />
                        <p className="self-end pb-2 text-xs text-slate-500">
                            Factory and department assignment is managed on the user details page after saving.
                        </p>
                        {!isEdit && (
                            <div className="sm:col-span-2">
                                <Toggle label="Active account" description="Inactive users cannot sign in." checked={form.is_active} onChange={(v) => setForm((f) => ({ ...f, is_active: v }))} />
                            </div>
                        )}
                    </CardBody>
                </Card>

                <Card>
                    <CardHeader title="Roles" description="Permissions come from the assigned roles." />
                    <CardBody className="space-y-3">
                        {isSelf && <Alert tone="info">You cannot change your own roles.</Alert>}
                        {roleOptions.map((role) => (
                            <Checkbox
                                key={role.id}
                                checked={form.role_ids.includes(role.id)}
                                onChange={() => toggleRole(role.id)}
                                disabled={isSelf}
                                label={<span className="inline-flex items-center gap-2">{role.name}{!role.is_active && <Badge>Inactive</Badge>}</span>}
                                description={role.description ?? undefined}
                            />
                        ))}
                        {roleOptions.length === 0 && <p className="text-sm text-slate-500">No roles available.</p>}
                        {errors.role_ids && <p className="text-xs text-red-600">{errors.role_ids}</p>}
                    </CardBody>
                </Card>
            </div>

            <div className="mt-6 flex justify-end gap-2">
                <ButtonLink to={isEdit ? `/settings/users/${id}` : '/settings/users'} variant="secondary">Cancel</ButtonLink>
                <Button type="submit" loading={saving}>{isEdit ? 'Save changes' : 'Create user'}</Button>
            </div>
        </form>
    );
}
