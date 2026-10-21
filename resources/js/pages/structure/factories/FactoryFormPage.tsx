import { useEffect, useState, type FormEvent } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useApi } from '@/hooks/useApi';
import { Card, CardBody, CardHeader } from '@/components/ui/Card';
import { Alert, PageHeader } from '@/components/ui/Feedback';
import { SelectInput, TextInput, Toggle } from '@/components/ui/Form';
import { Button, ButtonLink } from '@/components/ui/Button';
import { LoadingBlock } from '@/components/ui/Spinner';
import { useToast } from '@/components/ui/Toast';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { FACTORY_TYPES, type Factory } from '../types';

const blank = {
    code: '', name: '', type: 'manufacturing', address_line_1: '', address_line_2: '', city: '', state: '',
    postal_code: '', country: 'India', contact_person: '', contact_number: '', email: '', is_active: true,
};
type FormState = typeof blank;

export default function FactoryFormPage() {
    const { id } = useParams();
    const isEdit = Boolean(id);
    const navigate = useNavigate();
    const toast = useToast();
    const existing = useApi<{ data: Factory }>(isEdit ? `/factories/${id}` : null);

    const [form, setForm] = useState<FormState>(blank);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [formError, setFormError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        const f = existing.data?.data;
        if (f) {
            setForm({
                code: f.code, name: f.name, type: f.type, address_line_1: f.address_line_1 ?? '', address_line_2: f.address_line_2 ?? '',
                city: f.city ?? '', state: f.state ?? '', postal_code: f.postal_code ?? '', country: f.country ?? 'India',
                contact_person: f.contact_person ?? '', contact_number: f.contact_number ?? '', email: f.email ?? '', is_active: f.is_active,
            });
        }
    }, [existing.data]);

    if (existing.loading) return <LoadingBlock />;
    if (existing.error) return <Alert tone="danger">{existing.error}</Alert>;

    const set = (key: keyof FormState) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) =>
        setForm((f) => ({ ...f, [key]: key === 'code' ? e.target.value.toUpperCase() : e.target.value }));

    const onSubmit = async (event: FormEvent) => {
        event.preventDefault();
        if (saving) return;
        const clientErrors: Record<string, string> = {};
        if (!form.code.trim()) clientErrors.code = 'Factory code is required.';
        if (!form.name.trim()) clientErrors.name = 'Factory name is required.';
        setErrors(clientErrors);
        if (Object.keys(clientErrors).length) return;

        setSaving(true);
        setFormError(null);
        const { is_active, ...rest } = form;
        const payload = Object.fromEntries(Object.entries(rest).map(([k, v]) => [k, typeof v === 'string' && v.trim() === '' ? null : v]));
        try {
            if (isEdit) {
                await api.put(`/factories/${id}`, payload);
                toast.success('Factory updated.');
                navigate(`/factory-setup/factories/${id}`);
            } else {
                const { data } = await api.post<{ data: Factory }>('/factories', { ...payload, is_active });
                toast.success('Factory created.');
                navigate(`/factory-setup/factories/${data.data.id}`);
            }
        } catch (err) {
            const fields = fieldErrors(err);
            setErrors(fields);
            if (!Object.keys(fields).length) setFormError(errorMessage(err));
        } finally {
            setSaving(false);
        }
    };

    return (
        <form onSubmit={onSubmit} noValidate>
            <PageHeader
                title={isEdit ? `Edit factory: ${existing.data?.data.name ?? ''}` : 'Add factory'}
                breadcrumb={<Link to="/factory-setup/factories" className="hover:text-slate-700">Factories</Link>}
            />
            {formError && <Alert tone="danger" className="mb-6">{formError}</Alert>}
            <fieldset disabled={saving} className="space-y-6">
                <Card>
                    <CardHeader title="Factory details" />
                    <CardBody className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        <TextInput label="Factory code" value={form.code} onChange={set('code')} error={errors.code} required maxLength={20} hint="Unique within the company." autoFocus={!isEdit} />
                        <TextInput label="Factory name" value={form.name} onChange={set('name')} error={errors.name} required maxLength={150} />
                        <SelectInput label="Factory type" value={form.type} onChange={set('type')} error={errors.type} required>
                            {FACTORY_TYPES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                        </SelectInput>
                        {!isEdit && (
                            <div className="sm:col-span-2 lg:col-span-3">
                                <Toggle label="Active" description="Inactive factories cannot receive new departments, storage locations or assignments." checked={form.is_active} onChange={(v) => setForm((f) => ({ ...f, is_active: v }))} />
                            </div>
                        )}
                    </CardBody>
                </Card>
                <Card>
                    <CardHeader title="Address" />
                    <CardBody className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <TextInput label="Address line 1" value={form.address_line_1} onChange={set('address_line_1')} error={errors.address_line_1} wrapperClassName="sm:col-span-2" />
                        <TextInput label="Address line 2" value={form.address_line_2} onChange={set('address_line_2')} error={errors.address_line_2} wrapperClassName="sm:col-span-2" />
                        <TextInput label="City" value={form.city} onChange={set('city')} error={errors.city} />
                        <TextInput label="State" value={form.state} onChange={set('state')} error={errors.state} />
                        <TextInput label="Postal code" value={form.postal_code} onChange={set('postal_code')} error={errors.postal_code} inputMode="numeric" />
                        <TextInput label="Country" value={form.country} onChange={set('country')} error={errors.country} />
                    </CardBody>
                </Card>
                <Card>
                    <CardHeader title="Contact" />
                    <CardBody className="grid grid-cols-1 gap-5 sm:grid-cols-3">
                        <TextInput label="Contact person" value={form.contact_person} onChange={set('contact_person')} error={errors.contact_person} />
                        <TextInput label="Contact number" type="tel" value={form.contact_number} onChange={set('contact_number')} error={errors.contact_number} />
                        <TextInput label="Email" type="email" value={form.email} onChange={set('email')} error={errors.email} />
                    </CardBody>
                </Card>
            </fieldset>
            <div className="mt-6 flex justify-end gap-2">
                <ButtonLink to={isEdit ? `/factory-setup/factories/${id}` : '/factory-setup/factories'} variant="secondary">Cancel</ButtonLink>
                <Button type="submit" loading={saving}>{isEdit ? 'Save changes' : 'Create factory'}</Button>
            </div>
        </form>
    );
}
