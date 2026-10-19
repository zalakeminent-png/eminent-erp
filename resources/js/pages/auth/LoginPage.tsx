import { useState, type FormEvent } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '@/auth/AuthContext';
import { AuthLayout } from '@/layout/AppLayout';
import { Button } from '@/components/ui/Button';
import { Checkbox, TextInput } from '@/components/ui/Form';
import { Alert } from '@/components/ui/Feedback';
import { errorMessage, fieldErrors } from '@/lib/api';

export default function LoginPage() {
    const { login, notice } = useAuth();
    const navigate = useNavigate();
    const location = useLocation();
    const from = (location.state as { from?: string } | null)?.from ?? '/';

    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [remember, setRemember] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const onSubmit = async (event: FormEvent) => {
        event.preventDefault();
        setSubmitting(true);
        setError(null);
        setErrors({});
        try {
            await login(email.trim(), password, remember);
            navigate(from, { replace: true });
        } catch (err) {
            const fields = fieldErrors(err);
            // Credential errors are shown as a single banner, not per-field.
            if (fields.email && !fields.password) setError(fields.email);
            else if (Object.keys(fields).length) setErrors(fields);
            else setError(errorMessage(err));
            setPassword('');
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <AuthLayout title="Sign in" subtitle="Use your work email and password.">
            <form onSubmit={onSubmit} className="space-y-5" noValidate>
                {notice && !error && <Alert tone="warning">{notice}</Alert>}
                {error && <Alert tone="danger">{error}</Alert>}
                <TextInput
                    label="Email"
                    type="email"
                    autoComplete="username"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    error={errors.email}
                    required
                    autoFocus
                />
                <TextInput
                    label="Password"
                    type="password"
                    autoComplete="current-password"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    error={errors.password}
                    required
                />
                <div className="flex items-center justify-between">
                    <Checkbox label="Keep me signed in" checked={remember} onChange={(e) => setRemember(e.target.checked)} />
                    <Link to="/forgot-password" className="text-sm font-medium text-brand-600 hover:text-brand-700">
                        Forgot password?
                    </Link>
                </div>
                <Button type="submit" className="w-full" loading={submitting} disabled={!email || !password}>
                    Sign in
                </Button>
                <p className="text-center text-xs text-slate-500">Accounts are created by your administrator.</p>
            </form>
        </AuthLayout>
    );
}
