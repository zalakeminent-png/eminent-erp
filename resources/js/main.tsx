import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import { AuthProvider } from '@/auth/AuthContext';
import { ToastProvider } from '@/components/ui/Toast';
import App from '@/App';

createRoot(document.getElementById('root')!).render(
    <StrictMode>
        <BrowserRouter future={{ v7_startTransition: true, v7_relativeSplatPath: true }}>
            <ToastProvider>
                <AuthProvider>
                    <App />
                </AuthProvider>
            </ToastProvider>
        </BrowserRouter>
    </StrictMode>,
);
