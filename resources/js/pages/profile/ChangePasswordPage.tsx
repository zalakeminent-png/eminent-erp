import { useState, type FormEvent } from 'react';
import { Card, CardBody } from '@/components/ui/Card';
import { Alert, PageHeader } from '@/components/ui/Feedback';
import { TextInput } from '@/components/ui/Form';
import { Button } from '@/components/ui/Button';
import { useToast } from '@/components/ui/Toast';
import { PasswordRequirements, passwordMeetsRules } from '@/components/PasswordRequirements';
import { api, errorMessage, fieldErrors } from '@/lib/api';

const empty = { current_password: '', password: '', password_confirmation: '' };

export default function ChangePasswordPage() {
    const toast = useToast();
    const [form, setForm] = useState(empty);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [done, setDone] = useState(false);

    const onSubmit = async (event: FormEvent) => {
        event.preventDefault();
        setSaving(true);
        setErrors({});
        try {
            await api.put('/auth/change-password', form);
            setForm(empty);
            setDone(true);
            toast.success('Password changed.');
        } catch (err) {
            setErrors(fieldErrors(err));
            if (!Object.keys(fieldErrors(err)).length) toast.error(errorMessage(err));
        } finally {
            setSaving(false);
        }
    };

    const mismatch = form.password_confirmation !== '' && form.password !== form.password_confirmation;

    return (
        <>
            <PageHeader title="Change Password" description="Changing your password signs you out of all other devices." />
            <Card className="max-w-xl">
                <form onSubmit={onSubmit}>
                    <CardBody className="space-y-5">
                        {done && <Alert tone="success">Your password has been changed. Other sessions have been signed out.</Alert>}
                        <TextInput label="Current password" type="password" autoComplete="current-password" value={form.current_password} onChange={(e) => setForm({ ...form, current_password: e.target.value })} error={errors.current_password} required />
                        <TextInput label="New password" type="password" autoComplete="new-password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} error={errors.password} required />
                        <PasswordRequirements value={form.password} />
                        <TextInput label="Confirm new password" type="password" autoComplete="new-password" value={form.password_confirmation} onChange={(e) => setForm({ ...form, password_confirmation: e.target.value })} error={mismatch ? 'Passwords do not match.' : undefined} required />
                    </CardBody>
                    <div className="flex justify-end border-t border-slate-200 px-5 py-3">
                        <Button type="submit" loading={saving} disabled={!form.current_password || !passwordMeetsRules(form.password) || mismatch || !form.password_confirmation}>
                            Update password
                        </Button>
                    </div>
                </form>
            </Card>
        </>
    );
}
