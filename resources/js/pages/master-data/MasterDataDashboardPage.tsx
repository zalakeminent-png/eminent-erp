import { Link } from 'react-router-dom';
import { Boxes, ClipboardCheck, Cog, FileSpreadsheet, FolderTree, GitBranch, Layers, Ruler, Users, Workflow, Wrench } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useApi } from '@/hooks/useApi';
import { Card, CardHeader } from '@/components/ui/Card';
import { Alert, PageHeader } from '@/components/ui/Feedback';
import { ButtonLink } from '@/components/ui/Button';
import { LoadingBlock } from '@/components/ui/Spinner';
import { formatDateTime } from '@/lib/format';

type Counts = { active?: number; inactive?: number };
type ByStatus = Partial<Record<'draft' | 'approved' | 'inactive', number>>;
interface DashboardData {
    sections: {
        items?: Counts & { by_type: Record<string, number> };
        item_categories?: Counts; uoms?: Counts; material_grades?: Counts; processes?: Counts; machines?: Counts;
        boms?: ByStatus; routings?: ByStatus; qc_specifications?: ByStatus;
        business_partners?: { customers: number; suppliers: number; inactive: number };
    };
    recent_activity: { id: number; action: string; description: string | null; actor: string | null; created_at: string }[] | null;
}

function Tile({ to, icon, label, value, sub }: { to: string; icon: React.ReactNode; label: string; value: number | string; sub?: string }) {
    return (
        <Link to={to} className="block">
            <Card className="flex h-full items-center gap-4 p-4 transition-shadow hover:shadow-md">
                <div className="rounded-md bg-brand-50 p-2.5 text-brand-600">{icon}</div>
                <div>
                    <p className="text-sm text-slate-500">{label}</p>
                    <p className="text-2xl font-semibold tabular-nums text-slate-900">{value}</p>
                    {sub && <p className="text-xs text-slate-400">{sub}</p>}
                </div>
            </Card>
        </Link>
    );
}

const docSub = (s?: ByStatus) => (s ? `${s.draft ?? 0} draft · ${s.inactive ?? 0} inactive` : undefined);

export default function MasterDataDashboardPage() {
    const { can } = useAuth();
    const { data, loading, error } = useApi<{ data: DashboardData }>('/master-data/dashboard');
    if (loading) return <LoadingBlock />;
    if (error || !data) return <Alert tone="danger">{error ?? 'Unable to load.'}</Alert>;
    const s = data.data.sections;

    return (
        <>
            <PageHeader title="Master Data" description="Foundation records for inventory, orders, planning, production, quality and dispatch. Counts show real records only."
                actions={can('master-data.import') && <ButtonLink to="/master-data/import" variant="secondary" icon={<FileSpreadsheet className="h-4 w-4" />}>Import from Excel</ButtonLink>} />
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {s.items && <Tile to="/master-data/items" icon={<Boxes className="h-5 w-5" />} label="Active items" value={s.items.active ?? 0} sub={`${s.items.inactive ?? 0} inactive`} />}
                {s.item_categories && <Tile to="/master-data/categories" icon={<FolderTree className="h-5 w-5" />} label="Categories" value={s.item_categories.active ?? 0} />}
                {s.uoms && <Tile to="/master-data/uoms" icon={<Ruler className="h-5 w-5" />} label="Units of measure" value={s.uoms.active ?? 0} />}
                {s.material_grades && <Tile to="/master-data/material-grades" icon={<Layers className="h-5 w-5" />} label="Material grades" value={s.material_grades.active ?? 0} />}
                {s.processes && <Tile to="/master-data/processes" icon={<Cog className="h-5 w-5" />} label="Processes" value={s.processes.active ?? 0} />}
                {s.machines && <Tile to="/master-data/machines" icon={<Wrench className="h-5 w-5" />} label="Machines" value={s.machines.active ?? 0} />}
                {s.boms && <Tile to="/master-data/boms" icon={<GitBranch className="h-5 w-5" />} label="Approved BOMs" value={s.boms.approved ?? 0} sub={docSub(s.boms)} />}
                {s.routings && <Tile to="/master-data/routings" icon={<Workflow className="h-5 w-5" />} label="Approved routings" value={s.routings.approved ?? 0} sub={docSub(s.routings)} />}
                {s.qc_specifications && <Tile to="/master-data/qc-specifications" icon={<ClipboardCheck className="h-5 w-5" />} label="Approved QC specs" value={s.qc_specifications.approved ?? 0} sub={docSub(s.qc_specifications)} />}
                {s.business_partners && <Tile to="/master-data/partners" icon={<Users className="h-5 w-5" />} label="Customers / suppliers" value={`${s.business_partners.customers} / ${s.business_partners.suppliers}`} sub={`${s.business_partners.inactive} inactive`} />}
            </div>
            {data.data.recent_activity && (
                <Card className="mt-6">
                    <CardHeader title="Recent master data changes" actions={<Link to="/settings/activity-logs" className="text-sm font-medium text-brand-600">View all</Link>} />
                    {data.data.recent_activity.length === 0 ? <p className="p-5 text-sm text-slate-500">No changes yet.</p> : (
                        <ul className="divide-y divide-slate-100">
                            {data.data.recent_activity.map((a) => (
                                <li key={a.id} className="px-5 py-3 text-sm">
                                    <span className="font-medium">{a.actor ?? 'System'}</span> <span className="text-slate-600">{a.description ?? a.action}</span>
                                    <span className="block text-xs text-slate-400"><code>{a.action}</code> · {formatDateTime(a.created_at)}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            )}
        </>
    );
}
