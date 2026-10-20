import { useEffect, useState, type FormEvent } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { ChevronDown, ChevronRight, Download, Plus, Trash2 } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useApi } from '@/hooks/useApi';
import { Card, CardBody, CardHeader, DescriptionList } from '@/components/ui/Card';
import { Alert, Badge, EmptyState, PageHeader } from '@/components/ui/Feedback';
import { SelectInput, TextareaInput, TextInput } from '@/components/ui/Form';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Table, Td, Th } from '@/components/ui/Table';
import { LoadingBlock } from '@/components/ui/Spinner';
import { useToast } from '@/components/ui/Toast';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { formatDate, formatDateTime } from '@/lib/format';
import { DocStatusBadge, ItemPicker, MiniInput, MiniSelect, downloadFile, formatQty } from '../components/shared';
import type { Bom, Item, Uom } from '../types';
import { BOM_KIND, DocumentActions, DocumentListPage } from './DocumentCommon';

type ItemOption = Pick<Item, 'id' | 'code' | 'name' | 'item_type' | 'is_active'> & { base_uom?: Item['base_uom'] };
interface LineState { key: number; component: ItemOption | null; quantity: string; uom_id: string; scrap_percent: string; remarks: string }

let lineKey = 0;
const newLine = (): LineState => ({ key: ++lineKey, component: null, quantity: '', uom_id: '', scrap_percent: '0', remarks: '' });

export function BomListPage() {
    return <DocumentListPage kind={BOM_KIND} extraColumns={[{ label: 'Output', render: (d) => `${formatQty(d.output_qty as string)} ${(d.output_uom as { code: string } | undefined)?.code ?? ''}` }, { label: 'Lines', render: (d) => String(d.lines_count ?? '') }]} />;
}

