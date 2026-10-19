import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { MapPin, Pencil, Plus, Power } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useApi } from '@/hooks/useApi';
import { Card, CardBody, CardHeader, DescriptionList } from '@/components/ui/Card';
import { Alert, EmptyState, PageHeader, StatusBadge } from '@/components/ui/Feedback';
import { Button, ButtonLink } from '@/components/ui/Button';
import { LoadingBlock } from '@/components/ui/Spinner';
import { formatDateTime } from '@/lib/format';
import type { LocationNode, Warehouse } from '../types';
import { LocationTree } from '../components/LocationTree';
import { filterInputClass } from '../components/ListControls';
import { useStatusAction } from '../components/useStatusAction';

interface TreeResponse {
    data: { total: number; max_depth: number; tree: LocationNode[] };
}

export default function WarehouseDetailsPage() {
    const { id } = useParams();
    const { can } = useAuth();
    const [treeStatus, setTreeStatus] = useState('');
    const { data, loading, error, setData } = useApi<{ data: Warehouse }>(`/warehouses/${id}`);
    const tree = useApi<TreeResponse>(can('locations.view') ? `/warehouses/${id}/locations/tree` : null, { status: treeStatus });
    const status = useStatusAction<Warehouse>('warehouses', 'warehouse', (w) => setData({ data: w }), 'Its locations cannot be selected for new transactions while inactive.');

    if (loading) return <LoadingBlock />;
    if (error || !data) return <Alert tone="danger">{error ?? 'Warehouse not found.'}</Alert>;
    const w = data.data;
    const canAdd = can('locations.create') && w.is_active;

    return (
        <>
            <PageHeader
                title={w.name}
                breadcrumb={<Link to="/factory-setup/warehouses" className="hover:text-slate-700">Warehouses</Link>}
                description={<span className="font-mono">{w.code}</span>}
                actions={
                    <>
                        {can(w.is_active ? 'warehouses.deactivate' : 'warehouses.update') && (
                            <Button variant={w.is_active ? 'danger' : 'secondary'} icon={<Power className="h-4 w-4" />} onClick={() => status.request(w)}>{w.is_active ? 'Deactivate' : 'Activate'}</Button>
                        )}
                        {can('warehouses.update') && <ButtonLink to={`/factory-setup/warehouses/${w.id}/edit`} icon={<Pencil className="h-4 w-4" />}>Edit</ButtonLink>}
                    </>
                }
            />
            {!w.is_active && <Alert tone="warning" className="mb-6">This warehouse is inactive. Its locations cannot be selected for new operations.</Alert>}

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-5">
                <Card className="lg:col-span-2">
                    <CardHeader title="Details" />
                    <CardBody>
                        <DescriptionList
                            items={[
                                { label: 'Warehouse ID', value: <span className="font-mono">#{w.id}</span> },
                                { label: 'Status', value: <StatusBadge active={w.is_active} /> },
                                { label: 'Factory', value: w.factory ? <Link className="text-brand-700 hover:underline" to={`/factory-setup/factories/${w.factory.id}`}>{w.factory.name}</Link> : '—' },
                                { label: 'Type', value: w.type?.name ?? '—' },
                                { label: 'Responsible department', value: w.department ? <Link className="text-brand-700 hover:underline" to={`/factory-setup/departments/${w.department.id}`}>{w.department.name}</Link> : '—' },
                                { label: 'Manager', value: w.manager?.full_name ?? '—' },
                                { label: 'Locations', value: w.locations_count ?? 0 },
                                { label: 'Description', value: w.description || '—' },
                                { label: 'Created by', value: w.created_by?.full_name ?? '—' },
                                { label: 'Created', value: formatDateTime(w.created_at) },
                                { label: 'Last updated', value: formatDateTime(w.updated_at) },
                            ]}
                        />
                    </CardBody>
                </Card>

                {can('locations.view') && (
                    <Card className="lg:col-span-3">
                        <CardHeader
                            title="Storage locations"
                            description={tree.data ? `${tree.data.data.total} location(s) · up to ${tree.data.data.max_depth} levels` : undefined}
                            actions={
                                <>
                                    <select aria-label="Location status" value={treeStatus} onChange={(e) => setTreeStatus(e.target.value)} className={`${filterInputClass} h-8 w-32`}>
                                        <option value="">All</option>
                                        <option value="active">Active</option>
                                        <option value="inactive">Inactive</option>
                                    </select>
                                    {canAdd && <ButtonLink size="sm" to={`/factory-setup/locations/new?warehouse_id=${w.id}`} icon={<Plus className="h-4 w-4" />}>Add location</ButtonLink>}
                                </>
                            }
                        />
                        <CardBody>
                            {tree.loading && !tree.data ? (
                                <LoadingBlock />
                            ) : !tree.data?.data.tree.length ? (
                                <EmptyState icon={<MapPin className="h-6 w-6" />} title="No locations" description="Add zones, racks, shelves or bins to organise this warehouse." />
                            ) : (
                                <LocationTree
                                    nodes={tree.data.data.tree}
                                    canAddChild={canAdd}
                                    addChildHref={(n) => `/factory-setup/locations/new?warehouse_id=${w.id}&parent_id=${n.id}`}
                                />
                            )}
                        </CardBody>
                    </Card>
                )}
            </div>
            {status.dialog}
        </>
    );
}
