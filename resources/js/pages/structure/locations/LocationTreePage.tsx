import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { MapPin, Plus } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useApi } from '@/hooks/useApi';
import { Card, CardBody } from '@/components/ui/Card';
import { Alert, EmptyState, PageHeader } from '@/components/ui/Feedback';
import { ButtonLink } from '@/components/ui/Button';
import { LoadingBlock } from '@/components/ui/Spinner';
import type { Factory, LocationNode } from '../types';
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

/** Location Tree: pick a factory, then browse its storage location hierarchy. */
export default function LocationTreePage() {
    const { can } = useAuth();
    const [params, setParams] = useSearchParams();
    const factoryId = params.get('factory_id') ?? '';
    const [status, setStatus] = useState('');
    const [search, setSearch] = useState('');

    const factories = useApi<{ data: Factory[] }>('/factories', { all: 1 });
    const tree = useApi<{ data: { total: number; tree: LocationNode[]; factory: { is_active: boolean } } }>(
        factoryId ? `/factories/${factoryId}/locations/tree` : null,
        { status },
    );

    // Single factory: select it automatically.
    useEffect(() => {
        const list = factories.data?.data ?? [];
        if (!factoryId && list.length === 1) setParams({ factory_id: String(list[0].id) }, { replace: true });
    }, [factories.data, factoryId, setParams]);

    const nodes = useMemo(() => filterTree(tree.data?.data.tree ?? [], search.trim()), [tree.data, search]);
    const canAdd = can('locations.create') && Boolean(tree.data?.data.factory.is_active);

    return (
        <>
            <PageHeader
                title="Location Tree"
                description="Hierarchy of storage locations within a factory."
                actions={
                    <>
                        <ViewSwitch current="tree" />
                        {canAdd && <ButtonLink to={`/factory-setup/locations/new?factory_id=${factoryId}`} icon={<Plus className="h-4 w-4" />}>Add location</ButtonLink>}
                    </>
                }
            />
            <Card>
                <div className="grid grid-cols-1 gap-3 border-b border-slate-200 p-4 sm:grid-cols-3">
                    <select aria-label="Factory" value={factoryId} onChange={(e) => setParams(e.target.value ? { factory_id: e.target.value } : {}, { replace: true })} className={filterInputClass}>
                        <option value="">Select factory</option>
                        {factories.data?.data.map((f) => <option key={f.id} value={f.id}>{f.name} ({f.code}){f.is_active ? '' : ' — inactive'}</option>)}
                    </select>
                    <SearchInput value={search} onChange={setSearch} placeholder="Find location in tree" />
                    <select aria-label="Status" value={status} onChange={(e) => setStatus(e.target.value)} className={filterInputClass}>
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <CardBody>
                    {factories.loading ? (
                        <LoadingBlock />
                    ) : !factoryId ? (
                        <EmptyState icon={<MapPin className="h-6 w-6" />} title="Select a factory" description={factories.data?.data.length ? 'Choose a factory to view its locations.' : 'Create a factory first.'} />
                    ) : tree.error ? (
                        <Alert tone="danger">{tree.error}</Alert>
                    ) : tree.loading && !tree.data ? (
                        <LoadingBlock />
                    ) : nodes.length === 0 ? (
                        <EmptyState icon={<MapPin className="h-6 w-6" />} title={search ? 'No matching locations' : 'No locations in this factory'} />
                    ) : (
                        <LocationTree
                            key={`${factoryId}-${search}-${status}`}
                            nodes={nodes}
                            canAddChild={canAdd}
                            addChildHref={(n) => `/factory-setup/locations/new?factory_id=${factoryId}&parent_id=${n.id}`}
                        />
                    )}
                </CardBody>
            </Card>
        </>
    );
}