export function BomEditorPage() {
    const { id } = useParams();
    const [query] = useSearchParams();
    const isEdit = Boolean(id);
    const navigate = useNavigate();
    const toast = useToast();
    const existing = useApi<{ data: Bom }>(isEdit ? `/boms/${id}` : null);
    const prefillItem = useApi<{ data: Item }>(!isEdit && query.get('item_id') ? `/items/${query.get('item_id')}` : null);
    const uoms = useApi<{ data: Uom[] }>('/uoms', { all: 1, status: 'active' });

    const [product, setProduct] = useState<ItemOption | null>(null);
    const [header, setHeader] = useState({ output_qty: '1000', output_uom_id: '', effective_from: new Date().toISOString().slice(0, 10), effective_to: '', description: '' });
    const [lines, setLines] = useState<LineState[]>([newLine()]);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [formError, setFormError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        const b = existing.data?.data;
        if (!b) return;
        setProduct(b.item ? { id: b.item.id, code: b.item.code, name: b.item.name, item_type: '', is_active: true, base_uom: b.item.base_uom ?? undefined } : null);
        setHeader({ output_qty: b.output_qty, output_uom_id: String(b.output_uom_id), effective_from: b.effective_from, effective_to: b.effective_to ?? '', description: b.description ?? '' });
        setLines((b.lines ?? []).map((l) => ({
            key: ++lineKey, component: l.component ? { ...l.component, base_uom: l.component.base_uom ?? undefined } : null,
            quantity: l.quantity, uom_id: String(l.uom_id), scrap_percent: l.scrap_percent, remarks: l.remarks ?? '',
        })));
    }, [existing.data]);

    useEffect(() => {
        const i = prefillItem.data?.data;
        if (i && !product) {
            setProduct({ id: i.id, code: i.code, name: i.name, item_type: i.item_type, is_active: i.is_active, base_uom: i.base_uom });
            setHeader((h) => ({ ...h, output_uom_id: h.output_uom_id || String(i.base_uom_id) }));
        }
    }, [prefillItem.data, product]);

    if (existing.loading || uoms.loading) return <LoadingBlock />;
    if (existing.error) return <Alert tone="danger">{existing.error}</Alert>;
    if (existing.data && !existing.data.data.is_editable) return <Alert tone="warning">This BOM is {existing.data.data.status} and can no longer be edited. Create a revision instead.</Alert>;

    const uomOptions = (uoms.data?.data ?? []).map((u) => ({ value: u.id, label: u.code }));
    const updateLine = (key: number, patch: Partial<LineState>) => setLines((ls) => ls.map((l) => (l.key === key ? { ...l, ...patch } : l)));

    const onSubmit = async (event: FormEvent) => {
        event.preventDefault();
        if (saving) return;
        if (!product) { setErrors({ item_id: 'Select the product.' }); return; }
        setSaving(true);
        setErrors({});
        setFormError(null);
        const payload = {
            ...(isEdit ? {} : { item_id: product.id }),
            output_qty: header.output_qty.trim(), output_uom_id: Number(header.output_uom_id) || null,
            effective_from: header.effective_from, effective_to: header.effective_to || null, description: header.description || null,
            lines: lines.filter((l) => l.component).map((l) => ({
                component_item_id: l.component!.id, quantity: l.quantity.trim(), uom_id: Number(l.uom_id) || null,
                scrap_percent: l.scrap_percent.trim() || '0', remarks: l.remarks || null,
            })),
        };
        try {
            if (isEdit) {
                await api.put(`/boms/${id}`, payload);
                toast.success('Draft saved.');
                navigate(`/master-data/boms/${id}`);
            } else {
                const { data } = await api.post<{ data: Bom }>('/boms', payload);
                toast.success(`Draft ${data.data.number} created.`);
                navigate(`/master-data/boms/${data.data.id}`);
            }
        } catch (err) {
            const fields = fieldErrors(err);
            // Line errors refer to the submitted (non-empty) lines.
            setErrors(fields);
            if (!Object.keys(fields).length) setFormError(errorMessage(err));
        } finally {
            setSaving(false);
        }
    };

    const submitted = lines.filter((l) => l.component);
    const lineError = (line: LineState, field: string) => {
        const index = submitted.indexOf(line);
        return index >= 0 ? errors[`lines.${index}.${field}`] : undefined;
    };

    return (
        <form onSubmit={onSubmit} noValidate>
            <PageHeader
                title={isEdit ? `Edit BOM ${existing.data?.data.number} (v${existing.data?.data.version})` : 'New bill of materials'}
                breadcrumb={<Link to="/master-data/boms" className="hover:text-slate-700">Bills of Materials</Link>}
                description="Quantities are planned requirements entered by authorised users. Saving or approving never moves stock."
            />
            {formError && <Alert tone="danger" className="mb-4">{formError}</Alert>}
            <fieldset disabled={saving} className="space-y-6">
                <Card>
                    <CardHeader title="Header" />
                    <CardBody className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="sm:col-span-2">
                            <ItemPicker label="Finished / semi-finished item" value={product} disabled={isEdit} error={errors.item_id}
                                params={{ can_have_bom: 1 }} onChange={(i) => { setProduct(i); if (i?.base_uom) setHeader((h) => ({ ...h, output_uom_id: String(i.base_uom!.id) })); }} />
                        </div>
                        <TextInput label="Output quantity" value={header.output_qty} onChange={(e) => setHeader({ ...header, output_qty: e.target.value })} error={errors.output_qty} inputMode="decimal" required />
                        <SelectInput label="Output UOM" value={header.output_uom_id} onChange={(e) => setHeader({ ...header, output_uom_id: e.target.value })} error={errors.output_uom_id} required>
                            <option value="">Select</option>
                            {uomOptions.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                        </SelectInput>
                        <TextInput label="Effective from" type="date" value={header.effective_from} onChange={(e) => setHeader({ ...header, effective_from: e.target.value })} error={errors.effective_from} required />
                        <TextInput label="Effective to (optional)" type="date" value={header.effective_to} onChange={(e) => setHeader({ ...header, effective_to: e.target.value })} error={errors.effective_to} />
                        <TextareaInput label="Description" value={header.description} onChange={(e) => setHeader({ ...header, description: e.target.value })} wrapperClassName="sm:col-span-2" />
                    </CardBody>
                </Card>

                <Card>
                    <CardHeader title="Components" description="Use any unit that converts to the component's base unit (universally or via an item-specific conversion)."
                        actions={<Button size="sm" variant="secondary" icon={<Plus className="h-4 w-4" />} onClick={() => setLines((ls) => [...ls, newLine()])}>Add component</Button>} />
                    <div className="divide-y divide-slate-100">
                        {lines.length === 0 && <EmptyState title="No components" />}
                        {lines.map((line, index) => (
                            <div key={line.key} className="grid grid-cols-2 gap-2 px-4 py-3 md:grid-cols-[2rem_minmax(14rem,2fr)_7rem_6rem_6rem_minmax(8rem,1fr)_2.5rem] md:items-start">
                                <span className="hidden pt-2 text-xs text-slate-400 md:block">{index + 1}</span>
                                <div className="col-span-2 md:col-span-1">
                                    <ItemPicker compact value={line.component} error={lineError(line, 'component_item_id')} placeholder="Component item"
                                        onChange={(i) => updateLine(line.key, { component: i, uom_id: line.uom_id || (i?.base_uom ? String(i.base_uom.id) : '') })} />
                                </div>
                                <MiniInput label={`Quantity line ${index + 1}`} value={line.quantity} placeholder="Qty" onChange={(v) => updateLine(line.key, { quantity: v })} error={lineError(line, 'quantity')} />
                                <MiniSelect label={`UOM line ${index + 1}`} value={line.uom_id} placeholder="UOM" options={uomOptions} onChange={(v) => updateLine(line.key, { uom_id: v })} error={lineError(line, 'uom_id')} />
                                <MiniInput label={`Scrap % line ${index + 1}`} value={line.scrap_percent} placeholder="Scrap %" onChange={(v) => updateLine(line.key, { scrap_percent: v })} error={lineError(line, 'scrap_percent')} />
                                <MiniInput label={`Remarks line ${index + 1}`} value={line.remarks} inputMode="text" placeholder="Remarks" onChange={(v) => updateLine(line.key, { remarks: v })} />
                                <Button variant="ghost" size="sm" aria-label={`Remove line ${index + 1}`} icon={<Trash2 className="h-4 w-4 text-red-500" />} onClick={() => setLines((ls) => ls.filter((l) => l.key !== line.key))} />
                            </div>
                        ))}
                    </div>
                </Card>
            </fieldset>
            <div className="sticky bottom-0 mt-6 flex justify-end gap-2 border-t border-slate-200 bg-slate-50/95 py-3 backdrop-blur">
                <ButtonLink to={isEdit ? `/master-data/boms/${id}` : '/master-data/boms'} variant="secondary">Cancel</ButtonLink>
                <Button type="submit" loading={saving}>Save draft</Button>
            </div>
        </form>
    );
}

