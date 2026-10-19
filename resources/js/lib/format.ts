const dateTime = new Intl.DateTimeFormat('en-IN', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
});

const dateOnly = new Intl.DateTimeFormat('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });

export function formatDateTime(value: string | null | undefined, empty = '—'): string {
    return value ? dateTime.format(new Date(value)) : empty;
}

export function formatDate(value: string | null | undefined, empty = '—'): string {
    return value ? dateOnly.format(new Date(value)) : empty;
}

export const MONTHS = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
];

/** "users.reset-password" -> "Reset password" */
export function humanize(value: string): string {
    const text = value.replace(/[._-]+/g, ' ').trim();
    return text.charAt(0).toUpperCase() + text.slice(1);
}

export function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');
}

export function cx(...classes: Array<string | false | null | undefined>): string {
    return classes.filter(Boolean).join(' ');
}
