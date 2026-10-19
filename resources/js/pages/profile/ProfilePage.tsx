import { useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '@/auth/AuthContext';
import { Card, CardBody, CardHeader, DescriptionList } from '@/components/ui/Card';
import { Badge, PageHeader, StatusBadge } from '@/components/ui/Feedback';
import { TextInput } from '@/components/ui/Form';
import { Button } from '@/components/ui/Button';
import { useToast } from '@/components/ui/Toast';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { formatDateTime, initials } from '@/lib/format';
import type { AuthUser } from '@/types';

export default function ProfilePage() {
    const { user, setUser } = useAuth();
    const toast = useToast();
    const [form, setForm] = useState({ first_name: user?.first_name ?? '', last_name: user?.last_name ?? '', mobile: user?.mobile ?? '' });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    if (!user) return null;

    const onSubmit = async (event: FormEvent) => {
        event.preventDefault();
        setSaving(true);
        setErrors({});
        try {
            const { data } = await api.put<{ data: AuthUser }>('/auth/profile', { ...form, mobile: form.mobile || null });
            setUser(data.data);
            toast.success('Profile updated.');
        } catch (err) {
            setErrors(fieldErrors(err));
            toast.error(errorMessage(err));
        } finally {
            setSaving(false);
        }
    };

    return (
        <>
            <PageHeader title="My Profile" description="Your account details. Contact an administrator to change your email or roles." />
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <Card className="lg:col-span-1">
                    <CardBody className="flex flex-col items-center text-center">
                        <span className="flex h-16 w-16 items-center justify-center rounded-full bg-brand-100 text-xl font-semibold text-brand-700">{initials(user.full_name)}</span>
                        <h2 className="mt-3 text-lg font-semibold text-slate-900">{user.full_name}</h2>
                        <p className="text-sm text-slate-500">{user.email}</p>
                        <div className="mt-3 flex flex-wrap justify-center gap-1.5">
                            {user.roles.map((role) => <Badge key={role.id} tone="brand">{role.name}</Badge>)}
                        </div>
                        <div className="mt-5 w-full text-left">
                            <DescriptionList
                                items={[
                                    { label: 'Employee code', value: user.employee_code ?? '—' },
                                    { label: 'Status', value: <StatusBadge active={user.is_active} /> },
                                    { label: 'Last login', value: formatDateTime(user.last_login_at) },
                                    { label: 'Permissions', value: `${user.permissions.length} granted` },
                                ]}
                            />
                        </div>
                        <Link to="/profile/password" className="mt-6 text-sm font-medium text-brand-600 hover:text-brand-700">Change password →</Link>
                    </CardBody>
                </Card>

                <Card className="lg:col-span-2">
                    <CardHeader title="Personal details" />
                    <form onSubmit={onSubmit}>
                        <CardBody className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <TextInput label="First name" value={form.first_name} onChange={(e) => setForm({ ...form, first_name: e.target.value })} error={errors.first_name} required />
                            <TextInput label="Last name" value={form.last_name} onChange={(e) => setForm({ ...form, last_name: e.target.value })} error={errors.last_name} required />
                            <TextInput label="Email" value={user.email} disabled hint="Managed by your administrator." />
                            <TextInput label="Mobile number" type="tel" value={form.mobile} onChange={(e) => setForm({ ...form, mobile: e.target.value })} error={errors.mobile} />
                        </CardBody>
                        <div className="flex justify-end border-t border-slate-200 px-5 py-3">
                            <Button type="submit" loading={saving}>Save changes</Button>
                        </div>
                    </form>
                </Card>
            </div>
        </>
    );
}
