import { useRef, useState, type FormEvent, type ReactNode } from 'react';
import { Link, useParams } from 'react-router-dom';
import { Download, FileUp, Pencil, Plus, Power, Trash2 } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useApi } from '@/hooks/useApi';
import { Card, CardBody, CardHeader, DescriptionList } from '@/components/ui/Card';
import { Alert, Badge, EmptyState, PageHeader, StatusBadge } from '@/components/ui/Feedback';
import { SelectInput, TextInput } from '@/components/ui/Form';
import { Button, ButtonLink } from '@/components/ui/Button';
import { ConfirmDialog, Modal } from '@/components/ui/Modal';
import { Table, Td, Th } from '@/components/ui/Table';
import { LoadingBlock } from '@/components/ui/Spinner';
import { useToast } from '@/components/ui/Toast';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { cx, formatDate, formatDateTime } from '@/lib/format';
import type { Paginated } from '@/types';
import { useStatusAction } from '@/pages/structure/components/useStatusAction';
import { DocStatusBadge, downloadFile, formatQty } from '../components/shared';
import type { Document, Item, Uom, VersionedDoc } from '../types';

type Tab = 'general' | 'specifications' | 'stock' | 'units' | 'documents' | 'bom' | 'routing' | 'qc' | 'revisions';

const yes = (v: boolean) => (v ? 'Yes' : 'No');
const dim = (v: string | null, uom?: { code: string } | null) => (v ? `${formatQty(v)} ${uom?.code ?? ''}` : '—');

function LinkedDocuments({ itemId, endpoint, base, permission, label, canCreate }: { itemId: number; endpoint: string; base: string; permission: string; label: string; canCreate: boolean }) {
    const { can } = useAuth();
    const { data, loading } = useApi<Paginated<VersionedDoc & { lines_count?: number; operations_count?: number }>>(can(permission) ? endpoint : null, { item_id: itemId, per_page: 50, sort: 'created_at', direction: 'desc' });
    if (!can(permission)) return <Alert tone="info">You do not have permission to view {label}.</Alert>;
    return (
        <Card>
            <CardHeader title={label} actions={canCreate && <ButtonLink size="sm" to={`${base}/new?item_id=${itemId}`} icon={<Plus className="h-4 w-4" />}>New</ButtonLink>} />
            {loading ? <LoadingBlock /> : !data?.data.length ? <EmptyState title={`No ${label.toLowerCase()} yet`} /> : (
                <Table>
                    <thead><tr><Th>Number</Th><Th>Version</Th><Th>Status</Th><Th className="hidden sm:table-cell">Effective</Th><Th className="hidden md:table-cell">Approved</Th></tr></thead>
                    <tbody className="divide-y divide-slate-100">
                        {data.data.map((d) => (
                            <tr key={d.id}>
                                <Td><Link to={`${base}/${d.id}`} className="font-mono text-xs font-semibold text-brand-700 hover:underline">{d.number}</Link></Td>
                                <Td className="tabular-nums">v{d.version}</Td>
                                <Td><DocStatusBadge status={d.status} /></Td>
                                <Td className="hidden whitespace-nowrap text-xs sm:table-cell">{formatDate(d.effective_from)} – {d.effective_to ? formatDate(d.effective_to) : 'open'}</Td>
                                <Td className="hidden text-xs md:table-cell">{d.approved_by?.full_name ?? '—'}</Td>
                            </tr>
                        ))}
                    </tbody>
                </Table>
            )}
        </Card>
    );
}

