import { useState, type FormEvent } from 'react';
import { ArrowRight, Plus, Power } from 'lucide-react';
import { useAuth } from '@/auth/AuthContext';
import { useApi } from '@/hooks/useApi';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Alert, Badge, StatusBadge } from '@/components/ui/Feedback';
import { SelectInput, TextInput } from '@/components/ui/Form';
import { Button } from '@/components/ui/Button';
import { Modal } from '@/components/ui/Modal';
import { Table, Td, Th } from '@/components/ui/Table';
import { LoadingBlock } from '@/components/ui/Spinner';
import { useToast } from '@/components/ui/Toast';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { useStatusAction } from '@/pages/structure/components/useStatusAction';
import { SimpleMasterPage, emptyToNull, tagsToArray, type SimpleMasterConfig } from './components/SimpleMasterPage';
import { CAPACITY_PERIODS, QC_DATA_TYPES, UOM_DIMENSIONS, type Machine, type Process, type QcParameter, type Uom, type UomConversion } from './types';

const yesNo = (v: boolean) => (v ? 'Yes' : 'No');
const label = (list: { value: string; label: string }[], v: string) => list.find((o) => o.value === v)?.label ?? v;
const uomSource = (params: Record<string, unknown> = {}) => ({ url: '/uoms', params: { status: 'active', ...params }, map: (u: Record<string, unknown>) => ({ value: u.id as number, label: `${u.code} — ${u.name}` }) });
const departmentSource = { url: '/departments', params: { status: 'active' }, map: (d: Record<string, unknown>) => ({ value: d.id as number, label: `${d.name} (${(d.factory as { code?: string } | undefined)?.code ?? ''})` }) };

// ---- Units of measurement ---------------------------------------------

const uomConfig: SimpleMasterConfig<Uom> = {
    title: 'Units of Measurement',
    description: 'Units and their physical dimension. Count and packaging units only convert through item-specific conversions.',
    endpoint: '/uoms',
    noun: 'unit',
    permissions: { create: 'uoms.create', update: 'uoms.update', deactivate: 'uoms.update' },
    filters: [{ key: 'dimension', label: 'Dimension', options: UOM_DIMENSIONS }],
    columns: [
        { key: 'code', label: 'Code', sortable: true, render: (u) => <span className="font-mono text-xs font-semibold">{u.code}</span> },
        { key: 'name', label: 'Name', sortable: true },
        { key: 'dimension', label: 'Dimension', sortable: true, render: (u) => <span>{u.dimension_label}{!u.universally_convertible && <Badge className="ml-2">item-specific only</Badge>}</span> },
        { key: 'decimal_precision', label: 'Decimals', sortable: true, className: 'text-right tabular-nums' },
    ],
    fields: [
        { name: 'code', label: 'UOM code', type: 'text', required: true, uppercase: true, maxLength: 12 },
        { name: 'name', label: 'UOM name', type: 'text', required: true, maxLength: 60 },
        { name: 'dimension', label: 'Dimension / type', type: 'select', required: true, options: UOM_DIMENSIONS, hint: 'Cannot change once the unit is in use.' },
        { name: 'decimal_precision', label: 'Decimal precision', type: 'number', required: true, hint: '0–6 decimal places allowed for quantities.' },
    ],
    toForm: (u) => ({ code: u?.code ?? '', name: u?.name ?? '', dimension: u?.dimension ?? 'count', decimal_precision: u?.decimal_precision ?? 0 }),
    toPayload: (f) => ({ ...f, decimal_precision: Number(f.decimal_precision) }),
};

