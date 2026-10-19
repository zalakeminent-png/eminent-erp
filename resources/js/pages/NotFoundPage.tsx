import { ButtonLink } from '@/components/ui/Button';

export default function NotFoundPage({ message = 'The page you are looking for does not exist.' }: { message?: string }) {
    return (
        <div className="flex flex-col items-center justify-center py-24 text-center">
            <p className="text-sm font-semibold text-brand-600">404</p>
            <h1 className="mt-2 text-xl font-semibold text-slate-900">Not found</h1>
            <p className="mt-2 max-w-sm text-sm text-slate-500">{message}</p>
            <ButtonLink to="/" variant="secondary" className="mt-6">Go to dashboard</ButtonLink>
        </div>
    );
}
