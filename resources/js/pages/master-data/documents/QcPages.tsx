import { useEffect, useState, type FormEvent } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { Plus, Trash2 } from 'lucide-react';
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
import { DocStatusBadge, ItemPicker, MiniInput, MiniSelect } from '../components/shared';
import type { Item, Process, QcParameter, QcSpecification, Uom } from '../types';
import { DocumentActions, DocumentListPage, QC_KIND } from './DocumentCommon';

type ItemOption = Pick<Item, 'id' | 'code' | 'name' | 'item_type' | 'is_active'> & { base_uom?: Item['base_uom'] };
interface LineState { key: number; parameter_id: string; process_id: string; target_value: string; min_value: string; max_value: string; uom_id: string; expected_text: string; allowed_options: string[]; inspection_method: string; is_required: boolean }

let lineKey = 0;
const newLine = (): LineState => ({ key: ++lineKey, parameter_id: '', process_id: '', target_value: '', min_value: '', max_value: '', uom_id: '', expected_text: '', allowed_options: [], inspection_method: '', is_required: true });

/** Human summary of the acceptance criterion. */
export function criterion(l: { target_value: string | null; min_value: string | null; max_value: string | null; uom?: { code: string } | null; expected_text: string | null; allowed_options: string[]; parameter?: { data_type: string } | null }): string {
    if (l.parameter?.data_type === 'numeric') {
        const u = l.uom?.code ?? '';
        if (l.min_value && l.max_value) return `${l.min_value} – ${l.max_value} ${u}${l.target_value ? ` (target ${l.target_value})` : ''}`;
        if (l.min_value) return `≥ ${l.min_value} ${u}`;
        if (l.max_value) return `≤ ${l.max_value} ${u}`;
        return `${l.target_value} ${u}`;
    }
    if (l.parameter?.data_type === 'selection') return l.allowed_options.join(' / ');
    return l.expected_text || (l.parameter?.data_type === 'pass_fail' ? 'Pass' : 'Observation');
}

export function QcListPage() {
    return <DocumentListPage kind={QC_KIND} extraColumns={[{ label: 'Parameters', render: (d) => String(d.lines_count ?? '') }]} />;
}