function ConversionsCard() {
    const { can } = useAuth();
    const toast = useToast();
    const conversions = useApi<{ data: UomConversion[] }>('/uom-conversions', { all: 1 });
    const uoms = useApi<{ data: Uom[] }>('/uoms', { all: 1, status: 'active' });
    const status = useStatusAction<UomConversion & { name: string }>('uom-conversions', 'conversion', () => conversions.reload());
    const [adding, setAdding] = useState(false);
    const [form, setForm] = useState({ from_uom_id: '', to_uom_id: '', factor: '' });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [calc, setCalc] = useState({ quantity: '1', from: 'KG', to: 'GM' });
    const [calcResult, setCalcResult] = useState<string | null>(null);

    const convertible = (uoms.data?.data ?? []).filter((u) => u.universally_convertible);
    const from = convertible.find((u) => String(u.id) === form.from_uom_id);
    const toOptions = convertible.filter((u) => !from || (u.dimension === from.dimension && u.id !== from.id));

    const save = async (event: FormEvent) => {
        event.preventDefault();
        if (saving) return;
        setSaving(true);
        try {
            await api.post('/uom-conversions', { from_uom_id: Number(form.from_uom_id), to_uom_id: Number(form.to_uom_id), factor: form.factor.trim() });
            toast.success('Conversion added.');
            setAdding(false);
            conversions.reload();
        } catch (err) {
            setErrors(fieldErrors(err));
            if (!Object.keys(fieldErrors(err)).length) toast.error(errorMessage(err));
        } finally {
            setSaving(false);
        }
    };

    const runCalc = async () => {
        try {
            const { data } = await api.get<{ data: { quantity: string } }>('/uom-conversions/convert', { params: calc });
            setCalcResult(`${calc.quantity} ${calc.from} = ${data.data.quantity} ${calc.to}`);
        } catch (err) {
            setCalcResult(Object.values(fieldErrors(err))[0] ?? errorMessage(err));
        }
    };

    return (
        <Card className="mt-6">
            <CardHeader
                title="Universal conversions"
                description="Only between units of the same convertible dimension. Factors chain automatically (e.g. MTR→CM and MTR→MM imply CM→MM)."
                actions={can('uoms.create') && <Button size="sm" icon={<Plus className="h-4 w-4" />} onClick={() => { setForm({ from_uom_id: '', to_uom_id: '', factor: '' }); setErrors({}); setAdding(true); }}>Add conversion</Button>}
            />
            {conversions.loading ? <LoadingBlock /> : (
                <Table>
                    <thead><tr><Th>Conversion</Th><Th className="hidden sm:table-cell">Dimension</Th><Th>Status</Th><Th><span className="sr-only">Actions</span></Th></tr></thead>
                    <tbody className="divide-y divide-slate-100">
                        {conversions.data?.data.map((c) => (
                            <tr key={c.id}>
                                <Td className="font-mono text-sm">1 {c.from_uom.code} <ArrowRight className="mx-1 inline h-3 w-3" /> {c.factor} {c.to_uom.code}</Td>
                                <Td className="hidden sm:table-cell">{uoms.data?.data.find((u) => u.id === c.from_uom_id)?.dimension_label}</Td>
                                <Td><StatusBadge active={c.is_active} /></Td>
                                <Td className="text-right">
                                    {can('uoms.update') && (
                                        <Button variant="ghost" size="sm" aria-label={`${c.is_active ? 'Deactivate' : 'Activate'} conversion ${c.from_uom.code} to ${c.to_uom.code}`} icon={<Power className={`h-4 w-4 ${c.is_active ? 'text-red-500' : 'text-emerald-600'}`} />}
                                            onClick={() => status.request({ ...c, name: `${c.from_uom.code} → ${c.to_uom.code}` })} />
                                    )}
                                </Td>
                            </tr>
                        ))}
                    </tbody>
                </Table>
            )}
            <CardBody className="border-t border-slate-200">
                <p className="mb-2 text-sm font-medium text-slate-700">Quick converter</p>
                <div className="flex flex-wrap items-end gap-2">
                    <TextInput label="Quantity" value={calc.quantity} onChange={(e) => setCalc({ ...calc, quantity: e.target.value })} inputMode="decimal" wrapperClassName="w-28" />
                    <SelectInput label="From" value={calc.from} onChange={(e) => setCalc({ ...calc, from: e.target.value })} wrapperClassName="w-28">
                        {convertible.map((u) => <option key={u.id} value={u.code}>{u.code}</option>)}
                    </SelectInput>
                    <SelectInput label="To" value={calc.to} onChange={(e) => setCalc({ ...calc, to: e.target.value })} wrapperClassName="w-28">
                        {convertible.map((u) => <option key={u.id} value={u.code}>{u.code}</option>)}
                    </SelectInput>
                    <Button variant="secondary" onClick={runCalc}>Convert</Button>
                    {calcResult && <span className="pb-2 text-sm text-slate-700" role="status">{calcResult}</span>}
                </div>
            </CardBody>
            {status.dialog}
            <Modal open={adding} onClose={() => setAdding(false)} title="Add universal conversion">
                <form onSubmit={save} className="space-y-4">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <SelectInput label="1 ×" value={form.from_uom_id} onChange={(e) => setForm({ ...form, from_uom_id: e.target.value, to_uom_id: '' })} error={errors.from_uom_id} required>
                            <option value="">Unit</option>
                            {convertible.map((u) => <option key={u.id} value={u.id}>{u.code} ({u.dimension_label})</option>)}
                        </SelectInput>
                        <TextInput label="equals" value={form.factor} onChange={(e) => setForm({ ...form, factor: e.target.value })} error={errors.factor} inputMode="decimal" placeholder="e.g. 1000" required />
                        <SelectInput label="of" value={form.to_uom_id} onChange={(e) => setForm({ ...form, to_uom_id: e.target.value })} error={errors.to_uom_id} required disabled={!from}>
                            <option value="">Unit</option>
                            {toOptions.map((u) => <option key={u.id} value={u.id}>{u.code}</option>)}
                        </SelectInput>
                    </div>
                    <p className="text-xs text-slate-500">PCS↔KG or BOX↔PCS are not universal; add them as item-specific conversions on the item.</p>
                    <div className="flex justify-end gap-2">
                        <Button variant="secondary" onClick={() => setAdding(false)} disabled={saving}>Cancel</Button>
                        <Button type="submit" loading={saving} disabled={!form.from_uom_id || !form.to_uom_id || !form.factor}>Save</Button>
                    </div>
                </form>
            </Modal>
        </Card>
    );
}

