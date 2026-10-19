import { forwardRef, useId, type InputHTMLAttributes, type ReactNode, type SelectHTMLAttributes, type TextareaHTMLAttributes } from 'react';
import { cx } from '@/lib/format';

const control =
    'block w-full rounded-md border bg-white px-3 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:outline-none focus:ring-2 disabled:bg-slate-50 disabled:text-slate-500';

function controlClasses(error?: string) {
    return cx(control, error ? 'border-red-400 focus:border-red-500 focus:ring-red-200' : 'border-slate-300 focus:border-brand-500 focus:ring-brand-100');
}

interface FieldProps {
    label: string;
    error?: string;
    hint?: ReactNode;
    required?: boolean;
    className?: string;
    children: (id: string, describedBy: string | undefined) => ReactNode;
}

export function Field({ label, error, hint, required, className, children }: FieldProps) {
    const id = useId();
    const describedBy = error ? `${id}-error` : hint ? `${id}-hint` : undefined;
    return (
        <div className={className}>
            <label htmlFor={id} className="mb-1.5 block text-sm font-medium text-slate-700">
                {label}
                {required && <span className="ml-0.5 text-red-500" aria-hidden="true">*</span>}
            </label>
            {children(id, describedBy)}
            {error ? (
                <p id={`${id}-error`} className="mt-1.5 text-xs text-red-600">{error}</p>
            ) : hint ? (
                <p id={`${id}-hint`} className="mt-1.5 text-xs text-slate-500">{hint}</p>
            ) : null}
        </div>
    );
}

type InputProps = InputHTMLAttributes<HTMLInputElement> & { label: string; error?: string; hint?: ReactNode; wrapperClassName?: string };

export const TextInput = forwardRef<HTMLInputElement, InputProps>(function TextInput(
    { label, error, hint, required, wrapperClassName, className, ...props },
    ref,
) {
    return (
        <Field label={label} error={error} hint={hint} required={required} className={wrapperClassName}>
            {(id, describedBy) => (
                <input
                    ref={ref}
                    id={id}
                    required={required}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={describedBy}
                    className={cx(controlClasses(error), 'h-10', className)}
                    {...props}
                />
            )}
        </Field>
    );
});

type SelectProps = SelectHTMLAttributes<HTMLSelectElement> & { label: string; error?: string; hint?: ReactNode; wrapperClassName?: string };

export function SelectInput({ label, error, hint, required, wrapperClassName, className, children, ...props }: SelectProps) {
    return (
        <Field label={label} error={error} hint={hint} required={required} className={wrapperClassName}>
            {(id, describedBy) => (
                <select
                    id={id}
                    required={required}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={describedBy}
                    className={cx(controlClasses(error), 'h-10 pr-8', className)}
                    {...props}
                >
                    {children}
                </select>
            )}
        </Field>
    );
}

type TextareaProps = TextareaHTMLAttributes<HTMLTextAreaElement> & { label: string; error?: string; hint?: ReactNode; wrapperClassName?: string };

export function TextareaInput({ label, error, hint, required, wrapperClassName, className, ...props }: TextareaProps) {
    return (
        <Field label={label} error={error} hint={hint} required={required} className={wrapperClassName}>
            {(id, describedBy) => (
                <textarea
                    id={id}
                    required={required}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={describedBy}
                    className={cx(controlClasses(error), 'py-2', className)}
                    rows={3}
                    {...props}
                />
            )}
        </Field>
    );
}

export function Checkbox({ label, description, className, ...props }: InputHTMLAttributes<HTMLInputElement> & { label: ReactNode; description?: ReactNode }) {
    const id = useId();
    return (
        <div className={cx('flex items-start gap-3', className)}>
            <input id={id} type="checkbox" className="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 accent-brand-600 focus:ring-brand-500" {...props} />
            <label htmlFor={id} className="text-sm">
                <span className="font-medium text-slate-800">{label}</span>
                {description && <span className="block text-xs text-slate-500">{description}</span>}
            </label>
        </div>
    );
}

export function Toggle({ checked, onChange, label, description, disabled }: { checked: boolean; onChange: (value: boolean) => void; label: string; description?: string; disabled?: boolean }) {
    const id = useId();
    return (
        <div className="flex items-start justify-between gap-4">
            <div>
                <label htmlFor={id} className="text-sm font-medium text-slate-800">{label}</label>
                {description && <p className="text-xs text-slate-500">{description}</p>}
            </div>
            <button
                id={id}
                type="button"
                role="switch"
                aria-checked={checked}
                disabled={disabled}
                onClick={() => onChange(!checked)}
                className={cx(
                    'relative inline-flex h-6 w-11 shrink-0 rounded-full border-2 border-transparent transition-colors disabled:opacity-50',
                    checked ? 'bg-brand-600' : 'bg-slate-300',
                )}
            >
                <span className={cx('inline-block h-5 w-5 transform rounded-full bg-white shadow transition', checked ? 'translate-x-5' : 'translate-x-0')} />
            </button>
        </div>
    );
}