export function QcEditorPage() {
    const { id } = useParams();
    const [query] = useSearchParams();
    const isEdit = Boolean(id);
    const navigate = useNavigate();
    const toast = useToast();
    const existing = useApi<{ data: QcSpecification }>(isEdit ? `/qc-specifications/${id}` : null);
    const prefillItem = useApi<{ data: Item }>(!isEdit && query.get('item_id') ? `/items/${query.get('item_id')}` : null);
    const parameters = useApi<{ data: QcParameter[] }>('/qc-parameters', { all: 1, status: 'active' });
    const processes = useApi<{ data: Process[] }>('/manufacturing-processes', { all: 1, status: 'active' });
    const uoms = useApi<{ data: Uom[] }>('/uoms', { all: 1, status: 'active' });

    const [item, setItem] = useState<ItemOption | null>(null);
    const [header, setHeader] = useState({ effective_from: new Date().toISOString().slice(0, 10), effective_to: '', description: '', inspection_instructions: '' });
    const [lines, setLines] = useState<LineState[]>([newLine()]);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [formError, setFormError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        const s = existing.data?.data;
        if (!s) return;
        setItem(s.item ? { id: s.item.id, code: s.item.code, name: s.item.name, item_type: '', is_active: true } : null);
        setHeader({ effective_from: s.effective_from, effective_to: s.effective_to ?? '', description: s.description ?? '', inspection_instructions: s.inspection_instructions ?? '' });
        setLines((s.lines ?? []).map((l) => ({
            key: ++lineKey, parameter_id: String(l.parameter_id), process_id: l.process_id ? String(l.process_id) : '', target_value: l.target_value ?? '',
            min_value: l.min_value ?? '', max_value: l.max_value ?? '', uom_id: l.uom_id ? String(l.uom_id) : '', expected_text: l.expected_text ?? '',
            allowed_options: l.allowed_options ?? [], inspection_method: l.inspection_method ?? '', is_required: l.is_required,
        })));
    }, [existing.data]);

    useEffect(() => {
        const i = prefillItem.data?.data;
        if (i && !item) setItem({ id: i.id, code: i.code, name: i.name, item_type: i.item_type, is_active: i.is_active });
    }, [prefillItem.data, item]);

    if (existing.loading || parameters.loading) return <LoadingBlock />;
    if (existing.error) return <Alert tone="danger">{existing.error}</Alert>;
    if (existing.data && !existing.data.data.is_editable) return <Alert tone="warning">This specification is {existing.data.data.status} and can no longer be edited. Create a revision instead.</Alert>;

    const params = parameters.data?.data ?? [];
    const update = (key: number, patch: Partial<LineState>) => setLines((ls) => ls.map((l) => (l.key === key ? { ...l, ...patch } : l)));

    const onSubmit = async (event: FormEvent) => {
        event.preventDefault();
        if (saving) return;
        if (!item) { setErrors({ item_id: 'Select the item.' }); return; }
        setSaving(true);
        setErrors({});
        setFormError(null);
        const payload = {
            ...(isEdit ? {} : { item_id: item.id }),
            effective_from: header.effective_from, effective_to: header.effective_to || null, description: header.description || null, inspection_instructions: header.inspection_instructions || null,
            lines: lines.filter((l) => l.parameter_id).map((l) => {
                const type = params.find((p) => String(p.id) === l.parameter_id)?.data_type;
                return {
                    parameter_id: Number(l.parameter_id), process_id: l.process_id ? Number(l.process_id) : null, is_required: l.is_required, inspection_method: l.inspection_method || null,
                    target_value: type === 'numeric' ? l.target_value || null : null, min_value: type === 'numeric' ? l.min_value || null : null, max_value: type === 'numeric' ? l.max_value || null : null,
                    uom_id: type === 'numeric' && l.uom_id ? Number(l.uom_id) : null, expected_text: type !== 'numeric' ? l.expected_text || null : null,
                    allowed_options: type === 'selection' ? l.allowed_options : null,
                };
            }),
        };
        try {
            if (isEdit) {
                await api.put(`/qc-specifications/${id}`, payload);
                toast.success('Draft saved.');
                navigate(`/master-data/qc-specifications/${id}`);
            } else {
                const { data } = await api.post<{ data: QcSpecification }>('/qc-specifications', payload);
                toast.success(`Draft ${data.data.number} created.`);
                navigate(`/master-data/qc-specifications/${data.data.id}`);
            }
        } catch (err) {
            setErrors(fieldErrors(err));
            if (!Object.keys(fieldErrors(err)).length) setFormError(errorMessage(err));
        } finally {
            setSaving(false);
        }
    };

    const submitted = lines.filter((l) => l.parameter_id);
    const lineError = (line: LineState, field: string) => {
        const index = submitted.indexOf(line);
        return index >= 0 ? errors[`lines.${index}.${field}`] : undefined;
    };

    return (
        <form onSubmit={onSubmit} noValidate>
            <PageHeader title={isEdit ? `Edit ${existing.data?.data.number} (Rev ${existing.data?.data.version})` : 'New QC specification'}
                breadcrumb={<Link to="/master-data/qc-specifications" className="hover:text-slate-700">QC Specifications</Link>}
                description="Numeric parameters need limits in a unit of the parameter's dimension; pass/fail, text and selection parameters do not take numeric limits." />
            {formError && <Alert tone="danger" className="mb-4">{formError}</Alert>}
            <fieldset disabled={saving} className="space-y-6">
                <Card>
                    <CardHeader title="Header" />
                    <CardBody className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="sm:col-span-2"><ItemPicker label="Item" value={item} disabled={isEdit} error={errors.item_id} onChange={setItem} /></div>
                        <TextInput label="Effective from" type="date" value={header.effective_from} onChange={(e) => setHeader({ ...header, effective_from: e.target.value })} error={errors.effective_from} required />
                        <TextInput label="Effective to (optional)" type="date" value={header.effective_to} onChange={(e) => setHeader({ ...header, effective_to: e.target.value })} error={errors.effective_to} />
                        <TextareaInput label="Description" value={header.description} onChange={(e) => setHeader({ ...header, description: e.target.value })} wrapperClassName="sm:col-span-2" />
                        <TextareaInput label="Inspection instructions" value={header.inspection_instructions} onChange={(e) => setHeader({ ...header, inspection_instructions: e.target.value })} wrapperClassName="sm:col-span-2" />
                    </CardBody>
                </Card>
                <Card>
                    <CardHeader title="Parameters" actions={<Button size="sm" variant="secondary" icon={<Plus className="h-4 w-4" />} onClick={() => setLines((ls) => [...ls, newLine()])}>Add parameter</Button>} />
                    <div className="divide-y divide-slate-100">
                        {lines.length === 0 && <EmptyState title="No parameters" />}
                        {lines.map((line, index) => {
                            const parameter = params.find((p) => String(p.id) === line.parameter_id);
                            const type = parameter?.data_type;
                            return (
                                <div key={line.key} className="space-y-2 px-4 py-3">
                                    <div className="grid grid-cols-2 gap-2 md:grid-cols-[2rem_minmax(12rem,1.5fr)_minmax(10rem,1fr)_minmax(8rem,1fr)_auto_2.5rem] md:items-start">
                                        <span className="hidden pt-2 text-xs text-slate-400 md:block">{index + 1}</span>
                                        <MiniSelect label={`Parameter line ${index + 1}`} value={line.parameter_id} placeholder="Parameter" error={lineError(line, 'parameter_id')}
                                            options={params.map((p) => ({ value: p.id, label: `${p.name} (${p.data_type.replace('_', '/')})` }))}
                                            onChange={(v) => { const p = params.find((x) => String(x.id) === v); update(line.key, { parameter_id: v, uom_id: p?.default_uom_id ? String(p.default_uom_id) : '', allowed_options: [] }); }} />
                                        <MiniSelect label={`Process line ${index + 1}`} value={line.process_id} placeholder="Any process" error={lineError(line, 'process_id')}
                                            options={(processes.data?.data ?? []).map((p) => ({ value: p.id, label: p.name }))} onChange={(v) => update(line.key, { process_id: v })} />
                                        <MiniInput label={`Inspection method line ${index + 1}`} inputMode="text" placeholder="Method (e.g. Vernier)" value={line.inspection_method} onChange={(v) => update(line.key, { inspection_method: v })} />
                                        <Checkbox label="Required" checked={line.is_required} onChange={(e) => update(line.key, { is_required: e.target.checked })} />
                                        <Button variant="ghost" size="sm" aria-label={`Remove parameter line ${index + 1}`} icon={<Trash2 className="h-4 w-4 text-red-500" />} onClick={() => setLines((ls) => ls.filter((l) => l.key !== line.key))} />
                                    </div>
                                    {type === 'numeric' && (
                                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 md:ml-8">
                                            <MiniInput label={`Minimum line ${index + 1}`} placeholder="Min" value={line.min_value} onChange={(v) => update(line.key, { min_value: v })} error={lineError(line, 'min_value')} />
                                            <MiniInput label={`Target line ${index + 1}`} placeholder="Target" value={line.target_value} onChange={(v) => update(line.key, { target_value: v })} error={lineError(line, 'target_value')} />
                                            <MiniInput label={`Maximum line ${index + 1}`} placeholder="Max" value={line.max_value} onChange={(v) => update(line.key, { max_value: v })} error={lineError(line, 'max_value')} />
                                            <MiniSelect label={`Unit line ${index + 1}`} value={line.uom_id} placeholder="Unit" error={lineError(line, 'uom_id')}
                                                options={(uoms.data?.data ?? []).filter((u) => !parameter?.default_uom || u.dimension === (uoms.data?.data.find((x) => x.id === parameter.default_uom_id)?.dimension ?? u.dimension)).map((u) => ({ value: u.id, label: u.code }))}
                                                onChange={(v) => update(line.key, { uom_id: v })} />
                                        </div>
                                    )}
                                    {type === 'selection' && (
                                        <div className="flex flex-wrap gap-3 md:ml-8">
                                            <span className="text-xs text-slate-500">Acceptable:</span>
                                            {parameter?.options.map((o) => (
                                                <Checkbox key={o} label={o} checked={line.allowed_options.includes(o)}
                                                    onChange={(e) => update(line.key, { allowed_options: e.target.checked ? [...line.allowed_options, o] : line.allowed_options.filter((x) => x !== o) })} />
                                            ))}
                                            {lineError(line, 'allowed_options') && <p className="w-full text-xs text-red-600">{lineError(line, 'allowed_options')}</p>}
                                        </div>
                                    )}
                                    {(type === 'pass_fail' || type === 'text') && (
                                        <div className="md:ml-8">
                                            <MiniInput label={`Criteria line ${index + 1}`} inputMode="text" placeholder={type === 'pass_fail' ? 'Pass criteria (e.g. no burrs or scratches)' : 'What to record'}
                                                value={line.expected_text} onChange={(v) => update(line.key, { expected_text: v })} error={lineError(line, 'min_value')} />
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                </Card>
            </fieldset>
            <div className="sticky bottom-0 mt-6 flex justify-end gap-2 border-t border-slate-200 bg-slate-50/95 py-3 backdrop-blur">
                <ButtonLink to={isEdit ? `/master-data/qc-specifications/${id}` : '/master-data/qc-specifications'} variant="secondary">Cancel</ButtonLink>
                <Button type="submit" loading={saving}>Save draft</Button>
            </div>
        </form>
    );
}

export function QcDetailsPage() {
    const { id } = useParams();
    const { data, loading, error, reload } = useApi<{ data: QcSpecification }>(`/qc-specifications/${id}`);
    if (loading) return <LoadingBlock />;
    if (error || !data) return <Alert tone="danger">{error ?? 'Specification not found.'}</Alert>;
    const s = data.data;

    return (
        <>
            <PageHeader title={`${s.number} · Rev ${s.version}`} breadcrumb={<Link to="/master-data/qc-specifications" className="hover:text-slate-700">QC Specifications</Link>} description={<>{s.item?.code} — {s.item?.name}</>} />
            <div className="mb-6"><DocumentActions kind={QC_KIND} doc={s} onChanged={reload} /></div>
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <Card>
                    <CardHeader title="Header" />
                    <CardBody><DescriptionList items={[
                        { label: 'Status', value: <DocStatusBadge status={s.status} /> },
                        { label: 'Item', value: s.item ? <Link className="text-brand-700 hover:underline" to={`/master-data/items/${s.item.id}`}>{s.item.code}</Link> : '—' },
                        { label: 'Effective', value: `${formatDate(s.effective_from)} – ${s.effective_to ? formatDate(s.effective_to) : 'open'}` },
                        { label: 'Approved', value: s.approved_at ? `${s.approved_by?.full_name ?? ''} · ${formatDateTime(s.approved_at)}` : '—' },
                        { label: 'Revised from', value: s.revised_from ? <Link className="text-brand-700 hover:underline" to={`/master-data/qc-specifications/${s.revised_from.id}`}>{s.revised_from.number} Rev {s.revised_from.version}</Link> : '—' },
                        { label: 'Inspection instructions', value: s.inspection_instructions || '—' },
                    ]} /></CardBody>
                </Card>
                <Card className="lg:col-span-2">
                    <CardHeader title="Parameters" />
                    {!s.lines?.length ? <EmptyState title="No parameters" /> : (
                        <Table>
                            <thead><tr><Th>Parameter</Th><Th>Acceptance</Th><Th className="hidden md:table-cell">Process</Th><Th className="hidden sm:table-cell">Method</Th><Th>Req.</Th></tr></thead>
                            <tbody className="divide-y divide-slate-100">
                                {s.lines.map((l) => (
                                    <tr key={l.id}>
                                        <Td>{l.parameter?.name}<span className="block text-xs text-slate-500">{l.parameter?.data_type.replace('_', '/')}</span></Td>
                                        <Td className="text-sm">{criterion(l)}</Td>
                                        <Td className="hidden text-xs md:table-cell">{l.process?.name ?? 'Any'}</Td>
                                        <Td className="hidden text-xs sm:table-cell">{l.inspection_method ?? '—'}</Td>
                                        <Td>{l.is_required ? <Badge tone="info">Yes</Badge> : 'No'}</Td>
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