export function UomsPage() {
    const { can } = useAuth();
    return (
        <>
            <SimpleMasterPage config={uomConfig} />
            {can('uoms.view') && <ConversionsCard />}
        </>
    );
}

// ---- Material grades & families --------------------------------------

type Grade = { id: number; code: string; name: string; standard: string | null; description: string | null; family_id: number; family?: { code: string; name: string }; items_count?: number; is_active: boolean };
type Family = { id: number; code: string; name: string; description: string | null; grades_count?: number; is_active: boolean };

const gradeConfig: SimpleMasterConfig<Grade> = {
    title: 'Material Grades',
    description: 'Grades such as SS304 or brass grades, grouped by material family. Items may have no grade.',
    endpoint: '/material-grades',
    noun: 'grade',
    searchPlaceholder: 'Search code, name or standard',
    permissions: { create: 'material-grades.create', update: 'material-grades.update', deactivate: 'material-grades.update' },
    columns: [
        { key: 'code', label: 'Code', sortable: true, render: (g) => <span className="font-mono text-xs font-semibold">{g.code}</span> },
        { key: 'name', label: 'Name', sortable: true },
        { key: 'family', label: 'Family', render: (g) => g.family?.name ?? '—' },
        { key: 'standard', label: 'Standard', className: 'hidden md:table-cell', render: (g) => g.standard ?? '—' },
        { key: 'items_count', label: 'Items', className: 'hidden sm:table-cell text-right tabular-nums' },
    ],
    fields: [
        { name: 'family_id', label: 'Material family', type: 'select', required: true, source: { url: '/material-families', params: { status: 'active' }, map: (f) => ({ value: f.id as number, label: f.name as string }) } },
        { name: 'code', label: 'Grade code', type: 'text', required: true, uppercase: true, maxLength: 30 },
        { name: 'name', label: 'Grade name', type: 'text', required: true, maxLength: 100 },
        { name: 'standard', label: 'Standard / specification', type: 'text', maxLength: 150, placeholder: 'e.g. ASTM A276' },
        { name: 'description', label: 'Description', type: 'textarea' },
    ],
    toForm: (g) => ({ family_id: g?.family_id ?? '', code: g?.code ?? '', name: g?.name ?? '', standard: g?.standard ?? '', description: g?.description ?? '' }),
    toPayload: (f) => ({ ...f, family_id: Number(f.family_id), standard: emptyToNull(f.standard), description: emptyToNull(f.description) }),
};

