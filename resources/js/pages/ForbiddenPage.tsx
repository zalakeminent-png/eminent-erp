import { ShieldAlert } from 'lucide-react';
import { ButtonLink } from '@/components/ui/Button';

export default function ForbiddenPage() {
    return (
        <div className="flex flex-col items-center justify-center py-24 text-center">
            <div className="mb-4 rounded-full bg-amber-50 p-4 text-amber-600">
                <ShieldAlert className="h-8 w-8" aria-hidden="true" />
            </div>
            <h1 className="text-xl font-semibold text-slate-900">Access denied</h1>
            <p className="mt-2 max-w-sm text-sm text-slate-500">You do not have permission to view this page. Contact your administrator if you need access.</p>
            <ButtonLink to="/" variant="secondary" className="mt-6">Go to dashboard</ButtonLink>
        </div>
    );
}
