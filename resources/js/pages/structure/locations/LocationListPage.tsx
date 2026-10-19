import { useState } from 'react';
import { Link } from 'react-router-dom';
import { CloudOff, Eye, MapPin, Pencil, Plus } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useApi, useDebouncedValue } from '@/hooks/useApi';
import { Card } from '@/components/ui/Card';
import { Alert, Badge, EmptyState, PageHeader, StatusBadge } from '@/components/ui/Feedback';
import { ButtonLink } from '@/components/ui/Button';
import { Pagination, SortableTh, Table, Td, Th } from '@/components/ui/Table';
import { LoadingBlock } from '@/components/ui/Spinner';
import type { Paginated } from '@/types';
import { LOCATION_TYPES, type Location, type Warehouse } from '../types';
import { FilterBar, SearchInput, filterInputClass, useListParams } from '../components/ListControls';
import { ViewSwitch } from './ViewSwitch';

/** Searchable table view of storage locations. */
export default function LocationListPage() {
    const { can } = useAuth();
    const list = useListParams({ sort: 'code' });
    const [search, setSearch] = useState(list.get('search'));
    const debounced = useDebouncedValue(search);

    const { data, loading, error } = useApi<Paginated<Location>>('/locations', {
        search: debounced, warehouse_id: list.get('warehouse_id'), type: list.get('type'), status: list.get('status'),
        is_virtual: list.get('is_virtual'), sort: list.sort, direction: list.direction, page: list.page, per_page: 25,
    });
    const warehouses = useApi<{ data: Warehouse[] }>('/warehouses', { all: 1 });

    return (
        <>
            <PageHeader
                title="Storage Locations"
                description="Zones, racks, shelves and bins inside warehouses. Configuration only — no stock quantities."
                actions={
                    <>
                        <ViewSwitch current="table" />
                        {can('locations.create') && <ButtonLink to="/factory-setup/locations/new" icon={<Plus className="h-4 w-4" />}>Add location</ButtonLink>}
                    </>
                }
            />
            <Card>
                <FilterBar>
                    <SearchInput value={search} onChange={(v) => { setSearch(v); list.update({ search: v }); }} placeholder="Search code or name" />
                    <select aria-label="Filter by warehouse" value={list.get('warehouse_id')} onChange={(e) => list.update({ warehouse_id: e.target.value })} className={filterInputClass}>
                        <option value="">All warehouses</option>
                        {warehouses.data?.data.map((w) => <option key={w.id} value={w.id}>{w.name} ({w.factory?.code})</option>)}
                    </select>
                    <select aria-label="Filter by type" value={list.get('type')} onChange={(e) => list.update({ type: e.target.value })} className={filterInputClass}>
                        <option value="">All types</option>
                        {LOCATION_TYPES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                    </select>
                    <select aria-label="Filter by virtual" value={list.get('is_virtual')} onChange={(e) => list.update({ is_virtual: e.target.value })} className={filterInputClass}>
                        <option value="">Physical & virtual</option>
                        <option value="0">Physical only</option>
                        <option value="1">Virtual only</option>
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
                    <EmptyState icon={<MapPin className="h-6 w-6" />} title={list.hasFilters ? 'No locations match your filters' : 'No locations yet'} />
                ) : data ? (
                    <div className={loading ? 'opacity-60' : undefined}>
                        <Table>
                            <thead>
                                <tr>
                                    <SortableTh label="Code" field="code" sort={list.sort} direction={list.direction} onSort={list.onSort} />
                                    <SortableTh label="Name" field="name" sort={list.sort} direction={list.direction} onSort={list.onSort} />
                                    <Th className="hidden md:table-cell">Warehouse</Th>
                                    <Th className="hidden lg:table-cell">Parent</Th>
                                    <SortableTh label="Type" field="type" sort={list.sort} direction={list.direction} onSort={list.onSort} className="hidden sm:table-cell" />
                                    <SortableTh label="Status" field="is_active" sort={list.sort} direction={list.direction} onSort={list.onSort} />
                                    <Th className="text-right"><span className="sr-only">Actions</span></Th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 bg-white">
                                {data.data.map((l) => (
                                    <tr key={l.id} className="hover:bg-slate-50">
                                        <Td className="font-mono text-xs font-semibold">{l.code}</Td>
                                        <Td>
                                            <Link to={`/factory-setup/locations/${l.id}`} className="font-medium text-slate-900 hover:text-brand-700">{l.name}</Link>
                                            {l.is_virtual && <Badge tone="info" className="ml-2"><CloudOff className="mr-1 h-3 w-3" />Virtual</Badge>}
                                        </Td>
                                        <Td className="hidden md:table-cell">{l.warehouse?.name}</Td>
                                        <Td className="hidden font-mono text-xs lg:table-cell">{l.parent?.code ?? '—'}</Td>
                                        <Td className="hidden sm:table-cell"><Badge>{l.type_label}</Badge></Td>
                                        <Td><StatusBadge active={l.is_active} /></Td>
                                        <Td className="whitespace-nowrap text-right">
                                            <div className="flex justify-end gap-1">
                                                <ButtonLink to={`/factory-setup/locations/${l.id}`} variant="ghost" size="sm" aria-label={`View ${l.code}`} icon={<Eye className="h-4 w-4" />} />
                                                {can('locations.update') && <ButtonLink to={`/factory-setup/locations/${l.id}/edit`} variant="ghost" size="sm" aria-label={`Edit ${l.code}`} icon={<Pencil className="h-4 w-4" />} />}
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
        </>
    );
}