const familyConfig: SimpleMasterConfig<Family> = {
    title: 'Material families',
    description: 'Stainless Steel, Brass, Aluminium and any others you configure.',
    endpoint: '/material-families',
    noun: 'family',
    embedded: true,
    permissions: { create: 'material-grades.create', update: 'material-grades.update', deactivate: 'material-grades.update' },
    columns: [
        { key: 'code', label: 'Code', sortable: true, render: (f) => <span className="font-mono text-xs font-semibold">{f.code}</span> },
        { key: 'name', label: 'Name', sortable: true },
        { key: 'grades_count', label: 'Grades', className: 'text-right tabular-nums' },
    ],
    fields: [
        { name: 'code', label: 'Family code', type: 'text', required: true, uppercase: true, maxLength: 20 },
        { name: 'name', label: 'Family name', type: 'text', required: true, maxLength: 100 },
        { name: 'description', label: 'Description', type: 'textarea' },
    ],
    toForm: (f) => ({ code: f?.code ?? '', name: f?.name ?? '', description: f?.description ?? '' }),
};

export function MaterialGradesPage() {
    return (
        <>
            <SimpleMasterPage config={gradeConfig} />
            <div className="mt-8"><SimpleMasterPage config={familyConfig} /></div>
        </>
    );
}

// ---- Manufacturing processes -----------------------------------------

export function ProcessesPage() {
    const config: SimpleMasterConfig<Process> = {
        title: 'Manufacturing Processes',
        description: 'Configurable processes used in routings and machine capabilities. No sequence is fixed here.',
        endpoint: '/manufacturing-processes',
        noun: 'process',
        permissions: { create: 'processes.create', update: 'processes.update', deactivate: 'processes.update' },
        filters: [
            { key: 'process_type', label: 'Type', options: [{ value: 'internal', label: 'Internal' }, { value: 'external', label: 'External (job work)' }] },
            { key: 'requires_qc', label: 'Requires QC', options: [{ value: '1', label: 'Yes' }, { value: '0', label: 'No' }] },
        ],
        columns: [
            { key: 'code', label: 'Code', sortable: true, render: (p) => <span className="font-mono text-xs font-semibold">{p.code}</span> },
            { key: 'name', label: 'Process', sortable: true },
            { key: 'process_type', label: 'Type', sortable: true, render: (p) => <Badge tone={p.process_type === 'external' ? 'info' : 'neutral'}>{p.process_type === 'external' ? 'External' : 'Internal'}</Badge> },
            { key: 'default_department', label: 'Default department', className: 'hidden md:table-cell', render: (p) => p.default_department?.name ?? '—' },
            { key: 'requires_qc', label: 'QC', className: 'hidden sm:table-cell', render: (p) => yesNo(p.requires_qc) },
        ],
        fields: [
            { name: 'code', label: 'Process code', type: 'text', required: true, uppercase: true, maxLength: 30 },
            { name: 'name', label: 'Process name', type: 'text', required: true, maxLength: 150 },
            { name: 'process_type', label: 'Process type', type: 'select', required: true, options: [{ value: 'internal', label: 'Internal' }, { value: 'external', label: 'External (job work)' }] },
            { name: 'default_department_id', label: 'Default department', type: 'select', source: departmentSource },
            { name: 'requires_qc', label: 'Requires QC', type: 'checkbox', hint: 'Default for routing operations.' },
            { name: 'description', label: 'Description', type: 'textarea' },
        ],
        toForm: (p) => ({ code: p?.code ?? '', name: p?.name ?? '', process_type: p?.process_type ?? 'internal', default_department_id: p?.default_department_id ?? '', requires_qc: p?.requires_qc ?? false, description: p?.description ?? '' }),
        toPayload: (f) => ({ ...f, default_department_id: f.default_department_id ? Number(f.default_department_id) : null, description: emptyToNull(f.description) }),
    };
    return <SimpleMasterPage config={config} />;
}

