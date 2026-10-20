import { useEffect, useState, type FormEvent } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react';
import { useApi } from '@/hooks/useApi';
import { Card, CardBody, CardHeader, DescriptionList } from '@/components/ui/Card';
import { Alert, Badge, EmptyState, PageHeader } from '@/components/ui/Feedback';
import { Checkbox, TextareaInput, TextInput } from '@/components/ui/Form';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Table, Td, Th } from '@/components/ui/Table';
import { LoadingBlock } from '@/components/ui/Spinner';
import { useToast } from '@/components/ui/Toast';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { formatDate, formatDateTime } from '@/lib/format';
import { DocStatusBadge, ItemPicker, MiniInput, MiniSelect, formatQty } from '../components/shared';
import { TIME_UNITS, type Item, type Process, type Routing } from '../types';
import { DocumentActions, DocumentListPage, ROUTING_KIND } from './DocumentCommon';

type ItemOption = Pick<Item, 'id' | 'code' | 'name' | 'item_type' | 'is_active'> & { base_uom?: Item['base_uom'] };
interface MachineOption { id: number; code: string; name: string; department_id: number; processes?: { id: number }[] }
interface OpState { key: number; process_id: string; department_id: string; machine_id: string; setup_time: string; cycle_time: string; time_unit: string; process_loss_percent: string; requires_qc: boolean; remarks: string }

let opKey = 0;
const newOp = (): OpState => ({ key: ++opKey, process_id: '', department_id: '', machine_id: '', setup_time: '0', cycle_time: '0', time_unit: 'minute', process_loss_percent: '0', requires_qc: false, remarks: '' });

export function RoutingListPage() {
    return <DocumentListPage kind={ROUTING_KIND} extraColumns={[{ label: 'Operations', render: (d) => String(d.operations_count ?? '') }]} />;
}

