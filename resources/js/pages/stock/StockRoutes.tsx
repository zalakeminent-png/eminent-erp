import { Route } from 'react-router-dom';
import { RequirePermission } from '@/auth/ProtectedRoute';
import StockOverviewPage from './StockOverviewPage';
import SkuMasterPage from './SkuMasterPage';
import { VendorDetailsPage, VendorListPage, VendorMappingPage } from './VendorMasterPages';
import { DOC_CONFIGS, StockDocumentDetailsPage, StockDocumentFormPage, StockDocumentListPage, type DocConfig } from './StockDocumentPages';

function documentRoutes(config: DocConfig) {
    const path = config.base.replace('/inventory/', '');
    const p = config.module;
    return (
        <Route key={config.type} path={path}>
            <Route index element={<RequirePermission permission={`${p}.view`}><StockDocumentListPage key={config.type} config={config} /></RequirePermission>} />
            <Route path="new" element={<RequirePermission permission={`${p}.create`}><StockDocumentFormPage key={`${config.type}-new`} config={config} /></RequirePermission>} />
            <Route path=":id" element={<RequirePermission permission={`${p}.view`}><StockDocumentDetailsPage key={`${config.type}-view`} config={config} /></RequirePermission>} />
            <Route path=":id/edit" element={<RequirePermission permission={`${p}.update`}><StockDocumentFormPage key={`${config.type}-edit`} config={config} /></RequirePermission>} />
        </Route>
    );
}

/** Stock management routes, mounted under /inventory inside the authenticated layout. */
export function stockRoutes() {
    return (
        <Route path="inventory">
            <Route path="stock" element={<RequirePermission permission="stock-overview.view"><StockOverviewPage /></RequirePermission>} />
            <Route path="skus" element={<RequirePermission permission="skus.view"><SkuMasterPage /></RequirePermission>} />
            <Route path="vendors" element={<RequirePermission permission="vendors.view"><VendorListPage /></RequirePermission>} />
            <Route path="vendors/mapping" element={<RequirePermission permission="vendor-skus.view"><VendorMappingPage /></RequirePermission>} />
            <Route path="vendors/:id" element={<RequirePermission permission="vendors.view"><VendorDetailsPage /></RequirePermission>} />
            {Object.values(DOC_CONFIGS).map(documentRoutes)}
        </Route>
    );
}