// ---- Machines & workstations ------------------------------------------

type MachineRow = Machine & {
    machine_type: string | null; workstation: string | null; manufacturer: string | null; model: string | null;
    capacity_qty: string | null; capacity_uom_id: number | null; capacity_uom?: { code: string } | null;
    capacity_period: string | null; capacity_period_hours: string | null; working_hours_per_day: string | null; remarks: string | null;
};

export function MachinesPage() {
    const config: SimpleMasterConfig<MachineRow> = {
        title: 'Machines & Workstations',
        description: 'Machines per department with capacity and the processes they can perform. Maintenance and monitoring come later.',
        endpoint: '/machines',
        noun: 'machine',
        searchPlaceholder: 'Search code, name, type or workstation',
        permissions: { create: 'machines.create', update: 'machines.update', deactivate: 'machines.update' },
        columns: [
            { key: 'code', label: 'Code', sortable: true, render: (m) => <span className="font-mono text-xs font-semibold">{m.code}</span> },
            { key: 'name', label: 'Machine', sortable: true, render: (m) => <span>{m.name}{m.machine_type && <span className="block text-xs text-slate-500">{m.machine_type}</span>}</span> },
            { key: 'department', label: 'Department', className: 'hidden md:table-cell', render: (m) => `${m.department?.name ?? ''} (${m.factory?.code ?? ''})` },
            { key: 'capacity', label: 'Capacity', className: 'hidden lg:table-cell', render: (m) => m.capacity_qty ? `${m.capacity_qty} ${m.capacity_uom?.code ?? ''} / ${m.capacity_period} (${m.capacity_period_hours} h)` : '—' },
            { key: 'processes', label: 'Processes', className: 'hidden sm:table-cell', render: (m) => <div className="flex flex-wrap gap-1">{m.processes?.map((p) => <Badge key={p.id}>{p.code}</Badge>)}</div> },
        ],
        fields: [
            { name: 'department_id', label: 'Department', type: 'select', required: true, source: departmentSource, hint: 'Machines cannot move to another factory.' },
            { name: 'code', label: 'Machine code', type: 'text', required: true, uppercase: true, maxLength: 30 },
            { name: 'name', label: 'Machine name', type: 'text', required: true, maxLength: 150 },
            { name: 'machine_type', label: 'Machine type', type: 'text', maxLength: 80, placeholder: 'e.g. CNC Lathe' },
            { name: 'workstation', label: 'Workstation', type: 'text', maxLength: 100 },
            { name: 'manufacturer', label: 'Manufacturer', type: 'text', maxLength: 100 },
            { name: 'model', label: 'Model', type: 'text', maxLength: 100 },
            { name: 'capacity_qty', label: 'Capacity', type: 'number', hint: 'e.g. 500' },
            { name: 'capacity_uom_id', label: 'Capacity unit', type: 'select', source: uomSource() },
            { name: 'capacity_period', label: 'Per', type: 'select', options: CAPACITY_PERIODS },
            { name: 'capacity_period_hours', label: 'Period length (hours)', type: 'number', hint: 'e.g. 8 for an 8-hour shift' },
            { name: 'working_hours_per_day', label: 'Working hours per day', type: 'number' },
            { name: 'process_ids', label: 'Supported processes', type: 'multiselect', wide: true, source: { url: '/manufacturing-processes', params: { status: 'active' }, map: (p) => ({ value: p.id as number, label: `${p.code} — ${p.name}` }) } },
            { name: 'remarks', label: 'Remarks', type: 'textarea' },
        ],
        toForm: (m) => ({
            department_id: m?.department_id ?? '', code: m?.code ?? '', name: m?.name ?? '', machine_type: m?.machine_type ?? '', workstation: m?.workstation ?? '',
            manufacturer: m?.manufacturer ?? '', model: m?.model ?? '', capacity_qty: m?.capacity_qty ?? '', capacity_uom_id: m?.capacity_uom_id ?? '',
            capacity_period: m?.capacity_period ?? '', capacity_period_hours: m?.capacity_period_hours ?? '', working_hours_per_day: m?.working_hours_per_day ?? '',
            process_ids: m?.processes?.map((p) => p.id) ?? [], remarks: m?.remarks ?? '',
        }),
        toPayload: (f) => Object.fromEntries(Object.entries(f).map(([k, v]) => [k, k === 'process_ids' ? v : (k.endsWith('_id') && v ? Number(v) : emptyToNull(v))])),
    };
    return <SimpleMasterPage config={config} />;
}

