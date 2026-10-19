import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { MapPinned, Pencil } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useApi } from '@/hooks/useApi';
import { Card, CardBody, CardHeader, DescriptionList } from '@/components/ui/Card';
import { Alert, Badge } from '@/components/ui/Feedback';
import { Checkbox, SelectInput } from '@/components/ui/Form';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { useToast } from '@/components/ui/Toast';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import type { User } from '@/types';
import type { Department, Factory } from '@/pages/structure/types';

/**
 * Factory / department assignment (WHERE a user works). Separate from the
 * roles card (WHAT they may do).
 */
export function UserAssignmentsCard({ userId }: { userId: number }) {
    const { can, user: me } = useAuth();
    const toast = useToast();
    const assignment = useApi<{ data: User }>(`/users/${userId}/assignments`);
    const [editing, setEditing] = useState(false);
    const editable = can('departments.assign-users') && (userId !== me?.id || can('factories.access-all'));

    const factories = useApi<{ data: Factory[] }>(editing ? '/factories' : null, { all: 1 });
    const departments = useApi<{ data: Department[] }>(editing ? '/departments' : null, { all: 1 });

    const [form, setForm] = useState({ factory_id: '', primary_department_id: '', department_ids: [] as number[] });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    const u = assignment.data?.data;
    useEffect(() => {
        if (u && editing) {
            setForm({
                factory_id: u.factory_id ? String(u.factory_id) : '',
                primary_department_id: u.department_id ? String(u.department_id) : '',
                department_ids: u.additional_departments?.map((d) => d.id) ?? [],
            });
            setErrors({});
        }
    }, [u, editing]);

    const allDepartments = departments.data?.data ?? [];
    const primaryOptions = allDepartments.filter((d) => String(d.factory_id) === form.factory_id && (d.is_active || String(d.id) === form.primary_department_id));
    const additionalOptions = allDepartments.filter((d) => String(d.id) !== form.primary_department_id && (d.is_active || form.department_ids.includes(d.id)));

    const save = async () => {
        setSaving(true);
        try {
            const { data } = await api.put<{ data: User }>(`/users/${userId}/assignments`, {
                factory_id: form.factory_id ? Number(form.factory_id) : null,
                primary_department_id: form.primary_department_id ? Number(form.primary_department_id) : null,
                department_ids: form.department_ids,
            });
            assignment.setData(data);
            toast.success('Assignment saved.');
            setEditing(false);
        } catch (err) {
            setErrors(fieldErrors(err));
            if (!Object.keys(fieldErrors(err)).length) toast.error(errorMessage(err));
        } finally {
            setSaving(false);
        }
    };

    return (
        <Card>
            <CardHeader
                title="Factory & department"
                description="Where this user works. Roles decide what they may do."
                actions={editable && <Button size="sm" variant="secondary" icon={<Pencil className="h-4 w-4" />} onClick={() => setEditing(true)}>Change</Button>}
            />
            <CardBody>
                {assignment.error ? (
                    <Alert tone="danger">{assignment.error}</Alert>
                ) : !u ? null : (
                    <DescriptionList
                        items={[
                            { label: 'Primary factory', value: u.factory ? (can('factories.view') ? <Link className="text-brand-700 hover:underline" to={`/factory-setup/factories/${u.factory.id}`}>{u.factory.name}</Link> : u.factory.name) : <span className="text-slate-400">Not assigned</span> },
                            { label: 'Primary department', value: u.department ? (can('departments.view') ? <Link className="text-brand-700 hover:underline" to={`/factory-setup/departments/${u.department.id}`}>{u.department.name}</Link> : u.department.name) : <span className="text-slate-400">Not assigned</span> },
                            {
                                label: 'Additional departments',
                                value: u.additional_departments?.length ? (
                                    <div className="flex flex-wrap gap-1">{u.additional_departments.map((d) => <Badge key={d.id}>{d.name}</Badge>)}</div>
                                ) : '—',
                            },
                        ]}
                    />
                )}
            </CardBody>

            <Modal open={editing} onClose={() => setEditing(false)} title="Change factory & department">
                <div className="space-y-4">
                    {(factories.loading || departments.loading) && <p className="flex items-center gap-2 text-slate-500"><MapPinned className="h-4 w-4" /> Loading options…</p>}
                    <SelectInput label="Primary factory" value={form.factory_id} onChange={(e) => setForm({ ...form, factory_id: e.target.value, primary_department_id: '' })} error={errors.factory_id}>
                        <option value="">Not assigned</option>
                        {(factories.data?.data ?? []).filter((f) => f.is_active || String(f.id) === form.factory_id).map((f) => <option key={f.id} value={f.id}>{f.name} ({f.code})</option>)}
                    </SelectInput>
                    <SelectInput label="Primary department" value={form.primary_department_id} onChange={(e) => setForm({ ...form, primary_department_id: e.target.value, department_ids: form.department_ids.filter((x) => String(x) !== e.target.value) })} error={errors.primary_department_id} disabled={!form.factory_id}>
                        <option value="">Not assigned</option>
                        {primaryOptions.map((d) => <option key={d.id} value={d.id}>{d.name} ({d.code})</option>)}
                    </SelectInput>
                    <fieldset>
                        <legend className="mb-1.5 text-sm font-medium text-slate-700">Additional departments</legend>
                        <div className="max-h-48 space-y-2 overflow-y-auto rounded-md border border-slate-200 p-3">
                            {additionalOptions.length === 0 && <p className="text-xs text-slate-500">No other departments available.</p>}
                            {additionalOptions.map((d) => (
                                <Checkbox
                                    key={d.id}
                                    label={`${d.name} (${d.code})`}
                                    description={d.factory?.name}
                                    checked={form.department_ids.includes(d.id)}
                                    onChange={(e) => setForm({ ...form, department_ids: e.target.checked ? [...form.department_ids, d.id] : form.department_ids.filter((x) => x !== d.id) })}
                                />
                            ))}
                        </div>
                        {errors.department_ids && <p className="mt-1 text-xs text-red-600">{errors.department_ids}</p>}
                    </fieldset>
                    <div className="flex justify-end gap-2 pt-2">
                        <Button variant="secondary" onClick={() => setEditing(false)} disabled={saving}>Cancel</Button>
                        <Button onClick={save} loading={saving}>Save</Button>
                    </div>
                </div>
            </Modal>
        </Card>
    );
}
