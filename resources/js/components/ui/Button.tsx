import { forwardRef, type ButtonHTMLAttributes, type ReactNode } from 'react';
import { Link, type LinkProps } from 'react-router-dom';
import { cx } from '@/lib/format';
import { Spinner } from './Spinner';

type Variant = 'primary' | 'secondary' | 'danger' | 'ghost';
type Size = 'sm' | 'md';

const base =
    'inline-flex items-center justify-center gap-2 rounded-md font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-60 whitespace-nowrap';

const variants: Record<Variant, string> = {
    primary: 'bg-brand-600 text-white shadow-sm hover:bg-brand-700',
    secondary: 'border border-slate-300 bg-white text-slate-700 shadow-sm hover:bg-slate-50',
    danger: 'bg-red-600 text-white shadow-sm hover:bg-red-700',
    ghost: 'text-slate-600 hover:bg-slate-100 hover:text-slate-900',
};

const sizes: Record<Size, string> = {
    sm: 'h-8 px-3 text-sm',
    md: 'h-10 px-4 text-sm',
};

export function buttonClasses(variant: Variant = 'primary', size: Size = 'md', className?: string) {
    return cx(base, variants[variant], sizes[size], className);
}

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
    variant?: Variant;
    size?: Size;
    loading?: boolean;
    icon?: ReactNode;
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
    { variant = 'primary', size = 'md', loading = false, icon, className, children, disabled, type = 'button', ...props },
    ref,
) {
    return (
        <button ref={ref} type={type} className={buttonClasses(variant, size, className)} disabled={disabled || loading} {...props}>
            {loading ? <Spinner className="h-4 w-4" /> : icon}
            {children}
        </button>
    );
});

export function ButtonLink({ variant = 'primary', size = 'md', icon, className, children, ...props }: LinkProps & { variant?: Variant; size?: Size; icon?: ReactNode }) {
    return (
        <Link className={buttonClasses(variant, size, className)} {...props}>
            {icon}
            {children}
        </Link>
    );
}