export default function ItemDetailsPage() {
    const { id } = useParams();
    const { can } = useAuth();
    const toast = useToast();
    const [tab, setTab] = useState<Tab>('general');
    const { data, loading, error, reload } = useApi<{ data: Item; meta: { usage: Record<string, number>; lock_reasons: string[] } }>(`/items/${id}`);
    const documents = useApi<{ data: Document[] }>(tab === 'documents' ? `/items/${id}/documents` : null);
    const revisions = useApi<{ data: { revision: number; changes: Record<string, unknown>; reason: string | null; changed_by: string | null; created_at: string }[] }>(tab === 'revisions' ? `/items/${id}/revisions` : null);
    const uoms = useApi<{ data: Uom[] }>(tab === 'units' ? '/uoms' : null, { all: 1, status: 'active' });
    const status = useStatusAction<Item & { name: string }>('items', 'item', () => reload());

    const [conversion, setConversion] = useState<{ uom_id: string; factor: string; remarks: string } | null>(null);
    const [convErrors, setConvErrors] = useState<Record<string, string>>({});
    const [removingConversion, setRemovingConversion] = useState<number | null>(null);
    const [upload, setUpload] = useState<{ category: string; title: string; revision: string; file: File | null } | null>(null);
    const [uploading, setUploading] = useState(false);
    const [uploadError, setUploadError] = useState<string | null>(null);
    const [removingDoc, setRemovingDoc] = useState<Document | null>(null);
    const fileInput = useRef<HTMLInputElement>(null);

    if (loading) return <LoadingBlock />;
    if (error || !data) return <Alert tone="danger">{error ?? 'Item not found.'}</Alert>;
    const item = data.data;
    const usage = data.meta.usage;

    const saveConversion = async (event: FormEvent) => {
        event.preventDefault();
        if (!conversion) return;
        try {
            await api.post(`/items/${item.id}/uom-conversions`, { uom_id: Number(conversion.uom_id), factor: conversion.factor.trim(), remarks: conversion.remarks || null });
            toast.success('Conversion added.');
            setConversion(null);
            reload();
        } catch (err) {
            setConvErrors(fieldErrors(err));
            if (!Object.keys(fieldErrors(err)).length) toast.error(errorMessage(err));
        }
    };

    const removeConversion = async () => {
        try {
            await api.delete(`/items/${item.id}/uom-conversions/${removingConversion}`);
            toast.success('Conversion removed.');
            reload();
        } catch (err) {
            toast.error(errorMessage(err));
        } finally {
            setRemovingConversion(null);
        }
    };

    const submitUpload = async (event: FormEvent) => {
        event.preventDefault();
        if (!upload?.file || uploading) return;
        const body = new FormData();
        body.append('category', upload.category);
        if (upload.title) body.append('title', upload.title);
        if (upload.revision) body.append('revision', upload.revision);
        body.append('file', upload.file);
        setUploading(true);
        setUploadError(null);
        try {
            await api.post(`/items/${item.id}/documents`, body);
            toast.success('Document uploaded.');
            setUpload(null);
            documents.reload();
            reload();
        } catch (err) {
            setUploadError(Object.values(fieldErrors(err))[0] ?? errorMessage(err));
        } finally {
            setUploading(false);
        }
    };

    const removeDocument = async () => {
        if (!removingDoc) return;
        try {
            await api.delete(`/documents/${removingDoc.id}`);
            toast.success('Document deleted.');
            documents.reload();
            reload();
        } catch (err) {
            toast.error(errorMessage(err));
        } finally {
            setRemovingDoc(null);
        }
    };

    const tabs: { key: Tab; label: string; show: boolean; count?: number }[] = [
        { key: 'general', label: 'General', show: true },
        { key: 'specifications', label: 'Specifications', show: true },
        { key: 'stock', label: 'Stock Configuration', show: true },
        { key: 'units', label: 'Units', show: true, count: item.uom_conversions?.length },
        { key: 'documents', label: 'Documents', show: true },
        { key: 'bom', label: 'BOM', show: item.can_have_bom, count: usage.boms },
        { key: 'routing', label: 'Routing', show: item.can_have_bom, count: usage.routings },
        { key: 'qc', label: 'QC Parameters', show: true, count: usage.qc_specifications },
        { key: 'revisions', label: 'Revisions', show: true },
    ];

    const panel: Record<Tab, ReactNode> = {
        general: (
            <Card><CardBody>
                <div className="flex flex-col gap-6 md:flex-row">
                    {item.image_url && <img src={item.image_url} alt={item.name} className="h-40 w-40 shrink-0 rounded-lg border border-slate-200 object-contain" />}
                    <div className="flex-1">
                        <DescriptionList items={[
                            { label: 'Item ID', value: <span className="font-mono">#{item.id}</span> },
                            { label: 'Item code / SKU', value: <span className="font-mono">{item.code}</span> },
                            { label: 'Category', value: item.category?.name },
                            { label: 'Item type', value: <Badge>{item.item_type_label}</Badge> },
                            { label: 'Material grade', value: item.material_grade ? `${item.material_grade.code} — ${item.material_grade.name}` : '—' },
                            { label: 'Base UOM', value: item.base_uom?.code },
                            { label: 'Purchase UOM', value: item.purchase_uom?.code ?? '—' },
                            { label: 'Production UOM', value: item.production_uom?.code ?? '—' },
                            { label: 'Description', value: item.description || '—' },
                            { label: 'Technical specification', value: item.technical_specification || '—' },
                            { label: 'Created by', value: item.created_by?.full_name ?? '—' },
                            { label: 'Last updated', value: formatDateTime(item.updated_at) },
                        ]} />
                    </div>
                </div>
            </CardBody></Card>
        ),
        specifications: (
            <Card><CardBody>
                <DescriptionList items={[
                    { label: 'Material', value: item.material || '—' },
                    { label: 'Shape', value: item.shape || '—' },
                    { label: 'Size', value: item.size || '—' },
                    { label: 'Length', value: dim(item.length, item.dimension_uom) },
                    { label: 'Width', value: dim(item.width, item.dimension_uom) },
                    { label: 'Height', value: dim(item.height, item.dimension_uom) },
                    { label: 'Diameter', value: dim(item.diameter, item.dimension_uom) },
                    { label: 'Thickness', value: dim(item.thickness, item.dimension_uom) },
                    { label: 'Surface finish', value: item.surface_finish || '—' },
                    { label: 'Color', value: item.color || '—' },
                    { label: 'Weight per piece', value: dim(item.weight_per_piece, item.weight_uom) },
                    { label: 'Internal part no.', value: item.internal_part_number || '—' },
                    { label: 'Customer part no.', value: item.customer_part_number || '—' },
                    { label: 'Drawing', value: item.drawing_number ? `${item.drawing_number}${item.drawing_revision ? ` rev ${item.drawing_revision}` : ''}` : '—' },
                    ...(item.attribute_values ?? []).map((a) => ({ label: a.name, value: a.data_type === 'boolean' ? (a.value === '1' ? 'Yes' : 'No') : a.value })),
                    { label: 'Additional specifications', value: item.additional_specifications || '—' },
                ]} />
            </CardBody></Card>
        ),
        stock: (
            <Card><CardBody>
                <Alert tone="info" className="mb-4">Stock balances are not stored on the item; they are calculated by the Inventory module.</Alert>
                <DescriptionList items={[
                    { label: 'Stock item', value: yes(item.is_stock_item) },
                    { label: 'Track inventory', value: yes(item.track_inventory) },
                    { label: 'Minimum stock', value: dim(item.min_stock_level, item.base_uom) },
                    { label: 'Maximum stock', value: dim(item.max_stock_level, item.base_uom) },
                    { label: 'Reorder level', value: dim(item.reorder_level, item.base_uom) },
                    { label: 'Default location', value: item.default_location ? `${item.default_location.code} — ${item.default_location.name}` : '—' },
                    { label: 'Allow negative stock', value: yes(item.allow_negative_stock) },
                    { label: 'Requires QC', value: yes(item.requires_qc) },
                    { label: 'Purchased / Manufactured / Sold', value: `${yes(item.is_purchased)} / ${yes(item.is_manufactured)} / ${yes(item.is_sold)}` },
                ]} />
            </CardBody></Card>
        ),
        units: (
            <Card>
                <CardHeader title="Item-specific unit conversions" description={`Relative to the base unit (${item.base_uom?.code}). E.g. 1 BOX = 100 PCS, or a documented weight per piece.`}
                    actions={can('items.update') && <Button size="sm" icon={<Plus className="h-4 w-4" />} onClick={() => { setConversion({ uom_id: '', factor: '', remarks: '' }); setConvErrors({}); }}>Add conversion</Button>} />
                {!item.uom_conversions?.length ? <EmptyState title="No item-specific conversions" description="Universal conversions (e.g. KG ↔ GM) apply automatically." /> : (
                    <Table>
                        <thead><tr><Th>Conversion</Th><Th className="hidden sm:table-cell">Documentation</Th><Th><span className="sr-only">Actions</span></Th></tr></thead>
                        <tbody className="divide-y divide-slate-100">
                            {item.uom_conversions.map((c) => (
                                <tr key={c.id}>
                                    <Td className="font-mono text-sm">1 {c.uom.code} = {formatQty(c.factor)} {item.base_uom?.code}</Td>
                                    <Td className="hidden text-xs sm:table-cell">{c.remarks ?? '—'}</Td>
                                    <Td className="text-right">{can('items.update') && <Button variant="ghost" size="sm" aria-label={`Remove ${c.uom.code} conversion`} icon={<Trash2 className="h-4 w-4 text-red-500" />} onClick={() => setRemovingConversion(c.id)} />}</Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </Card>
        ),
        documents: (
            <Card>
                <CardHeader title="Images, drawings & attachments" actions={can('items.update') && <Button size="sm" icon={<FileUp className="h-4 w-4" />} onClick={() => setUpload({ category: 'drawing', title: '', revision: item.drawing_revision ?? '', file: null })}>Upload</Button>} />
                {documents.loading ? <LoadingBlock /> : !documents.data?.data.length ? <EmptyState title="No documents" description="Upload the item image, drawings (PDF/DWG/DXF/STEP) or specifications." /> : (
                    <Table>
                        <thead><tr><Th>File</Th><Th>Type</Th><Th className="hidden sm:table-cell">Revision</Th><Th className="hidden md:table-cell">Uploaded</Th><Th><span className="sr-only">Actions</span></Th></tr></thead>
                        <tbody className="divide-y divide-slate-100">
                            {documents.data.data.map((d) => (
                                <tr key={d.id}>
                                    <Td><span className="font-medium">{d.title || d.original_name}</span>{d.title && <span className="block text-xs text-slate-500">{d.original_name}</span>}</Td>
                                    <Td><Badge>{d.category}</Badge></Td>
                                    <Td className="hidden sm:table-cell">{d.revision ?? '—'}</Td>
                                    <Td className="hidden text-xs md:table-cell">{formatDateTime(d.created_at)}{d.uploaded_by && ` · ${d.uploaded_by.full_name}`}</Td>
                                    <Td className="whitespace-nowrap text-right">
                                        <Button variant="ghost" size="sm" aria-label={`Download ${d.original_name}`} icon={<Download className="h-4 w-4" />} onClick={() => downloadFile(`/documents/${d.id}/download`, undefined, d.original_name).catch((e) => toast.error(errorMessage(e)))} />
                                        {can('items.update') && <Button variant="ghost" size="sm" aria-label={`Delete ${d.original_name}`} icon={<Trash2 className="h-4 w-4 text-red-500" />} onClick={() => setRemovingDoc(d)} />}
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </Card>
        ),
        bom: <LinkedDocuments itemId={item.id} endpoint="/boms" base="/master-data/boms" permission="boms.view" label="Bills of materials" canCreate={can('boms.create') && item.is_active} />,
        routing: <LinkedDocuments itemId={item.id} endpoint="/product-routings" base="/master-data/routings" permission="routings.view" label="Routings" canCreate={can('routings.create') && item.is_active} />,
        qc: <LinkedDocuments itemId={item.id} endpoint="/qc-specifications" base="/master-data/qc-specifications" permission="qc-specifications.view" label="QC specifications" canCreate={can('qc-specifications.create') && item.is_active} />,
        revisions: (
            <Card>
                <CardHeader title="Specification revision history" />
                {revisions.loading ? <LoadingBlock /> : (
                    <ul className="divide-y divide-slate-100">
                        {revisions.data?.data.map((r) => (
                            <li key={r.revision} className="px-5 py-3 text-sm">
                                <p className="font-medium text-slate-900">Revision {r.revision} <span className="ml-2 text-xs font-normal text-slate-500">{formatDateTime(r.created_at)}{r.changed_by && ` · ${r.changed_by}`}</span></p>
                                {r.reason && <p className="text-slate-600">{r.reason}</p>}
                                {!('initial' in r.changes) && (
                                    <ul className="mt-1 space-y-0.5 text-xs text-slate-600">
                                        {Object.entries(r.changes).map(([field, change]) => (
                                            <li key={field}><span className="font-mono">{field}</span>: {JSON.stringify((change as { from: unknown }).from)} → {JSON.stringify((change as { to: unknown }).to)}</li>
                                        ))}
                                    </ul>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Card>
        ),
    };

    return (
        <>
            <PageHeader
                title={item.name}
                breadcrumb={<Link to="/master-data/items" className="hover:text-slate-700">Items / Products</Link>}
                description={<span className="font-mono">{item.code} · Rev {item.revision}</span>}
                actions={
                    <>
                        {can(item.is_active ? 'items.deactivate' : 'items.update') && (
                            <Button variant={item.is_active ? 'danger' : 'secondary'} icon={<Power className="h-4 w-4" />} onClick={() => status.request(item)}>{item.is_active ? 'Deactivate' : 'Activate'}</Button>
                        )}
                        {can('items.update') && <ButtonLink to={`/master-data/items/${item.id}/edit`} icon={<Pencil className="h-4 w-4" />}>Edit</ButtonLink>}
                    </>
                }
            />
            <div className="mb-4 flex items-center gap-2"><StatusBadge active={item.is_active} />{data.meta.lock_reasons.length > 0 && <Badge tone="info">Referenced — code, base UOM and type locked</Badge>}</div>

            <div role="tablist" className="mb-4 flex gap-1 overflow-x-auto border-b border-slate-200">
                {tabs.filter((t) => t.show).map((t) => (
                    <button key={t.key} type="button" role="tab" aria-selected={tab === t.key} onClick={() => setTab(t.key)}
                        className={cx('-mb-px whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium', tab === t.key ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700')}>
                        {t.label}{t.count ? <span className="ml-1.5 rounded-full bg-slate-100 px-1.5 text-xs text-slate-600">{t.count}</span> : null}
                    </button>
                ))}
            </div>
            {panel[tab]}

            {status.dialog}
            <ConfirmDialog open={removingConversion !== null} tone="danger" title="Remove conversion" confirmLabel="Remove" onCancel={() => setRemovingConversion(null)} onConfirm={removeConversion}
                message="Remove this item-specific conversion? Conversions used by approved BOMs or the purchase/production unit cannot be removed." />
            <ConfirmDialog open={removingDoc !== null} tone="danger" title="Delete document" confirmLabel="Delete" onCancel={() => setRemovingDoc(null)} onConfirm={removeDocument}
                message={<>Delete <strong>{removingDoc?.original_name}</strong>?</>} />

            <Modal open={conversion !== null} onClose={() => setConversion(null)} title="Add item-specific conversion">
                {conversion && (
                    <form onSubmit={saveConversion} className="space-y-4">
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <SelectInput label="Alternate unit (1 ×)" value={conversion.uom_id} onChange={(e) => setConversion({ ...conversion, uom_id: e.target.value })} error={convErrors.uom_id} required>
                                <option value="">Select unit</option>
                                {uoms.data?.data.filter((u) => u.id !== item.base_uom_id).map((u) => <option key={u.id} value={u.id}>{u.code} — {u.name}</option>)}
                            </SelectInput>
                            <TextInput label={`= how many ${item.base_uom?.code}`} value={conversion.factor} onChange={(e) => setConversion({ ...conversion, factor: e.target.value })} error={convErrors.factor} inputMode="decimal" required />
                            <TextInput label="Documentation / source" value={conversion.remarks} onChange={(e) => setConversion({ ...conversion, remarks: e.target.value })} wrapperClassName="sm:col-span-2" placeholder="e.g. Standard carton, weighed sample of 100 pcs" />
                        </div>
                        <div className="flex justify-end gap-2"><Button variant="secondary" onClick={() => setConversion(null)}>Cancel</Button><Button type="submit" disabled={!conversion.uom_id || !conversion.factor}>Save</Button></div>
                    </form>
                )}
            </Modal>

            <Modal open={upload !== null} onClose={() => setUpload(null)} title="Upload document">
                {upload && (
                    <form onSubmit={submitUpload} className="space-y-4">
                        {uploadError && <Alert tone="danger">{uploadError}</Alert>}
                        <SelectInput label="Type" value={upload.category} onChange={(e) => setUpload({ ...upload, category: e.target.value })}>
                            <option value="image">Item image (PNG/JPG/WebP)</option>
                            <option value="drawing">Product drawing</option>
                            <option value="specification">Specification</option>
                            <option value="other">Other attachment</option>
                        </SelectInput>
                        <TextInput label="Title" value={upload.title} onChange={(e) => setUpload({ ...upload, title: e.target.value })} maxLength={150} />
                        <TextInput label="Revision" value={upload.revision} onChange={(e) => setUpload({ ...upload, revision: e.target.value })} maxLength={20} />
                        <div>
                            <input ref={fileInput} type="file" className="hidden" onChange={(e) => setUpload({ ...upload, file: e.target.files?.[0] ?? null })}
                                accept={upload.category === 'image' ? 'image/png,image/jpeg,image/webp' : '.pdf,.png,.jpg,.jpeg,.webp,.dwg,.dxf,.step,.stp,.igs,.iges,.xlsx,.xls,.docx,.csv,.txt'} />
                            <Button variant="secondary" icon={<FileUp className="h-4 w-4" />} onClick={() => fileInput.current?.click()}>{upload.file ? upload.file.name : 'Choose file'}</Button>
                            <p className="mt-1 text-xs text-slate-500">Max 20 MB. Stored privately; downloads require permission.</p>
                        </div>
                        <div className="flex justify-end gap-2"><Button variant="secondary" onClick={() => setUpload(null)}>Cancel</Button><Button type="submit" loading={uploading} disabled={!upload.file}>Upload</Button></div>
                    </form>
                )}
            </Modal>
        </>
    );
}
