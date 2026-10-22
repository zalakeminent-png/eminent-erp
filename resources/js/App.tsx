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
import { stockRoutes } from '@/pages/stock/StockRoutes';
import FactoryStructurePage from '@/pages/structure/FactoryStructurePage';
import StructureTypesPage from '@/pages/structure/StructureTypesPage';
import FactoryListPage from '@/pages/structure/factories/FactoryListPage';
import FactoryFormPage from '@/pages/structure/factories/FactoryFormPage';
import FactoryDetailsPage from '@/pages/structure/factories/FactoryDetailsPage';
import DepartmentListPage from '@/pages/structure/departments/DepartmentListPage';
import DepartmentFormPage from '@/pages/structure/departments/DepartmentFormPage';
import DepartmentDetailsPage from '@/pages/structure/departments/DepartmentDetailsPage';
import DepartmentEmployeesPage from '@/pages/structure/departments/DepartmentEmployeesPage';
import DepartmentLeadersPage from '@/pages/structure/departments/DepartmentLeadersPage';
import LocationListPage from '@/pages/structure/locations/LocationListPage';
import LocationTreePage from '@/pages/structure/locations/LocationTreePage';
import LocationFormPage from '@/pages/structure/locations/LocationFormPage';
import LocationDetailsPage from '@/pages/structure/locations/LocationDetailsPage';

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

                    {stockRoutes()}

                    <Route path="factory-setup">
                        <Route path="structure" element={<RequirePermission permission="factory-structure.view"><FactoryStructurePage /></RequirePermission>} />
                        <Route path="types" element={<RequirePermission permission="structure-types.view"><StructureTypesPage /></RequirePermission>} />

                        <Route path="factories">
                            <Route index element={<RequirePermission permission="factories.view"><FactoryListPage /></RequirePermission>} />
                            <Route path="new" element={<RequirePermission permission="factories.create"><FactoryFormPage /></RequirePermission>} />
                            <Route path=":id" element={<RequirePermission permission="factories.view"><FactoryDetailsPage /></RequirePermission>} />
                            <Route path=":id/edit" element={<RequirePermission permission="factories.update"><FactoryFormPage /></RequirePermission>} />
                        </Route>

                        <Route path="departments">
                            <Route index element={<RequirePermission permission="departments.view"><DepartmentListPage /></RequirePermission>} />
                            <Route path="new" element={<RequirePermission permission="departments.create"><DepartmentFormPage /></RequirePermission>} />
                            <Route path=":id" element={<RequirePermission permission="departments.view"><DepartmentDetailsPage /></RequirePermission>} />
                            <Route path=":id/edit" element={<RequirePermission permission="departments.update"><DepartmentFormPage /></RequirePermission>} />
                            <Route path=":id/employees" element={<RequirePermission permission="departments.view"><DepartmentEmployeesPage /></RequirePermission>} />
                            <Route path=":id/leaders" element={<RequirePermission permission="departments.assign-managers"><DepartmentLeadersPage /></RequirePermission>} />
                        </Route>

                        <Route path="locations">
                            <Route index element={<RequirePermission permission="locations.view"><LocationListPage /></RequirePermission>} />
                            <Route path="tree" element={<RequirePermission permission="locations.view"><LocationTreePage /></RequirePermission>} />
                            <Route path="new" element={<RequirePermission permission="locations.create"><LocationFormPage /></RequirePermission>} />
                            <Route path=":id" element={<RequirePermission permission="locations.view"><LocationDetailsPage /></RequirePermission>} />
                            <Route path=":id/edit" element={<RequirePermission permission="locations.update"><LocationFormPage /></RequirePermission>} />
                        </Route>
                    </Route>

                    <Route path="*" element={<NotFoundPage />} />
                </Route>
            </Route>
        </Routes>
    );
}