// ---- QC parameters --------------------------------------------------

export function QcParametersPage() {
    const config: SimpleMasterConfig<QcParameter> = {
        title: 'QC Parameters',
        description: 'Inspection characteristics used in QC specifications. Not every parameter has numeric limits.',
        endpoint: '/qc-parameters',
        noun: 'parameter',
        permissions: { create: 'qc-specifications.create', update: 'qc-specifications.update', deactivate: 'qc-specifications.update' },
        filters: [{ key: 'data_type', label: 'Data type', options: QC_DATA_TYPES }],
        columns: [
            { key: 'code', label: 'Code', sortable: true, render: (p) => <span className="font-mono text-xs font-semibold">{p.code}</span> },
            { key: 'name', label: 'Parameter', sortable: true },
            { key: 'data_type', label: 'Data type', sortable: true, render: (p) => label(QC_DATA_TYPES, p.data_type) },
            { key: 'default_uom', label: 'Unit / options', className: 'hidden md:table-cell', render: (p) => p.default_uom?.code ?? (p.options.length ? p.options.join(', ') : '—') },
        ],
        fields: [
            { name: 'code', label: 'Parameter code', type: 'text', required: true, uppercase: true, maxLength: 30 },
            { name: 'name', label: 'Parameter name', type: 'text', required: true, maxLength: 150 },
            { name: 'data_type', label: 'Data type', type: 'select', required: true, options: QC_DATA_TYPES },
            { name: 'default_uom_id', label: 'Measurement unit', type: 'select', source: uomSource(), showWhen: (f) => f.data_type === 'numeric', hint: 'Specification limits must use a unit of the same dimension.' },
            { name: 'options', label: 'Selection options', type: 'tags', showWhen: (f) => f.data_type === 'selection' },
            { name: 'description', label: 'Description', type: 'textarea' },
        ],
        toForm: (p) => ({ code: p?.code ?? '', name: p?.name ?? '', data_type: p?.data_type ?? 'numeric', default_uom_id: p?.default_uom_id ?? '', options: p?.options ?? [], description: p?.description ?? '' }),
        toPayload: (f) => ({
            code: f.code, name: f.name, data_type: f.data_type, description: emptyToNull(f.description),
            default_uom_id: f.data_type === 'numeric' && f.default_uom_id ? Number(f.default_uom_id) : null,
            options: f.data_type === 'selection' ? tagsToArray(f.options) : null,
        }),
    };
    return <SimpleMasterPage config={config} />;
}

export function NoAccess() {
    return <Alert tone="info">You do not have access to this section.</Alert>;
}
