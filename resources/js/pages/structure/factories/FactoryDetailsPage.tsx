import { Link, useParams } from 'react-router-dom';
import { Network, Pencil, Plus, Power } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useApi } from '@/hooks/useApi';
import { Card, CardBody, CardHeader, DescriptionList } from '@/components/ui/Card';
import { Alert, Badge, EmptyState, PageHeader, StatusBadge } from '@/components/ui/Feedback';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Table, Td, Th } from '@/components/ui/Table';
import { LoadingBlock } from '@/components/ui/Spinner';
import { formatDateTime } from '@/lib/format';
import type { Department, Factory, Location } from '../types';
import { useStatusAction } from '../components/useStatusAction';

export default function FactoryDetailsPage() {
    const { id } = useParams();
    const { can } = useAuth();
    const { data, loading, error, setData } = useApi<{ data: Factory }>(`/factories/${id}`);
    const departments = useApi<{ data: Department[] }>(can('departments.view') ? '/departments' : null, { factory_id: id, all: 1 });
    const locations = useApi<{ data: Location[]; meta: { total: number } }>(can('locations.view') ? '/locations' : null, { factory_id: id, per_page: 200 });
    const status = useStatusAction<Factory>('factories', 'factory', (f) => setData({ data: f }), 'Departments and storage locations are not changed.');

    if (loading) return <LoadingBlock />;
    if (error || !data) return <Alert tone="danger">{error ?? 'Factory not found.'}</Alert>;
    const f = data.data;
    const address = [f.address_line_1, f.address_line_2, f.city, f.state, f.postal_code, f.country].filter(Boolean).join(', ');

    return (
        <>
            <PageHeader
                title={f.name}
                breadcrumb={<Link to="/factory-setup/factories" className="hover:text-slate-700">Factories</Link>}
                description={<span className="font-mono">{f.code}</span>}
                actions={
                    <>
                        {can('factory-structure.view') && <ButtonLink to={`/factory-setup/structure?factory=${f.id}`} variant="secondary" icon={<Network className="h-4 w-4" />}>Structure</ButtonLink>}
                        {can(f.is_active ? 'factories.deactivate' : 'factories.update') && (
                            <Button variant={f.is_active ? 'danger' : 'secondary'} icon={<Power className="h-4 w-4" />} onClick={() => status.request(f)}>{f.is_active ? 'Deactivate' : 'Activate'}</Button>
                        )}
                        {can('factories.update') && <ButtonLink to={`/factory-setup/factories/${f.id}/edit`} icon={<Pencil className="h-4 w-4" />}>Edit</ButtonLink>}
                    </>
                }
            />
            {!f.is_active && <Alert tone="warning" className="mb-6">This factory is inactive and cannot receive new departments, storage locations or user assignments.</Alert>}

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader title="Details" />
                    <CardBody>
                        <DescriptionList
                            items={[
                                { label: 'Factory ID', value: <span className="font-mono">#{f.id}</span> },
                                { label: 'Company', value: f.company?.name },
                                { label: 'Type', value: <Badge>{f.type_label}</Badge> },
                                { label: 'Status', value: <StatusBadge active={f.is_active} /> },
                                { label: 'Address', value: address || '—' },
                                { label: 'Contact person', value: f.contact_person ?? '—' },
                                { label: 'Contact number', value: f.contact_number ?? '—' },
                                { label: 'Email', value: f.email ?? '—' },
                                { label: 'Created by', value: f.created_by?.full_name ?? '—' },
                                { label: 'Created', value: formatDateTime(f.created_at) },
                                { label: 'Last updated', value: formatDateTime(f.updated_at) },
                            ]}
                        />
                    </CardBody>
                </Card>
                <Card>
                    <CardHeader title="At a glance" />
                    <CardBody className="grid grid-cols-3 gap-3 text-center">
                        {[
                            ['Departments', f.departments_count],
                            ['Storage locations', locations.data?.meta.total],
                            ['Assigned users', f.users_count],
                        ].map(([label, value]) => (
                            <div key={label as string} className="rounded-md bg-slate-50 p-3">
                                <p className="text-2xl font-semibold tabular-nums text-slate-900">{value ?? 0}</p>
                                <p className="text-xs text-slate-500">{label}</p>
                            </div>
                        ))}
                    </CardBody>
                </Card>
            </div>

            {can('departments.view') && (
                <Card className="mt-6">
                    <CardHeader
                        title="Departments"
                        actions={can('departments.create') && f.is_active && <ButtonLink size="sm" to={`/factory-setup/departments/new?factory_id=${f.id}`} icon={<Plus className="h-4 w-4" />}>Add department</ButtonLink>}
                    />
                    {departments.loading ? <LoadingBlock /> : !departments.data?.data.length ? (
                        <EmptyState title="No departments" description="Departments are optional and created as needed." />
                    ) : (
                        <Table>
                            <thead><tr><Th>Code</Th><Th>Department</Th><Th className="hidden md:table-cell">Manager</Th><Th>Status</Th></tr></thead>
                            <tbody className="divide-y divide-slate-100">
                                {departments.data.data.map((d) => (
                                    <tr key={d.id}>
                                        <Td className="font-mono text-xs">{d.code}</Td>
                                        <Td><Link className="font-medium text-brand-700 hover:underline" to={`/factory-setup/departments/${d.id}`}>{d.name}</Link></Td>
                                        <Td className="hidden md:table-cell">{d.manager?.full_name ?? '—'}</Td>
                                        <Td><StatusBadge active={d.is_active} /></Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    )}
                </Card>
            )}

            {can('locations.view') && (
                <Card className="mt-6">
                    <CardHeader
                        title="Storage locations"
                        actions={can('locations.create') && f.is_active && <ButtonLink size="sm" to={`/factory-setup/locations/new?factory_id=${f.id}`} icon={<Plus className="h-4 w-4" />}>Add location</ButtonLink>}
                    />
                    {locations.loading ? <LoadingBlock /> : !locations.data?.data.length ? (
                        <EmptyState title="No storage locations" description="Add racks, bins and areas where material is kept." />
                    ) : (
                        <Table>
                            <thead><tr><Th>Code</Th><Th>Location</Th><Th className="hidden md:table-cell">Type</Th><Th className="hidden md:table-cell">Parent</Th><Th>Status</Th></tr></thead>
                            <tbody className="divide-y divide-slate-100">
                                {locations.data.data.map((l) => (
                                    <tr key={l.id}>
                                        <Td className="font-mono text-xs">{l.code}</Td>
                                        <Td><Link className="font-medium text-brand-700 hover:underline" to={`/factory-setup/locations/${l.id}`}>{l.name}</Link></Td>
                                        <Td className="hidden md:table-cell">{l.type_label}</Td>
                                        <Td className="hidden md:table-cell">{l.parent?.code ?? '—'}</Td>
                                        <Td><StatusBadge active={l.is_active} /></Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    )}
                </Card>
            )}
            {status.dialog}
        </>
    );
}
