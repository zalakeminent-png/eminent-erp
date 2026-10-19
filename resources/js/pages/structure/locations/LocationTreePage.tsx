import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { MapPin, Plus } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useApi } from '@/hooks/useApi';
import { Card, CardBody } from '@/components/ui/Card';
import { Alert, EmptyState, PageHeader } from '@/components/ui/Feedback';
import { ButtonLink } from '@/components/ui/Button';
import { LoadingBlock } from '@/components/ui/Spinner';
import type { LocationNode, Warehouse } from '../types';
import { LocationTree } from '../components/LocationTree';
import { SearchInput, filterInputClass } from '../components/ListControls';
import { ViewSwitch } from './ViewSwitch';

function filterTree(nodes: LocationNode[], term: string): LocationNode[] {
    if (!term) return nodes;
    const t = term.toLowerCase();
    return nodes.flatMap((n) => {
        if (n.code.toLowerCase().includes(t) || n.name.toLowerCase().includes(t)) return [n];
        const children = filterTree(n.children, term);
        return children.length ? [{ ...n, children }] : [];
    });
}

/** Location Tree: pick a warehouse, then browse its hierarchy. */
export default function LocationTreePage() {
    const { can } = useAuth();
    const [params, setParams] = useSearchParams();
    const warehouseId = params.get('warehouse_id') ?? '';
    const [status, setStatus] = useState('');
    const [search, setSearch] = useState('');

    const warehouses = useApi<{ data: Warehouse[] }>('/warehouses', { all: 1 });
    const tree = useApi<{ data: { total: number; tree: LocationNode[]; warehouse: { is_active: boolean } } }>(
        warehouseId ? `/warehouses/${warehouseId}/locations/tree` : null,
        { status },
    );

    // Single warehouse: select it automatically.
    useEffect(() => {
        const list = warehouses.data?.data ?? [];
        if (!warehouseId && list.length === 1) setParams({ warehouse_id: String(list[0].id) }, { replace: true });
    }, [warehouses.data, warehouseId, setParams]);

    const nodes = useMemo(() => filterTree(tree.data?.data.tree ?? [], search.trim()), [tree.data, search]);
    const canAdd = can('locations.create') && Boolean(tree.data?.data.warehouse.is_active);

    return (
        <>
            <PageHeader
                title="Location Tree"
                description="Hierarchy of storage locations within a warehouse."
                actions={
                    <>
                        <ViewSwitch current="tree" />
                        {canAdd && <ButtonLink to={`/factory-setup/locations/new?warehouse_id=${warehouseId}`} icon={<Plus className="h-4 w-4" />}>Add location</ButtonLink>}
                    </>
                }
            />
            <Card>
                <div className="grid grid-cols-1 gap-3 border-b border-slate-200 p-4 sm:grid-cols-3">
                    <select aria-label="Warehouse" value={warehouseId} onChange={(e) => setParams(e.target.value ? { warehouse_id: e.target.value } : {}, { replace: true })} className={filterInputClass}>
                        <option value="">Select warehouse</option>
                        {warehouses.data?.data.map((w) => <option key={w.id} value={w.id}>{w.name} ({w.factory?.code}){w.is_active ? '' : ' — inactive'}</option>)}
                    </select>
                    <SearchInput value={search} onChange={setSearch} placeholder="Find location in tree" />
                    <select aria-label="Status" value={status} onChange={(e) => setStatus(e.target.value)} className={filterInputClass}>
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <CardBody>
                    {warehouses.loading ? (
                        <LoadingBlock />
                    ) : !warehouseId ? (
                        <EmptyState icon={<MapPin className="h-6 w-6" />} title="Select a warehouse" description={warehouses.data?.data.length ? 'Choose a warehouse to view its locations.' : 'Create a warehouse first.'} />
                    ) : tree.error ? (
                        <Alert tone="danger">{tree.error}</Alert>
                    ) : tree.loading && !tree.data ? (
                        <LoadingBlock />
                    ) : nodes.length === 0 ? (
                        <EmptyState icon={<MapPin className="h-6 w-6" />} title={search ? 'No matching locations' : 'No locations in this warehouse'} />
                    ) : (
                        <LocationTree
                            key={`${warehouseId}-${search}-${status}`}
                            nodes={nodes}
                            canAddChild={canAdd}
                            addChildHref={(n) => `/factory-setup/locations/new?warehouse_id=${warehouseId}&parent_id=${n.id}`}
                        />
                    )}
                </CardBody>
            </Card>
        </>
    );
}
