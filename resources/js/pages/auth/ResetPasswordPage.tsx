import { useState, type FormEvent } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { AuthLayout } from '@/layout/AppLayout';
import { Button } from '@/components/ui/Button';
import { TextInput } from '@/components/ui/Form';
import { Alert } from '@/components/ui/Feedback';
import { PasswordRequirements, passwordMeetsRules } from '@/components/PasswordRequirements';
import { api, ensureCsrfCookie, errorMessage, fieldErrors } from '@/lib/api';

export default function ResetPasswordPage() {
    const [params] = useSearchParams();
    const token = params.get('token') ?? '';
    const email = params.get('email') ?? '';
    const isInvite = params.get('invite') === '1';

    const [password, setPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [done, setDone] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const title = isInvite ? 'Set your password' : 'Reset password';

    if (!token || !email) {
        return (
            <AuthLayout title={title}>
                <Alert tone="danger">This link is incomplete or invalid. Request a new password reset link.</Alert>
                <Link to="/forgot-password" className="mt-6 inline-block text-sm font-medium text-brand-600">Request a new link</Link>
            </AuthLayout>
        );
    }

    const onSubmit = async (event: FormEvent) => {
        event.preventDefault();
        setSubmitting(true);
        setError(null);
        setErrors({});
        try {
            await ensureCsrfCookie();
            const { data } = await api.post<{ message: string }>('/auth/reset-password', {
                token,
                email,
                password,
                password_confirmation: confirmation,
            });
            setDone(data.message);
        } catch (err) {
            const fields = fieldErrors(err);
            if (fields.email) setError(`${fields.email} Request a new link if it has expired.`);
            else if (Object.keys(fields).length) setErrors(fields);
            else setError(errorMessage(err));
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <AuthLayout title={title} subtitle={`For ${email}`}>
            {done ? (
                <div className="space-y-6">
                    <Alert tone="success">{done}</Alert>
                    <Link to="/login" className="text-sm font-medium text-brand-600 hover:text-brand-700">Go to sign in →</Link>
                </div>
            ) : (
                <form onSubmit={onSubmit} className="space-y-5" noValidate>
                    {error && <Alert tone="danger">{error}</Alert>}
                    <TextInput label="New password" type="password" autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} error={errors.password} required autoFocus />
                    <PasswordRequirements value={password} />
                    <TextInput
                        label="Confirm new password"
                        type="password"
                        autoComplete="new-password"
                        value={confirmation}
                        onChange={(e) => setConfirmation(e.target.value)}
                        error={confirmation && confirmation !== password ? 'Passwords do not match.' : undefined}
                        required
                    />
                    <Button type="submit" className="w-full" loading={submitting} disabled={!passwordMeetsRules(password) || password !== confirmation}>
                        {isInvite ? 'Set password' : 'Reset password'}
                    </Button>
                </form>
            )}
        </AuthLayout>
    );
}