interface StructureNode {
    level: number; line_no: number; item: { id: number; code: string; name: string }; quantity: string; uom: string; scrap_percent: string;
    base_quantity_with_scrap: string | null; base_uom: string; sub_bom: string | null; remarks: string | null; children: StructureNode[];
}

function StructureTree({ nodes }: { nodes: StructureNode[] }) {
    const [closed, setClosed] = useState<Set<string>>(new Set());
    const render = (list: StructureNode[], path: string) => (
        <ul className={path ? 'ml-5 border-l border-slate-200 pl-2' : undefined}>
            {list.map((n) => {
                const key = `${path}/${n.line_no}`;
                const open = !closed.has(key);
                return (
                    <li key={key}>
                        <div className="flex flex-wrap items-center gap-2 py-1.5 text-sm">
                            {n.children.length ? (
                                <button type="button" className="rounded p-0.5 text-slate-400 hover:bg-slate-200" aria-label={`${open ? 'Collapse' : 'Expand'} ${n.item.code}`}
                                    onClick={() => setClosed((s) => { const x = new Set(s); x.has(key) ? x.delete(key) : x.add(key); return x; })}>
                                    {open ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
                                </button>
                            ) : <span className="w-5" />}
                            <span className="font-mono text-xs font-semibold">{n.item.code}</span>
                            <span className="text-slate-700">{n.item.name}</span>
                            <span className="text-slate-500">{formatQty(n.quantity)} {n.uom}{Number(n.scrap_percent) > 0 && ` + ${n.scrap_percent}% scrap`}</span>
                            {n.base_quantity_with_scrap && <Badge>{formatQty(n.base_quantity_with_scrap)} {n.base_uom}</Badge>}
                            {n.sub_bom && <Badge tone="info">{n.sub_bom}</Badge>}
                        </div>
                        {n.children.length > 0 && open && render(n.children, key)}
                    </li>
                );
            })}
        </ul>
    );
    return render(nodes, '');
}

export function BomDetailsPage() {
    const { id } = useParams();
    const { can } = useAuth();
    const toast = useToast();
    const { data, loading, error, reload } = useApi<{ data: Bom }>(`/boms/${id}`);
    const structure = useApi<{ data: { components: StructureNode[] } }>(`/boms/${id}/structure`);

    if (loading) return <LoadingBlock />;
    if (error || !data) return <Alert tone="danger">{error ?? 'BOM not found.'}</Alert>;
    const bom = data.data;

    return (
        <>
            <PageHeader
                title={`${bom.number} · v${bom.version}`}
                breadcrumb={<Link to="/master-data/boms" className="hover:text-slate-700">Bills of Materials</Link>}
                description={<>{bom.item?.code} — {bom.item?.name}</>}
                actions={can('boms.export') && <Button variant="secondary" icon={<Download className="h-4 w-4" />} onClick={() => downloadFile(`/boms/${bom.id}/export`, { format: 'xlsx' }, `${bom.number}.xlsx`).catch((e) => toast.error(errorMessage(e)))}>Export</Button>}
            />
            <div className="mb-6"><DocumentActions kind={BOM_KIND} doc={bom} onChanged={() => { reload(); structure.reload(); }} /></div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <Card>
                    <CardHeader title="Header" />
                    <CardBody>
                        <DescriptionList items={[
                            { label: 'Status', value: <DocStatusBadge status={bom.status} /> },
                            { label: 'Product', value: bom.item ? <Link className="text-brand-700 hover:underline" to={`/master-data/items/${bom.item.id}`}>{bom.item.code}</Link> : '—' },
                            { label: 'Output', value: `${formatQty(bom.output_qty)} ${bom.output_uom?.code ?? ''}` },
                            { label: 'Effective', value: `${formatDate(bom.effective_from)} – ${bom.effective_to ? formatDate(bom.effective_to) : 'open'}` },
                            { label: 'Approved', value: bom.approved_at ? `${bom.approved_by?.full_name ?? ''} · ${formatDateTime(bom.approved_at)}` : '—' },
                            { label: 'Revised from', value: bom.revised_from ? <Link className="text-brand-700 hover:underline" to={`/master-data/boms/${bom.revised_from.id}`}>{bom.revised_from.number} v{bom.revised_from.version}</Link> : '—' },
                            { label: 'Description', value: bom.description || '—' },
                        ]} />
                    </CardBody>
                </Card>
                <Card className="lg:col-span-2">
                    <CardHeader title="Components" description={`Per ${formatQty(bom.output_qty)} ${bom.output_uom?.code ?? ''} of output`} />
                    {!bom.lines?.length ? <EmptyState title="No components" /> : (
                        <Table>
                            <thead><tr><Th>#</Th><Th>Component</Th><Th className="text-right">Quantity</Th><Th className="hidden sm:table-cell text-right">Scrap %</Th><Th className="hidden md:table-cell">Remarks</Th></tr></thead>
                            <tbody className="divide-y divide-slate-100">
                                {bom.lines.map((l) => (
                                    <tr key={l.id}>
                                        <Td className="text-xs text-slate-400">{l.line_no}</Td>
                                        <Td><span className="font-mono text-xs font-semibold">{l.component?.code}</span><span className="block text-xs text-slate-500">{l.component?.name}</span></Td>
                                        <Td className="whitespace-nowrap text-right tabular-nums">{formatQty(l.quantity)} {l.uom?.code}</Td>
                                        <Td className="hidden text-right tabular-nums sm:table-cell">{l.scrap_percent}</Td>
                                        <Td className="hidden text-xs md:table-cell">{l.remarks ?? '—'}</Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    )}
                </Card>
            </div>

            <Card className="mt-6">
                <CardHeader title="Multi-level structure" description="Sub-assemblies expand using their currently effective approved BOM. Base quantities include expected scrap." />
                <CardBody>
                    {structure.loading ? <LoadingBlock /> : structure.data?.data.components.length ? <StructureTree nodes={structure.data.data.components} /> : <p className="text-sm text-slate-500">No components.</p>}
                </CardBody>
            </Card>
        </>
    );
}
