import { Link, useParams } from 'react-router-dom';
import { KeyRound, Pencil, Power } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useApi } from '@/hooks/useApi';
import { Card, CardBody, CardHeader, DescriptionList } from '@/components/ui/Card';
import { Alert, Badge, PageHeader, StatusBadge } from '@/components/ui/Feedback';
import { Button, ButtonLink } from '@/components/ui/Button';
import { LoadingBlock } from '@/components/ui/Spinner';
import { formatDateTime, initials } from '@/lib/format';
import { ActivityFeed } from '@/pages/activity/ActivityFeed';
import type { ActivityLog, Paginated, User } from '@/types';
import { UserAssignmentsCard } from './UserAssignmentsCard';
import { useUserActions } from './useUserActions';

export default function UserDetailsPage() {
    const { id } = useParams();
    const { can, user: me } = useAuth();
    const { data, loading, error, setData, reload } = useApi<{ data: User }>(`/users/${id}`);
    const activity = useApi<Paginated<ActivityLog>>(can('activity-logs.view') ? '/activity-logs' : null, { entity_type: 'user', entity_id: id, per_page: 10 });
    const actions = useUserActions((updated) => {
        if (updated) setData({ data: { ...updated, roles: data?.data.roles } });
        else reload();
        activity.reload();
    });

    if (loading) return <LoadingBlock />;
    if (error || !data) return <Alert tone="danger">{error ?? 'User not found.'}</Alert>;

    const user = data.data;
    const isSelf = user.id === me?.id;

    return (
        <>
            <PageHeader
                title={user.full_name}
                breadcrumb={<Link to="/settings/users" className="hover:text-slate-700">Users</Link>}
                description={user.email}
                actions={
                    <>
                        {user.is_active && can('users.reset-password') && (
                            <Button variant="secondary" icon={<KeyRound className="h-4 w-4" />} onClick={() => actions.sendReset(user)}>Send password reset</Button>
                        )}
                        {!isSelf && can(user.is_active ? 'users.deactivate' : 'users.activate') && (
                            <Button variant={user.is_active ? 'danger' : 'secondary'} icon={<Power className="h-4 w-4" />} onClick={() => actions.toggleStatus(user)}>
                                {user.is_active ? 'Deactivate' : 'Activate'}
                            </Button>
                        )}
                        {can('users.update') && <ButtonLink to={`/settings/users/${user.id}/edit`} icon={<Pencil className="h-4 w-4" />}>Edit</ButtonLink>}
                    </>
                }
            />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader title="Account" />
                    <CardBody>
                        <div className="mb-6 flex items-center gap-4">
                            <span className="flex h-14 w-14 items-center justify-center rounded-full bg-brand-100 text-lg font-semibold text-brand-700">{initials(user.full_name)}</span>
                            <div>
                                <p className="font-medium text-slate-900">{user.full_name}</p>
                                <div className="mt-1"><StatusBadge active={user.is_active} /></div>
                            </div>
                        </div>
                        <DescriptionList
                            items={[
                                { label: 'User ID', value: <span className="font-mono">#{user.id}</span> },
                                { label: 'Employee code', value: user.employee_code ?? '—' },
                                { label: 'First name', value: user.first_name },
                                { label: 'Last name', value: user.last_name },
                                { label: 'Email', value: user.email },
                                { label: 'Mobile', value: user.mobile ?? '—' },
                                { label: 'Last login', value: formatDateTime(user.last_login_at, 'Never') },
                                { label: 'Created', value: formatDateTime(user.created_at) },
                                { label: 'Last updated', value: formatDateTime(user.updated_at) },
                                ...(user.deactivated_at ? [{ label: 'Deactivated', value: formatDateTime(user.deactivated_at) }] : []),
                            ]}
                        />
                    </CardBody>
                </Card>

                <Card>
                    <CardHeader title="Assigned roles" />
                    <CardBody className="space-y-2">
                        {user.roles?.length ? (
                            user.roles.map((role) => (
                                <div key={role.id} className="flex items-center justify-between rounded-md border border-slate-200 px-3 py-2">
                                    {can('roles.view') ? (
                                        <Link to={`/settings/roles/${role.id}`} className="text-sm font-medium text-brand-700 hover:underline">{role.name}</Link>
                                    ) : (
                                        <span className="text-sm font-medium text-slate-800">{role.name}</span>
                                    )}
                                    {!role.is_active && <Badge>Inactive role</Badge>}
                                </div>
                            ))
                        ) : (
                            <p className="text-sm text-slate-500">No roles assigned.</p>
                        )}
                    </CardBody>
                </Card>
            </div>

            <div className="mt-6">
                <UserAssignmentsCard userId={user.id} />
            </div>

            {can('activity-logs.view') && (
                <Card className="mt-6">
                    <CardHeader title="Recent changes to this user" />
                    {activity.loading ? <LoadingBlock /> : <ActivityFeed logs={activity.data?.data ?? []} />}
                </Card>
            )}
            {actions.dialog}
        </>
    );
}
