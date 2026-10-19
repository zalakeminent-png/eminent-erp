import { Link, useParams } from 'react-router-dom';
import { KeySquare, Pencil } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useApi } from '@/hooks/useApi';
import { Card, CardBody, CardHeader, DescriptionList } from '@/components/ui/Card';
import { Alert, Badge, EmptyState, PageHeader, StatusBadge } from '@/components/ui/Feedback';
import { ButtonLink } from '@/components/ui/Button';
import { Table, Td, Th } from '@/components/ui/Table';
import { LoadingBlock } from '@/components/ui/Spinner';
import { formatDateTime, humanize } from '@/lib/format';
import type { Role, User } from '@/types';

export default function RoleDetailsPage() {
    const { id } = useParams();
    const { can } = useAuth();
    const { data, loading, error } = useApi<{ data: Role; users: User[] }>(`/roles/${id}`);

    if (loading) return <LoadingBlock />;
    if (error || !data) return <Alert tone="danger">{error ?? 'Role not found.'}</Alert>;

    const role = data.data;
    const grouped = (role.permissions ?? []).reduce<Record<string, string[]>>((acc, name) => {
        const [module, action] = name.split('.');
        (acc[module] ??= []).push(action);
        return acc;
    }, {});

    return (
        <>
            <PageHeader
                title={role.name}
                breadcrumb={<Link to="/settings/roles" className="hover:text-slate-700">Roles & Permissions</Link>}
                description={role.description ?? undefined}
                actions={
                    <>
                        <ButtonLink to={`/settings/roles/${role.id}/permissions`} variant="secondary" icon={<KeySquare className="h-4 w-4" />}>
                            {can('roles.assign-permissions') && !role.is_primary_admin ? 'Assign permissions' : 'View permission matrix'}
                        </ButtonLink>
                        {can('roles.update') && <ButtonLink to={`/settings/roles/${role.id}/edit`} icon={<Pencil className="h-4 w-4" />}>Edit</ButtonLink>}
                    </>
                }
            />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <Card>
                    <CardHeader title="Role details" />
                    <CardBody>
                        <DescriptionList
                            items={[
                                { label: 'Status', value: <StatusBadge active={role.is_active} /> },
                                { label: 'Type', value: role.is_system ? <Badge tone="info">Core role</Badge> : <Badge>Custom role</Badge> },
                                { label: 'Guard', value: <code className="text-xs">{role.guard_name}</code> },
                                { label: 'Users', value: role.users_count },
                                { label: 'Created', value: formatDateTime(role.created_at) },
                                { label: 'Last updated', value: formatDateTime(role.updated_at) },
                            ]}
                        />
                    </CardBody>
                </Card>

                <Card className="lg:col-span-2">
                    <CardHeader title="Permissions" description={`${role.permissions_count ?? 0} granted`} />
                    <CardBody>
                        {Object.keys(grouped).length === 0 ? (
                            <p className="text-sm text-slate-500">This role has no permissions yet.</p>
                        ) : (
                            <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                {Object.entries(grouped).map(([module, actions]) => (
                                    <div key={module}>
                                        <dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">{humanize(module)}</dt>
                                        <dd className="mt-1.5 flex flex-wrap gap-1">
                                            {actions.map((a) => <Badge key={a} tone="brand">{humanize(a)}</Badge>)}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        )}
                    </CardBody>
                </Card>
            </div>

            <Card className="mt-6">
                <CardHeader title="Users with this role" description={`${role.users_count ?? 0} assigned`} />
                {data.users.length === 0 ? (
                    <EmptyState title="No users assigned" description={role.is_system ? undefined : 'Unused custom roles can be deleted.'} />
                ) : (
                    <Table>
                        <thead>
                            <tr>
                                <Th>Name</Th>
                                <Th className="hidden sm:table-cell">Email</Th>
                                <Th>Status</Th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {data.users.map((u) => (
                                <tr key={u.id}>
                                    <Td>
                                        {can('users.view') ? (
                                            <Link to={`/settings/users/${u.id}`} className="font-medium text-brand-700 hover:underline">{u.full_name}</Link>
                                        ) : (
                                            u.full_name
                                        )}
                                    </Td>
                                    <Td className="hidden sm:table-cell">{u.email}</Td>
                                    <Td><StatusBadge active={u.is_active} /></Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </Card>
        </>
    );
}
