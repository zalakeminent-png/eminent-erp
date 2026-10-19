import { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { Building, Building2, ChevronDown, ChevronRight, Download, Factory as FactoryIcon, Network, Warehouse as WarehouseIcon } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useApi, useDebouncedValue } from '@/hooks/useApi';
import { Card } from '@/components/ui/Card';
import { Alert, Badge, EmptyState, PageHeader } from '@/components/ui/Feedback';
import { Button } from '@/components/ui/Button';
import { LoadingBlock } from '@/components/ui/Spinner';
import { useToast } from '@/components/ui/Toast';
import { api, errorMessage } from '@/lib/api';
import { cx } from '@/lib/format';
import type { LocationNode } from './types';
import { LocationTree } from './components/LocationTree';
import { SearchInput, filterInputClass } from './components/ListControls';

interface StructureFactory {
    id: number;
    code: string;
    name: string;
    type_label: string;
    city: string | null;
    is_active: boolean;
    counts: { departments: number; warehouses: number; locations: number };
    departments: { id: number; code: string; name: string; is_active: boolean; type: string | null; manager: string | null; supervisor: string | null }[];
    warehouses: { id: number; code: string; name: string; is_active: boolean; type: string | null; department: string | null; locations_count: number; locations: LocationNode[] }[];
}

interface Structure {
    company: { id: number; name: string; code: string } | null;
    totals: { factories: number; departments: number; warehouses: number; locations: number };
    factories: StructureFactory[];
}

