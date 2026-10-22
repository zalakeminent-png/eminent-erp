import { api } from './api';

/** Triggers a browser download for an authenticated GET (cookie session). */
export async function downloadFile(url: string, params?: Record<string, unknown>, fallbackName = 'download.csv'): Promise<void> {
    const cleanParams = Object.fromEntries(Object.entries(params ?? {}).filter(([, v]) => v !== '' && v !== null && v !== undefined));
    const response = await api.get(url, { params: cleanParams, responseType: 'blob' });
    const disposition = String(response.headers['content-disposition'] ?? '');
    const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition);
    const href = URL.createObjectURL(response.data as Blob);
    const a = document.createElement('a');
    a.href = href;
    a.download = match ? decodeURIComponent(match[1]) : fallbackName;
    a.click();
    URL.revokeObjectURL(href);
}
