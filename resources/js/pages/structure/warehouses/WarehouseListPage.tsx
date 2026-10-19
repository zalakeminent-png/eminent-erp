import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Eye, Pencil, Plus, Power, Warehouse as WarehouseIcon } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useApi, useDebouncedValue } from '@/hooks/useApi';
import { Card } from '@/components/ui/Card';
import { Alert, EmptyState, PageHeader, StatusBadge } from '@/components/ui/Feedback';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Pagination, SortableTh, Table, Td, Th } from '@/components/ui/Table';
import { LoadingBlock } from '@/components/ui/Spinner';
import type { Paginated } from '@/types';
import type { Factory, StructureType, Warehouse } from '../types';
import { FilterBar, SearchInput, filterInputClass, useListParams } from '../components/ListControls';
import { useStatusAction } from '../components/useStatusAction';

export default function WarehouseListPage() {
    const { can } = useAuth();
    const list = useListParams({ sort: 'name' });
    const [search, setSearch] = useState(list.get('search'));
    const debounced = useDebouncedValue(search);

    const { data, loading, error, reload } = useApi<Paginated<Warehouse>>('/warehouses', {
        search: debounced, factory_id: list.get('factory_id'), type_id: list.get('type_id'), status: list.get('status'),
        sort: list.sort, direction: list.direction, page: list.page, per_page: 15,
    });
    const factories = useApi<{ data: Factory[] }>('/factories', { all: 1 });
    const types = useApi<{ data: StructureType[] }>('/structure-types', { category: 'warehouse' });
    const status = useStatusAction<Warehouse>('warehouses', 'warehouse', () => reload(), 'Its locations cannot be selected for new transactions while inactive.');

    return (
        <>
            <PageHeader
                title="Warehouses"
                description="Storage areas within each factory. Independent of departments."
                actions={can('warehouses.create') && <ButtonLink to="/factory-setup/warehouses/new" icon={<Plus className="h-4 w-4" />}>Add warehouse</ButtonLink>}
            />
            <Card>
                <FilterBar>
                    <SearchInput value={search} onChange={(v) => { setSearch(v); list.update({ search: v }); }} placeholder="Search code or name" />
                    <select aria-label="Filter by factory" value={list.get('factory_id')} onChange={(e) => list.update({ factory_id: e.target.value })} className={filterInputClass}>
                        <option value="">All factories</option>
                        {factories.data?.data.map((f) => <option key={f.id} value={f.id}>{f.name}</option>)}
                    </select>
                    <select aria-label="Filter by type" value={list.get('type_id')} onChange={(e) => list.update({ type_id: e.target.value })} className={filterInputClass}>
                        <option value="">All types</option>
                        {types.data?.data.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
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
                    <EmptyState icon={<WarehouseIcon className="h-6 w-6" />} title={list.hasFilters ? 'No warehouses match your filters' : 'No warehouses yet'} />
                ) : data ? (
                    <div className={loading ? 'opacity-60' : undefined}>
                        <Table>
                            <thead>
                                <tr>
                                    <SortableTh label="Code" field="code" sort={list.sort} direction={list.direction} onSort={list.onSort} />
                                    <SortableTh label="Warehouse" field="name" sort={list.sort} direction={list.direction} onSort={list.onSort} />
                                    <Th className="hidden md:table-cell">Factory</Th>
                                    <Th className="hidden lg:table-cell">Responsible dept.</Th>
                                    <Th className="hidden text-right sm:table-cell">Locations</Th>
                                    <SortableTh label="Status" field="is_active" sort={list.sort} direction={list.direction} onSort={list.onSort} />
                                    <Th className="text-right"><span className="sr-only">Actions</span></Th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 bg-white">
                                {data.data.map((w) => (
                                    <tr key={w.id} className="hover:bg-slate-50">
                                        <Td className="font-mono text-xs font-semibold">{w.code}</Td>
                                        <Td>
                                            <Link to={`/factory-setup/warehouses/${w.id}`} className="font-medium text-slate-900 hover:text-brand-700">{w.name}</Link>
                                            {w.type && <p className="text-xs text-slate-500">{w.type.name}</p>}
                                        </Td>
                                        <Td className="hidden md:table-cell">{w.factory?.name}</Td>
                                        <Td className="hidden lg:table-cell">{w.department?.name ?? '—'}</Td>
                                        <Td className="hidden text-right tabular-nums sm:table-cell">{w.locations_count}</Td>
                                        <Td><StatusBadge active={w.is_active} /></Td>
                                        <Td className="whitespace-nowrap text-right">
                                            <div className="flex justify-end gap-1">
                                                <ButtonLink to={`/factory-setup/warehouses/${w.id}`} variant="ghost" size="sm" aria-label={`View ${w.name}`} icon={<Eye className="h-4 w-4" />} />
                                                {can('warehouses.update') && <ButtonLink to={`/factory-setup/warehouses/${w.id}/edit`} variant="ghost" size="sm" aria-label={`Edit ${w.name}`} icon={<Pencil className="h-4 w-4" />} />}
                                                {can(w.is_active ? 'warehouses.deactivate' : 'warehouses.update') && (
                                                    <Button variant="ghost" size="sm" onClick={() => status.request(w)} aria-label={`${w.is_active ? 'Deactivate' : 'Activate'} ${w.name}`} icon={<Power className={`h-4 w-4 ${w.is_active ? 'text-red-500' : 'text-emerald-600'}`} />} />
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
