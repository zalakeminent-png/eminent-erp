import { Link } from 'react-router-dom';
import { ShieldCheck, UserCheck, UserX } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useApi } from '@/hooks/useApi';
import { Card, CardHeader } from '@/components/ui/Card';
import { Alert, PageHeader } from '@/components/ui/Feedback';
import { LoadingBlock } from '@/components/ui/Spinner';
import { ActivityFeed } from '@/pages/activity/ActivityFeed';
import type { ActivityLog } from '@/types';

interface DashboardData {
    company_name: string | null;
    stats: { active_users: number; inactive_users: number; total_roles: number };
    recent_activity: ActivityLog[] | null;
}

function greeting(): string {
    const hour = new Date().getHours();
    return hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
}

function StatCard({ label, value, icon, to }: { label: string; value: number; icon: React.ReactNode; to?: string }) {
    const body = (
        <div className="flex items-center gap-4 p-5">
            <div className="rounded-md bg-brand-50 p-2.5 text-brand-600">{icon}</div>
            <div>
                <p className="text-sm text-slate-500">{label}</p>
                <p className="text-2xl font-semibold tabular-nums text-slate-900">{value}</p>
            </div>
        </div>
    );
    return <Card className={to ? 'transition-shadow hover:shadow-md' : undefined}>{to ? <Link to={to}>{body}</Link> : body}</Card>;
}

export default function DashboardPage() {
    const { user, can } = useAuth();
    const allowed = can('dashboard.view');
    const { data, loading, error } = useApi<{ data: DashboardData }>(allowed ? '/dashboard' : null);
    const dashboard = data?.data;

    return (
        <>
            <PageHeader
                title={`${greeting()}, ${user?.first_name ?? ''}`}
                description={dashboard?.company_name ?? user?.company?.name ?? undefined}
            />

            {!allowed ? (
                <Card className="p-6 text-sm text-slate-600">
                    Welcome to the ERP. Use the navigation to open the modules assigned to your role.
                </Card>
            ) : loading ? (
                <LoadingBlock />
            ) : error ? (
                <Alert tone="danger">{error}</Alert>
            ) : dashboard ? (
                <div className="space-y-6">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <StatCard label="Active users" value={dashboard.stats.active_users} icon={<UserCheck className="h-5 w-5" />} to={can('users.view') ? '/settings/users?status=active' : undefined} />
                        <StatCard label="Inactive users" value={dashboard.stats.inactive_users} icon={<UserX className="h-5 w-5" />} to={can('users.view') ? '/settings/users?status=inactive' : undefined} />
                        <StatCard label="Roles" value={dashboard.stats.total_roles} icon={<ShieldCheck className="h-5 w-5" />} to={can('roles.view') ? '/settings/roles' : undefined} />
                    </div>

                    {dashboard.recent_activity && (
                        <Card>
                            <CardHeader
                                title="Recent administrative activity"
                                actions={<Link to="/settings/activity-logs" className="text-sm font-medium text-brand-600 hover:text-brand-700">View all</Link>}
                            />
                            <ActivityFeed logs={dashboard.recent_activity} />
                        </Card>
                    )}
                </div>
            ) : null}
        </>
    );
}
