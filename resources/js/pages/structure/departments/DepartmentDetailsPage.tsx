import { Link, useParams } from 'react-router-dom';
import { Pencil, Power, UserCog, Users } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useApi } from '@/hooks/useApi';
import { Card, CardBody, CardHeader, DescriptionList } from '@/components/ui/Card';
import { Alert, Badge, PageHeader, StatusBadge } from '@/components/ui/Feedback';
import { Button, ButtonLink } from '@/components/ui/Button';
import { LoadingBlock } from '@/components/ui/Spinner';
import { formatDateTime } from '@/lib/format';
import type { Department } from '../types';
import { useStatusAction } from '../components/useStatusAction';

export default function DepartmentDetailsPage() {
    const { id } = useParams();
    const { can } = useAuth();
    const { data, loading, error, setData } = useApi<{ data: Department }>(`/departments/${id}`);
    const status = useStatusAction<Department>('departments', 'department', (d) => setData({ data: d }), 'Assigned employees keep their assignment.');

    if (loading) return <LoadingBlock />;
    if (error || !data) return <Alert tone="danger">{error ?? 'Department not found.'}</Alert>;
    const d = data.data;

    const person = (u: Department['manager']) =>
        u ? <span>{u.full_name}{!u.is_active && <Badge tone="danger" className="ml-2">Inactive user</Badge>}</span> : <span className="text-slate-400">Not assigned</span>;

    return (
        <>
            <PageHeader
                title={d.name}
                breadcrumb={<Link to="/factory-setup/departments" className="hover:text-slate-700">Departments</Link>}
                description={<span className="font-mono">{d.code}</span>}
                actions={
                    <>
                        <ButtonLink to={`/factory-setup/departments/${d.id}/employees`} variant="secondary" icon={<Users className="h-4 w-4" />}>Employees</ButtonLink>
                        {can('departments.assign-managers') && <ButtonLink to={`/factory-setup/departments/${d.id}/leaders`} variant="secondary" icon={<UserCog className="h-4 w-4" />}>Manager / Supervisor</ButtonLink>}
                        {can(d.is_active ? 'departments.deactivate' : 'departments.update') && (
                            <Button variant={d.is_active ? 'danger' : 'secondary'} icon={<Power className="h-4 w-4" />} onClick={() => status.request(d)}>{d.is_active ? 'Deactivate' : 'Activate'}</Button>
                        )}
                        {can('departments.update') && <ButtonLink to={`/factory-setup/departments/${d.id}/edit`} icon={<Pencil className="h-4 w-4" />}>Edit</ButtonLink>}
                    </>
                }
            />
            {!d.is_active && <Alert tone="warning" className="mb-6">This department is inactive and cannot receive new user assignments.</Alert>}
            <Card>
                <CardHeader title="Details" />
                <CardBody>
                    <DescriptionList
                        items={[
                            { label: 'Department ID', value: <span className="font-mono">#{d.id}</span> },
                            { label: 'Factory', value: d.factory ? <Link className="text-brand-700 hover:underline" to={`/factory-setup/factories/${d.factory.id}`}>{d.factory.name}</Link> : '—' },
                            { label: 'Type', value: d.type?.name ?? '—' },
                            { label: 'Status', value: <StatusBadge active={d.is_active} /> },
                            { label: 'Manager', value: person(d.manager) },
                            { label: 'Supervisor', value: person(d.supervisor) },
                            { label: 'Employees', value: d.members_count ?? 0 },
                            { label: 'Description', value: d.description || '—' },
                            { label: 'Created by', value: d.created_by?.full_name ?? '—' },
                            { label: 'Created', value: formatDateTime(d.created_at) },
                            { label: 'Last updated', value: formatDateTime(d.updated_at) },
                        ]}
                    />
                </CardBody>
            </Card>
            {status.dialog}
        </>
    );
}