function Toggle({ open, onClick, label }: { open: boolean; onClick: () => void; label: string }) {
    return (
        <button type="button" onClick={onClick} className="rounded p-1 text-slate-400 hover:bg-slate-200" aria-expanded={open} aria-label={`${open ? 'Collapse' : 'Expand'} ${label}`}>
            {open ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
        </button>
    );
}

function Inactive({ active }: { active: boolean }) {
    return active ? null : <Badge className="ml-2">Inactive</Badge>;
}

/**
 * Company → Factory → Departments / Warehouses → Locations. Real records
 * only; stock balances are introduced by the Inventory module.
 */
export default function FactoryStructurePage() {
    const { can } = useAuth();
    const toast = useToast();
    const [params] = useSearchParams();
    const factoryId = params.get('factory');
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState('');
    const [collapsed, setCollapsed] = useState<Set<string>>(new Set());
    const [exporting, setExporting] = useState(false);
    const debounced = useDebouncedValue(search);

    const { data, loading, error } = useApi<{ data: Structure }>(factoryId ? `/factory-structure/${factoryId}` : '/factory-structure', { search: debounced, status });
    const s = data?.data;
    const searching = debounced.trim() !== '';

    const isOpen = (key: string) => searching || !collapsed.has(key);
    const toggle = (key: string) =>
        setCollapsed((prev) => {
            const next = new Set(prev);
            if (next.has(key)) next.delete(key);
            else next.add(key);
            return next;
        });

    const exportCsv = async () => {
        setExporting(true);
        try {
            const response = await api.get('/factory-structure/export', { params: { search: debounced || undefined, status: status || undefined }, responseType: 'blob' });
            const url = URL.createObjectURL(response.data as Blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `factory-structure-${new Date().toISOString().slice(0, 10)}.csv`;
            a.click();
            URL.revokeObjectURL(url);
        } catch (err) {
            toast.error(errorMessage(err, 'Export failed.'));
        } finally {
            setExporting(false);
        }
    };

    return (
        <>
            <PageHeader
                title="Factory Structure"
                description={s?.company ? `${s.company.name} — organisational and physical structure` : 'Organisational and physical structure'}
                actions={can('factory-structure.export') && <Button variant="secondary" icon={<Download className="h-4 w-4" />} loading={exporting} onClick={exportCsv}>Export CSV</Button>}
            />

            <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                {([
                    ['Factories', s?.totals.factories, FactoryIcon],
                    ['Departments', s?.totals.departments, Building],
                    ['Warehouses', s?.totals.warehouses, WarehouseIcon],
                    ['Locations', s?.totals.locations, Network],
                ] as const).map(([label, value, Icon]) => (
                    <Card key={label} className="flex items-center gap-3 p-4">
                        <Icon className="h-5 w-5 text-brand-600" aria-hidden="true" />
                        <div>
                            <p className="text-xl font-semibold tabular-nums text-slate-900">{value ?? '–'}</p>
                            <p className="text-xs text-slate-500">{label}</p>
                        </div>
                    </Card>
                ))}
            </div>

            <Card>
                <div className="grid grid-cols-1 gap-3 border-b border-slate-200 p-4 sm:grid-cols-[1fr_200px]">
                    <SearchInput value={search} onChange={setSearch} placeholder="Search factory, department, warehouse or location" />
                    <select aria-label="Status" value={status} onChange={(e) => setStatus(e.target.value)} className={filterInputClass}>
                        <option value="">All statuses</option>
                        <option value="active">Active only</option>
                        <option value="inactive">Inactive items</option>
                    </select>
                </div>
                {factoryId && (
                    <div className="border-b border-slate-200 bg-brand-50 px-4 py-2 text-sm text-brand-800">
                        Showing one factory. <Link to="/factory-setup/structure" className="font-medium underline">Show all</Link>
                    </div>
                )}

                {error ? (
                    <div className="p-4"><Alert tone="danger">{error}</Alert></div>
                ) : loading && !s ? (
                    <LoadingBlock />
                ) : !s?.factories.length ? (
                    <EmptyState
                        icon={<Network className="h-6 w-6" />}
                        title={searching || status ? 'Nothing matches your filters' : 'No factories set up yet'}
                        description={searching || status ? undefined : 'Create a factory, then add departments, warehouses and locations.'}
                    />
                ) : (
                    <div className={cx('p-2 sm:p-4', loading && 'opacity-60')}>
                        {s.company && (
                            <p className="mb-3 flex items-center gap-2 px-1 text-sm font-semibold text-slate-700">
                                <Building2 className="h-4 w-4 text-slate-400" aria-hidden="true" /> {s.company.name}
                            </p>
                        )}
                        <ul className="space-y-3">
                            {s.factories.map((f) => {
                                const fk = `f${f.id}`;
                                return (
                                    <li key={f.id} className="rounded-lg border border-slate-200">
                                        <div className="flex flex-wrap items-center gap-2 bg-slate-50 px-3 py-2.5">
                                            <Toggle open={isOpen(fk)} onClick={() => toggle(fk)} label={f.name} />
                                            <FactoryIcon className="h-4 w-4 text-brand-600" aria-hidden="true" />
                                            {can('factories.view') ? (
                                                <Link to={`/factory-setup/factories/${f.id}`} className="font-semibold text-slate-900 hover:text-brand-700">{f.name}</Link>
                                            ) : <span className="font-semibold text-slate-900">{f.name}</span>}
                                            <span className="font-mono text-xs text-slate-500">{f.code}</span>
                                            <Badge>{f.type_label}</Badge>
                                            <Inactive active={f.is_active} />
                                            <span className="ml-auto text-xs text-slate-500">
                                                {f.counts.departments} dept · {f.counts.warehouses} wh · {f.counts.locations} loc
                                            </span>
                                        </div>
                                        {isOpen(fk) && (
                                            <div className="grid grid-cols-1 gap-4 p-3 lg:grid-cols-2">
                                                <section>
                                                    <h3 className="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                                        <Building className="h-3.5 w-3.5" aria-hidden="true" /> Departments
                                                    </h3>
                                                    {f.departments.length === 0 ? (
                                                        <p className="px-1 text-sm text-slate-400">No departments.</p>
                                                    ) : (
                                                        <ul className="divide-y divide-slate-100 rounded-md border border-slate-100">
                                                            {f.departments.map((d) => (
                                                                <li key={d.id} className="px-3 py-2 text-sm">
                                                                    {can('departments.view') ? (
                                                                        <Link to={`/factory-setup/departments/${d.id}`} className="font-medium text-slate-800 hover:text-brand-700">{d.name}</Link>
                                                                    ) : <span className="font-medium">{d.name}</span>}
                                                                    <span className="ml-2 font-mono text-xs text-slate-400">{d.code}</span>
                                                                    <Inactive active={d.is_active} />
                                                                    {(d.manager || d.supervisor) && (
                                                                        <p className="text-xs text-slate-500">
                                                                            {d.manager && <>Manager: {d.manager}</>}
                                                                            {d.manager && d.supervisor && ' · '}
                                                                            {d.supervisor && <>Supervisor: {d.supervisor}</>}
                                                                        </p>
                                                                    )}
                                                                </li>
                                                            ))}
                                                        </ul>
                                                    )}
                                                </section>
                                                <section>
                                                    <h3 className="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                                        <WarehouseIcon className="h-3.5 w-3.5" aria-hidden="true" /> Warehouses & locations
                                                    </h3>
                                                    {f.warehouses.length === 0 ? (
                                                        <p className="px-1 text-sm text-slate-400">No warehouses.</p>
                                                    ) : (
                                                        <ul className="space-y-2">
                                                            {f.warehouses.map((w) => {
                                                                const wk = `w${w.id}`;
                                                                return (
                                                                    <li key={w.id} className="rounded-md border border-slate-100">
                                                                        <div className="flex flex-wrap items-center gap-1.5 px-2 py-1.5 text-sm">
                                                                            {w.locations.length > 0 ? <Toggle open={isOpen(wk)} onClick={() => toggle(wk)} label={w.name} /> : <span className="w-6" />}
                                                                            {can('warehouses.view') ? (
                                                                                <Link to={`/factory-setup/warehouses/${w.id}`} className="font-medium text-slate-800 hover:text-brand-700">{w.name}</Link>
                                                                            ) : <span className="font-medium">{w.name}</span>}
                                                                            <span className="font-mono text-xs text-slate-400">{w.code}</span>
                                                                            <Inactive active={w.is_active} />
                                                                            <span className="ml-auto text-xs text-slate-500">{w.locations_count} loc</span>
                                                                        </div>
                                                                        {w.locations.length > 0 && isOpen(wk) && (
                                                                            <div className="border-t border-slate-100 px-2 py-1">
                                                                                <LocationTree nodes={w.locations} defaultExpanded={searching} />
                                                                            </div>
                                                                        )}
                                                                    </li>
                                                                );
                                                            })}
                                                        </ul>
                                                    )}
                                                </section>
                                            </div>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                )}
            </Card>
        </>
    );
}
