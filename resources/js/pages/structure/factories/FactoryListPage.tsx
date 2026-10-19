import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Eye, Factory as FactoryIcon, Pencil, Plus, Power } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useApi, useDebouncedValue } from '@/hooks/useApi';
import { Card } from '@/components/ui/Card';
import { Alert, Badge, EmptyState, PageHeader, StatusBadge } from '@/components/ui/Feedback';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Pagination, SortableTh, Table, Td, Th } from '@/components/ui/Table';
import { LoadingBlock } from '@/components/ui/Spinner';
import type { Paginated } from '@/types';
import { FACTORY_TYPES, type Factory } from '../types';
import { FilterBar, SearchInput, filterInputClass, useListParams } from '../components/ListControls';
import { useStatusAction } from '../components/useStatusAction';

export default function FactoryListPage() {
    const { can } = useAuth();
    const list = useListParams({ sort: 'name' });
    const [search, setSearch] = useState(list.get('search'));
    const debounced = useDebouncedValue(search);

    const { data, loading, error, reload } = useApi<Paginated<Factory>>('/factories', {
        search: debounced, status: list.get('status'), type: list.get('type'),
        sort: list.sort, direction: list.direction, page: list.page, per_page: 15,
    });
    const status = useStatusAction<Factory>('factories', 'factory', () => reload(), 'Departments, warehouses and locations are not changed.');

    return (
        <>
            <PageHeader
                title="Factories"
                description="Physical sites operated by the company."
                actions={can('factories.create') && <ButtonLink to="/factory-setup/factories/new" icon={<Plus className="h-4 w-4" />}>Add factory</ButtonLink>}
            />
            <Card>
                <FilterBar>
                    <SearchInput value={search} onChange={(v) => { setSearch(v); list.update({ search: v }); }} placeholder="Search code, name or city" />
                    <select aria-label="Filter by type" value={list.get('type')} onChange={(e) => list.update({ type: e.target.value })} className={filterInputClass}>
                        <option value="">All types</option>
                        {FACTORY_TYPES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                    </select>
                    <select aria-label="Filter by status" value={list.get('status')} onChange={(e) => list.update({ status: e.target.value })} className={filterInputClass}>
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </FilterBar>

                {error ? (
                    <div className="p-4"><Alert tone="danger">{error}</Alert></div>
                ) : loading && !data ? (
                    <LoadingBlock />
                ) : data && data.data.length === 0 ? (
                    <EmptyState
                        icon={<FactoryIcon className="h-6 w-6" />}
                        title={list.hasFilters ? 'No factories match your filters' : 'No factories yet'}
                        description={list.hasFilters ? undefined : 'Create your factory to start setting up departments and warehouses.'}
                        action={!list.hasFilters && can('factories.create') ? <ButtonLink to="/factory-setup/factories/new">Add factory</ButtonLink> : undefined}
                    />
                ) : data ? (
                    <div className={loading ? 'opacity-60' : undefined}>
                        <Table>
                            <thead>
                                <tr>
                                    <SortableTh label="Code" field="code" sort={list.sort} direction={list.direction} onSort={list.onSort} />
                                    <SortableTh label="Factory" field="name" sort={list.sort} direction={list.direction} onSort={list.onSort} />
                                    <SortableTh label="Type" field="type" sort={list.sort} direction={list.direction} onSort={list.onSort} className="hidden md:table-cell" />
                                    <SortableTh label="City" field="city" sort={list.sort} direction={list.direction} onSort={list.onSort} className="hidden lg:table-cell" />
                                    <Th className="hidden text-right sm:table-cell">Depts / WH</Th>
                                    <SortableTh label="Status" field="is_active" sort={list.sort} direction={list.direction} onSort={list.onSort} />
                                    <Th className="text-right"><span className="sr-only">Actions</span></Th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 bg-white">
                                {data.data.map((f) => (
                                    <tr key={f.id} className="hover:bg-slate-50">
                                        <Td className="font-mono text-xs font-semibold">{f.code}</Td>
                                        <Td><Link to={`/factory-setup/factories/${f.id}`} className="font-medium text-slate-900 hover:text-brand-700">{f.name}</Link></Td>
                                        <Td className="hidden md:table-cell"><Badge>{f.type_label}</Badge></Td>
                                        <Td className="hidden lg:table-cell">{f.city ?? '—'}</Td>
                                        <Td className="hidden text-right tabular-nums sm:table-cell">{f.departments_count} / {f.warehouses_count}</Td>
                                        <Td><StatusBadge active={f.is_active} /></Td>
                                        <Td className="whitespace-nowrap text-right">
                                            <div className="flex justify-end gap-1">
                                                <ButtonLink to={`/factory-setup/factories/${f.id}`} variant="ghost" size="sm" aria-label={`View ${f.name}`} icon={<Eye className="h-4 w-4" />} />
                                                {can('factories.update') && <ButtonLink to={`/factory-setup/factories/${f.id}/edit`} variant="ghost" size="sm" aria-label={`Edit ${f.name}`} icon={<Pencil className="h-4 w-4" />} />}
                                                {can(f.is_active ? 'factories.deactivate' : 'factories.update') && (
                                                    <Button variant="ghost" size="sm" onClick={() => status.request(f)} aria-label={`${f.is_active ? 'Deactivate' : 'Activate'} ${f.name}`} icon={<Power className={`h-4 w-4 ${f.is_active ? 'text-red-500' : 'text-emerald-600'}`} />} />
                                                )}
                                            </div>
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                        <Pagination meta={data.meta} onPage={(p) => list.update({ page: String(p) })} />
                    </div>
                ) : null}
            </Card>
            {status.dialog}
        </>
    );
}
