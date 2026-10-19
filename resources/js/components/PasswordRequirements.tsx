import { Check, Circle } from 'lucide-react';
import { cx } from '@/lib/format';

/** Mirrors the server-side Password::defaults() rule (the server is authoritative). */
export const passwordRules = [
    { label: 'At least 10 characters', test: (v: string) => v.length >= 10 },
    { label: 'Upper and lower case letters', test: (v: string) => /[a-z]/.test(v) && /[A-Z]/.test(v) },
    { label: 'At least one number', test: (v: string) => /\d/.test(v) },
    { label: 'At least one symbol', test: (v: string) => /[^A-Za-z0-9]/.test(v) },
];

export function passwordMeetsRules(value: string): boolean {
    return passwordRules.every((rule) => rule.test(value));
}

export function PasswordRequirements({ value }: { value: string }) {
    return (
        <ul className="grid grid-cols-1 gap-1 text-xs sm:grid-cols-2">
            {passwordRules.map((rule) => {
                const ok = rule.test(value);
                return (
                    <li key={rule.label} className={cx('flex items-center gap-1.5', ok ? 'text-emerald-700' : 'text-slate-500')}>
                        {ok ? <Check className="h-3.5 w-3.5" aria-hidden="true" /> : <Circle className="h-3 w-3" aria-hidden="true" />}
                        {rule.label}
                    </li>
                );
            })}
        </ul>
    );
}
