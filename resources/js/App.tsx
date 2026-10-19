import { Route, Routes } from 'react-router-dom';
import { GuestOnly, RequireAuth, RequirePermission } from '@/auth/ProtectedRoute';
import { AppLayout } from '@/layout/AppLayout';
import LoginPage from '@/pages/auth/LoginPage';
import ForgotPasswordPage from '@/pages/auth/ForgotPasswordPage';
import ResetPasswordPage from '@/pages/auth/ResetPasswordPage';
import DashboardPage from '@/pages/DashboardPage';
import ProfilePage from '@/pages/profile/ProfilePage';
import ChangePasswordPage from '@/pages/profile/ChangePasswordPage';
import CompanySettingsPage from '@/pages/company/CompanySettingsPage';
import UserListPage from '@/pages/users/UserListPage';
import UserFormPage from '@/pages/users/UserFormPage';
import UserDetailsPage from '@/pages/users/UserDetailsPage';
import RoleListPage from '@/pages/roles/RoleListPage';
import RoleFormPage from '@/pages/roles/RoleFormPage';
import RoleDetailsPage from '@/pages/roles/RoleDetailsPage';
import RolePermissionsPage from '@/pages/roles/RolePermissionsPage';
import ActivityLogPage from '@/pages/activity/ActivityLogPage';
import NotFoundPage from '@/pages/NotFoundPage';

export default function App() {
    return (
        <Routes>
            <Route element={<GuestOnly />}>
                <Route path="/login" element={<LoginPage />} />
                <Route path="/forgot-password" element={<ForgotPasswordPage />} />
            </Route>
            {/* Reachable signed in or out (e.g. invitation links). */}
            <Route path="/reset-password" element={<ResetPasswordPage />} />

            <Route element={<RequireAuth />}>
                <Route element={<AppLayout />}>
                    <Route index element={<DashboardPage />} />
                    <Route path="profile" element={<ProfilePage />} />
                    <Route path="profile/password" element={<ChangePasswordPage />} />

                    <Route path="settings">
                        <Route path="company" element={<RequirePermission permission="company.view"><CompanySettingsPage /></RequirePermission>} />

                        <Route path="users">
                            <Route index element={<RequirePermission permission="users.view"><UserListPage /></RequirePermission>} />
                            <Route path="new" element={<RequirePermission permission="users.create"><UserFormPage /></RequirePermission>} />
                            <Route path=":id" element={<RequirePermission permission="users.view"><UserDetailsPage /></RequirePermission>} />
                            <Route path=":id/edit" element={<RequirePermission permission="users.update"><UserFormPage /></RequirePermission>} />
                        </Route>

                        <Route path="roles">
                            <Route index element={<RequirePermission permission="roles.view"><RoleListPage /></RequirePermission>} />
                            <Route path="new" element={<RequirePermission permission="roles.create"><RoleFormPage /></RequirePermission>} />
                            <Route path=":id" element={<RequirePermission permission="roles.view"><RoleDetailsPage /></RequirePermission>} />
                            <Route path=":id/edit" element={<RequirePermission permission="roles.update"><RoleFormPage /></RequirePermission>} />
                            <Route path=":id/permissions" element={<RequirePermission permission="roles.view"><RolePermissionsPage /></RequirePermission>} />
                        </Route>

                        <Route path="activity-logs" element={<RequirePermission permission="activity-logs.view"><ActivityLogPage /></RequirePermission>} />
                    </Route>

                    <Route path="*" element={<NotFoundPage />} />
                </Route>
            </Route>
        </Routes>
    );
}
