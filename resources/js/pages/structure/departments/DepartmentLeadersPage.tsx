import { useEffect, useState, type FormEvent } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useApi } from '@/hooks/useApi';
import { Card, CardBody } from '@/components/ui/Card';
import { Alert, PageHeader } from '@/components/ui/Feedback';
import { Button, ButtonLink } from '@/components/ui/Button';
import { LoadingBlock } from '@/components/ui/Spinner';
import { useToast } from '@/components/ui/Toast';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import type { Department, UserSummary } from '../types';
import { UserPicker } from '../components/UserPicker';

/** Department Manager / Supervisor Assignment. */
export default function DepartmentLeadersPage() {
    const { id } = useParams();
    const navigate = useNavigate();
    const toast = useToast();
    const { data, loading, error } = useApi<{ data: Department }>(`/departments/${id}`);
    const [manager, setManager] = useState<UserSummary | null>(null);
    const [supervisor, setSupervisor] = useState<UserSummary | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (data?.data) {
            setManager(data.data.manager ?? null);
            setSupervisor(data.data.supervisor ?? null);
        }
    }, [data]);

    if (loading) return <LoadingBlock />;
    if (error || !data) return <Alert tone="danger">{error ?? 'Department not found.'}</Alert>;
    const d = data.data;

    const onSubmit = async (event: FormEvent) => {
        event.preventDefault();
        if (saving) return;
        setSaving(true);
        setErrors({});
        try {
            await api.put(`/departments/${d.id}/supervisor`, { manager_id: manager?.id ?? null, supervisor_id: supervisor?.id ?? null });
            toast.success('Manager and supervisor saved.');
            navigate(`/factory-setup/departments/${d.id}`);
        } catch (err) {
            setErrors(fieldErrors(err));
            if (!Object.keys(fieldErrors(err)).length) toast.error(errorMessage(err));
        } finally {
            setSaving(false);
        }
    };

    return (
        <form onSubmit={onSubmit}>
            <PageHeader
                title="Manager & supervisor"
                breadcrumb={<><Link to="/factory-setup/departments" className="hover:text-slate-700">Departments</Link> / <Link to={`/factory-setup/departments/${d.id}`} className="hover:text-slate-700">{d.name}</Link></>}
                description="Both are optional. Only active users can be assigned."
            />
            <Card className="max-w-2xl">
                <CardBody className="space-y-5">
                    {(manager && !manager.is_active) || (supervisor && !supervisor.is_active) ? (
                        <Alert tone="warning">An assigned user is inactive. Choose an active user or clear the field before saving.</Alert>
                    ) : null}
                    <UserPicker label="Department manager" value={manager} onChange={setManager} error={errors.manager_id} />
                    <UserPicker label="Department supervisor" value={supervisor} onChange={setSupervisor} error={errors.supervisor_id} />
                </CardBody>
                <div className="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
                    <ButtonLink to={`/factory-setup/departments/${d.id}`} variant="secondary">Cancel</ButtonLink>
                    <Button type="submit" loading={saving}>Save</Button>
                </div>
            </Card>
        </form>
    );
}