export function RoutingEditorPage() {
    const { id } = useParams();
    const [query] = useSearchParams();
    const isEdit = Boolean(id);
    const navigate = useNavigate();
    const toast = useToast();
    const existing = useApi<{ data: Routing }>(isEdit ? `/product-routings/${id}` : null);
    const prefillItem = useApi<{ data: Item }>(!isEdit && query.get('item_id') ? `/items/${query.get('item_id')}` : null);
    const processes = useApi<{ data: Process[] }>('/manufacturing-processes', { all: 1, status: 'active' });
    const machines = useApi<{ data: MachineOption[] }>('/machines', { all: 1, status: 'active' });
    const departments = useApi<{ data: { id: number; code: string; name: string }[] }>('/departments', { all: 1, status: 'active' });

    const [product, setProduct] = useState<ItemOption | null>(null);
    const [header, setHeader] = useState({ effective_from: new Date().toISOString().slice(0, 10), effective_to: '', description: '' });
    const [ops, setOps] = useState<OpState[]>([newOp()]);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [formError, setFormError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        const r = existing.data?.data;
        if (!r) return;
        setProduct(r.item ? { id: r.item.id, code: r.item.code, name: r.item.name, item_type: '', is_active: true } : null);
        setHeader({ effective_from: r.effective_from, effective_to: r.effective_to ?? '', description: r.description ?? '' });
        setOps((r.operations ?? []).map((o) => ({
            key: ++opKey, process_id: String(o.process_id), department_id: o.department_id ? String(o.department_id) : '', machine_id: o.machine_id ? String(o.machine_id) : '',
            setup_time: o.setup_time, cycle_time: o.cycle_time, time_unit: o.time_unit, process_loss_percent: o.process_loss_percent, requires_qc: o.requires_qc, remarks: o.remarks ?? '',
        })));
    }, [existing.data]);

    useEffect(() => {
        const i = prefillItem.data?.data;
        if (i && !product) setProduct({ id: i.id, code: i.code, name: i.name, item_type: i.item_type, is_active: i.is_active });
    }, [prefillItem.data, product]);

    if (existing.loading || processes.loading) return <LoadingBlock />;
    if (existing.error) return <Alert tone="danger">{existing.error}</Alert>;
    if (existing.data && !existing.data.data.is_editable) return <Alert tone="warning">This routing is {existing.data.data.status} and can no longer be edited. Create a revision instead.</Alert>;

    const allProcesses = processes.data?.data ?? [];
    const update = (key: number, patch: Partial<OpState>) => setOps((list) => list.map((o) => (o.key === key ? { ...o, ...patch } : o)));
    const move = (index: number, delta: number) => setOps((list) => {
        const next = [...list];
        const [op] = next.splice(index, 1);
        next.splice(index + delta, 0, op);
        return next;
    });

    const onSubmit = async (event: FormEvent) => {
        event.preventDefault();
        if (saving) return;
        if (!product) { setErrors({ item_id: 'Select the product.' }); return; }
        setSaving(true);
        setErrors({});
        setFormError(null);
        const operations = ops.filter((o) => o.process_id).map((o, index) => ({
            sequence: index + 1, process_id: Number(o.process_id), department_id: o.department_id ? Number(o.department_id) : null,
            machine_id: o.machine_id ? Number(o.machine_id) : null, setup_time: o.setup_time || '0', cycle_time: o.cycle_time || '0',
            time_unit: o.time_unit, process_loss_percent: o.process_loss_percent || '0', requires_qc: o.requires_qc, remarks: o.remarks || null,
        }));
        const payload = { ...(isEdit ? {} : { item_id: product.id }), effective_from: header.effective_from, effective_to: header.effective_to || null, description: header.description || null, operations };
        try {
            if (isEdit) {
                await api.put(`/product-routings/${id}`, payload);
                toast.success('Draft saved.');
                navigate(`/master-data/routings/${id}`);
            } else {
                const { data } = await api.post<{ data: Routing }>('/product-routings', payload);
                toast.success(`Draft ${data.data.number} created.`);
                navigate(`/master-data/routings/${data.data.id}`);
            }
        } catch (err) {
            setErrors(fieldErrors(err));
            if (!Object.keys(fieldErrors(err)).length) setFormError(errorMessage(err));
        } finally {
            setSaving(false);
        }
    };

    const submitted = ops.filter((o) => o.process_id);
    const opError = (op: OpState, field: string) => {
        const index = submitted.indexOf(op);
        return index >= 0 ? errors[`operations.${index}.${field}`] : undefined;
    };

    return (
        <form onSubmit={onSubmit} noValidate>
            <PageHeader title={isEdit ? `Edit routing ${existing.data?.data.number} (v${existing.data?.data.version})` : 'New product routing'}
                breadcrumb={<Link to="/master-data/routings" className="hover:text-slate-700">Product Routings</Link>}
                description="Operations run in the order shown; use the arrows to reorder. Machines must support the operation's process." />
            {formError && <Alert tone="danger" className="mb-4">{formError}</Alert>}
            <fieldset disabled={saving} className="space-y-6">
                <Card>
                    <CardHeader title="Header" />
                    <CardBody className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="sm:col-span-2"><ItemPicker label="Product" value={product} disabled={isEdit} error={errors.item_id} params={{ can_have_bom: 1 }} onChange={setProduct} /></div>
                        <TextInput label="Effective from" type="date" value={header.effective_from} onChange={(e) => setHeader({ ...header, effective_from: e.target.value })} error={errors.effective_from} required />
                        <TextInput label="Effective to (optional)" type="date" value={header.effective_to} onChange={(e) => setHeader({ ...header, effective_to: e.target.value })} error={errors.effective_to} />
                        <TextareaInput label="Description" value={header.description} onChange={(e) => setHeader({ ...header, description: e.target.value })} wrapperClassName="sm:col-span-2 lg:col-span-4" />
                    </CardBody>
                </Card>
                <Card>
                    <CardHeader title="Operations" description="Cycle time is per unit of the item's base UOM."
                        actions={<Button size="sm" variant="secondary" icon={<Plus className="h-4 w-4" />} onClick={() => setOps((list) => [...list, newOp()])}>Add operation</Button>} />
                    <div className="divide-y divide-slate-100">
                        {ops.length === 0 && <EmptyState title="No operations" />}
                        {ops.map((op, index) => {
                            const process = allProcesses.find((p) => String(p.id) === op.process_id);
                            const machineOptions = (machines.data?.data ?? []).filter((m) => (!op.process_id || m.processes?.some((p) => String(p.id) === op.process_id)) && (!op.department_id || String(m.department_id) === op.department_id));
                            return (
                                <div key={op.key} className="space-y-2 px-4 py-3">
                                    <div className="grid grid-cols-2 gap-2 md:grid-cols-[3rem_minmax(10rem,1.5fr)_minmax(9rem,1fr)_minmax(9rem,1fr)_auto] md:items-start">
                                        <span className="flex items-center gap-1 pt-2 text-sm font-semibold text-slate-500">{index + 1}</span>
                                        <MiniSelect label={`Process for operation ${index + 1}`} value={op.process_id} placeholder="Process" error={opError(op, 'process_id')}
                                            options={allProcesses.map((p) => ({ value: p.id, label: `${p.code} — ${p.name}` }))}
                                            onChange={(v) => { const p = allProcesses.find((x) => String(x.id) === v); update(op.key, { process_id: v, machine_id: '', requires_qc: p?.requires_qc ?? false, department_id: op.department_id || (p?.default_department_id ? String(p.default_department_id) : '') }); }} />
                                        <MiniSelect label={`Department for operation ${index + 1}`} value={op.department_id} placeholder={process?.default_department ? `Default: ${process.default_department.name}` : 'Department'} error={opError(op, 'department_id')}
                                            options={(departments.data?.data ?? []).map((d) => ({ value: d.id, label: d.name }))} onChange={(v) => update(op.key, { department_id: v, machine_id: '' })} />
                                        <MiniSelect label={`Machine for operation ${index + 1}`} value={op.machine_id} placeholder="Machine (optional)" error={opError(op, 'machine_id')}
                                            options={machineOptions.map((m) => ({ value: m.id, label: `${m.code} — ${m.name}` }))} onChange={(v) => update(op.key, { machine_id: v })} />
                                        <div className="col-span-2 flex justify-end gap-1 md:col-span-1">
                                            <Button variant="ghost" size="sm" disabled={index === 0} aria-label={`Move operation ${index + 1} up`} icon={<ArrowUp className="h-4 w-4" />} onClick={() => move(index, -1)} />
                                            <Button variant="ghost" size="sm" disabled={index === ops.length - 1} aria-label={`Move operation ${index + 1} down`} icon={<ArrowDown className="h-4 w-4" />} onClick={() => move(index, 1)} />
                                            <Button variant="ghost" size="sm" aria-label={`Remove operation ${index + 1}`} icon={<Trash2 className="h-4 w-4 text-red-500" />} onClick={() => setOps((list) => list.filter((o) => o.key !== op.key))} />
                                        </div>
                                    </div>
                                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 md:ml-12 md:grid-cols-[6rem_6rem_7rem_6rem_auto_1fr] md:items-center">
                                        <MiniInput label={`Setup time operation ${index + 1}`} placeholder="Setup" value={op.setup_time} onChange={(v) => update(op.key, { setup_time: v })} error={opError(op, 'setup_time')} />
                                        <MiniInput label={`Cycle time operation ${index + 1}`} placeholder="Cycle" value={op.cycle_time} onChange={(v) => update(op.key, { cycle_time: v })} error={opError(op, 'cycle_time')} />
                                        <MiniSelect label={`Time unit operation ${index + 1}`} value={op.time_unit} options={TIME_UNITS} onChange={(v) => update(op.key, { time_unit: v })} />
                                        <MiniInput label={`Process loss % operation ${index + 1}`} placeholder="Loss %" value={op.process_loss_percent} onChange={(v) => update(op.key, { process_loss_percent: v })} error={opError(op, 'process_loss_percent')} />
                                        <Checkbox label="QC" checked={op.requires_qc} onChange={(e) => update(op.key, { requires_qc: e.target.checked })} />
                                        <MiniInput label={`Remarks operation ${index + 1}`} inputMode="text" placeholder="Remarks" value={op.remarks} onChange={(v) => update(op.key, { remarks: v })} />
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </Card>
            </fieldset>
            <div className="sticky bottom-0 mt-6 flex justify-end gap-2 border-t border-slate-200 bg-slate-50/95 py-3 backdrop-blur">
                <ButtonLink to={isEdit ? `/master-data/routings/${id}` : '/master-data/routings'} variant="secondary">Cancel</ButtonLink>
                <Button type="submit" loading={saving}>Save draft</Button>
            </div>
        </form>
    );
}

export function RoutingDetailsPage() {
    const { id } = useParams();
    const { data, loading, error, reload } = useApi<{ data: Routing }>(`/product-routings/${id}`);
    if (loading) return <LoadingBlock />;
    if (error || !data) return <Alert tone="danger">{error ?? 'Routing not found.'}</Alert>;
    const r = data.data;

    return (
        <>
            <PageHeader title={`${r.number} · v${r.version}`} breadcrumb={<Link to="/master-data/routings" className="hover:text-slate-700">Product Routings</Link>} description={<>{r.item?.code} — {r.item?.name}</>} />
            <div className="mb-6"><DocumentActions kind={ROUTING_KIND} doc={r} onChanged={reload} /></div>
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <Card>
                    <CardHeader title="Header" />
                    <CardBody><DescriptionList items={[
                        { label: 'Status', value: <DocStatusBadge status={r.status} /> },
                        { label: 'Product', value: r.item ? <Link className="text-brand-700 hover:underline" to={`/master-data/items/${r.item.id}`}>{r.item.code}</Link> : '—' },
                        { label: 'Effective', value: `${formatDate(r.effective_from)} – ${r.effective_to ? formatDate(r.effective_to) : 'open'}` },
                        { label: 'Approved', value: r.approved_at ? `${r.approved_by?.full_name ?? ''} · ${formatDateTime(r.approved_at)}` : '—' },
                        { label: 'Revised from', value: r.revised_from ? <Link className="text-brand-700 hover:underline" to={`/master-data/routings/${r.revised_from.id}`}>{r.revised_from.number} v{r.revised_from.version}</Link> : '—' },
                        { label: 'Description', value: r.description || '—' },
                    ]} /></CardBody>
                </Card>
                <Card className="lg:col-span-2">
                    <CardHeader title="Operation sequence" />
                    {!r.operations?.length ? <EmptyState title="No operations" /> : (
                        <Table>
                            <thead><tr><Th>Seq</Th><Th>Process</Th><Th className="hidden md:table-cell">Department / machine</Th><Th className="hidden sm:table-cell">Setup / cycle</Th><Th className="hidden lg:table-cell">Loss %</Th><Th>QC</Th></tr></thead>
                            <tbody className="divide-y divide-slate-100">
                                {r.operations.map((o) => (
                                    <tr key={o.id}>
                                        <Td className="font-semibold tabular-nums">{o.sequence}</Td>
                                        <Td>{o.process?.name}<span className="block font-mono text-xs text-slate-500">{o.process?.code}</span></Td>
                                        <Td className="hidden text-xs md:table-cell">{o.department?.name ?? '—'}{o.machine && <span className="block">{o.machine.code}</span>}</Td>
                                        <Td className="hidden whitespace-nowrap text-xs sm:table-cell">{formatQty(o.setup_time)} / {formatQty(o.cycle_time)} {o.time_unit}</Td>
                                        <Td className="hidden lg:table-cell">{o.process_loss_percent}</Td>
                                        <Td>{o.requires_qc ? <Badge tone="info">QC</Badge> : '—'}</Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    )}
                </Card>
            </div>
        </>
    );
}
