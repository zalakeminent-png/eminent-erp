import { useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { AuthLayout } from '@/layout/AppLayout';
import { Button } from '@/components/ui/Button';
import { TextInput } from '@/components/ui/Form';
import { Alert } from '@/components/ui/Feedback';
import { api, ensureCsrfCookie, errorMessage, fieldErrors } from '@/lib/api';

export default function ForgotPasswordPage() {
    const [email, setEmail] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [sent, setSent] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    const onSubmit = async (event: FormEvent) => {
        event.preventDefault();
        setSubmitting(true);
        setError(null);
        try {
            await ensureCsrfCookie();
            const { data } = await api.post<{ message: string }>('/auth/forgot-password', { email: email.trim() });
            setSent(data.message);
        } catch (err) {
            setError(fieldErrors(err).email ?? errorMessage(err));
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <AuthLayout title="Forgot password" subtitle="We'll email you a link to choose a new password.">
            {sent ? (
                <div className="space-y-6">
                    <Alert tone="success">{sent}</Alert>
                    <Link to="/login" className="text-sm font-medium text-brand-600 hover:text-brand-700">← Back to sign in</Link>
                </div>
            ) : (
                <form onSubmit={onSubmit} className="space-y-5" noValidate>
                    {error && <Alert tone="danger">{error}</Alert>}
                    <TextInput label="Email" type="email" autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)} required autoFocus />
                    <Button type="submit" className="w-full" loading={submitting} disabled={!email}>
                        Send reset link
                    </Button>
                    <p className="text-center">
                        <Link to="/login" className="text-sm font-medium text-brand-600 hover:text-brand-700">Back to sign in</Link>
                    </p>
                </form>
            )}
        </AuthLayout>
    );
}
