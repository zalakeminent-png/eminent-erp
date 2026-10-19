import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { Pencil, Plus, Power, Trash2 } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useApi } from '@/hooks/useApi';
import { Card, CardBody, CardHeader, DescriptionList } from '@/components/ui/Card';
import { Alert, Badge, PageHeader, StatusBadge } from '@/components/ui/Feedback';
import { Button, ButtonLink } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/Modal';
import { LoadingBlock } from '@/components/ui/Spinner';
import { useToast } from '@/components/ui/Toast';
import { api, errorMessage } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import type { Location } from '../types';
import { useStatusAction } from '../components/useStatusAction';

type PathItem = { id: number; code: string; name: string };

export default function LocationDetailsPage() {
    const { id } = useParams();
    const navigate = useNavigate();
    const toast = useToast();
    const { can } = useAuth();
    const { data, loading, error, reload } = useApi<{ data: Location; path: PathItem[] }>(`/locations/${id}`);
    const status = useStatusAction<Location>('locations', 'location', () => reload());
    const [deleting, setDeleting] = useState(false);
    const [busy, setBusy] = useState(false);

    if (loading) return <LoadingBlock />;
    if (error || !data) return <Alert tone="danger">{error ?? 'Location not found.'}</Alert>;
    const l = data.data;
    const yes = (v: boolean) => (v ? 'Yes' : 'No');

    const onDelete = async () => {
        setBusy(true);
        try {
            await api.delete(`/locations/${l.id}`);
            toast.success('Location deleted.');
            navigate(`/factory-setup/warehouses/${l.warehouse_id}`);
        } catch (err) {
            toast.error(errorMessage(err));
            setDeleting(false);
        } finally {
            setBusy(false);
        }
    };

    return (
        <>
            <PageHeader
                title={`${l.code} — ${l.name}`}
                breadcrumb={
                    <span className="flex flex-wrap items-center gap-1">
                        <Link to={`/factory-setup/warehouses/${l.warehouse_id}`} className="hover:text-slate-700">{l.warehouse?.name}</Link>
                        {data.path.map((p) => (
                            <span key={p.id}> / <Link to={`/factory-setup/locations/${p.id}`} className="font-mono hover:text-slate-700">{p.code}</Link></span>
                        ))}
                    </span>
                }
                actions={
                    <>
                        {can('locations.create') && l.is_active && <ButtonLink variant="secondary" to={`/factory-setup/locations/new?warehouse_id=${l.warehouse_id}&parent_id=${l.id}`} icon={<Plus className="h-4 w-4" />}>Add child</ButtonLink>}
                        {can('locations.delete') && (l.children_count ?? 0) === 0 && (
                            <Button variant="ghost" icon={<Trash2 className="h-4 w-4 text-red-500" />} onClick={() => setDeleting(true)}>Delete</Button>
                        )}
                        {can(l.is_active ? 'locations.deactivate' : 'locations.update') && (
                            <Button variant={l.is_active ? 'danger' : 'secondary'} icon={<Power className="h-4 w-4" />} onClick={() => status.request(l)}>{l.is_active ? 'Deactivate' : 'Activate'}</Button>
                        )}
                        {can('locations.update') && <ButtonLink to={`/factory-setup/locations/${l.id}/edit`} icon={<Pencil className="h-4 w-4" />}>Edit</ButtonLink>}
                    </>
                }
            />
            {!l.is_active && <Alert tone="warning" className="mb-6">This location is inactive and is excluded from new operational selections.</Alert>}
            <Card>
                <CardHeader title="Details" />
                <CardBody>
                    <DescriptionList
                        items={[
                            { label: 'Location ID', value: <span className="font-mono">#{l.id}</span> },
                            { label: 'Status', value: <StatusBadge active={l.is_active} /> },
                            { label: 'Warehouse', value: l.warehouse?.name },
                            { label: 'Parent', value: l.parent ? <Link className="text-brand-700 hover:underline" to={`/factory-setup/locations/${l.parent.id}`}>{l.parent.code} — {l.parent.name}</Link> : 'Top level' },
                            { label: 'Type', value: <Badge>{l.type_label}</Badge> },
                            { label: 'Child locations', value: l.children_count ?? 0 },
                            { label: 'Virtual', value: yes(l.is_virtual) },
                            { label: 'Pickable', value: yes(l.is_pickable) },
                            { label: 'Receivable', value: yes(l.is_receivable) },
                            { label: 'Description', value: l.description || '—' },
                            { label: 'Created by', value: l.created_by?.full_name ?? '—' },
                            { label: 'Created', value: formatDateTime(l.created_at) },
                            { label: 'Last updated', value: formatDateTime(l.updated_at) },
                        ]}
                    />
                </CardBody>
            </Card>
            {status.dialog}
            <ConfirmDialog
                open={deleting}
                tone="danger"
                title="Delete location"
                confirmLabel="Delete permanently"
                loading={busy}
                onCancel={() => setDeleting(false)}
                onConfirm={onDelete}
                message={<>Permanently delete <strong>{l.code}</strong>? Only unused locations can be deleted; otherwise deactivate it.</>}
            />
        </>
    );
}
