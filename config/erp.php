<?php

/*
|--------------------------------------------------------------------------
| ERP Module Configuration
|--------------------------------------------------------------------------
|
| Central registry for the permission catalogue and default roles. Every
| permission in the system is generated from the "modules" list below
| (format: module.action), so adding a future ERP module only requires
| registering it here and running `php artisan erp:sync-permissions`.
|
*/

return [

    /*
    | Standard matrix columns, in display order. Modules may also declare
    | extra module-specific actions (e.g. users.activate); those render in
    | the matrix's "Other" column.
    */
    'standard_actions' => ['view', 'create', 'update', 'delete', 'approve', 'export'],

    'modules' => [
        // ---- Module 1: System Settings & User Management -------------
        'dashboard' => [
            'label' => 'Dashboard',
            'group' => 'System',
            'actions' => ['view'],
        ],
        'company' => [
            'label' => 'Company Settings',
            'group' => 'System',
            'actions' => ['view', 'update'],
        ],
        'users' => [
            'label' => 'Users',
            'group' => 'System',
            'actions' => ['view', 'create', 'update', 'activate', 'deactivate', 'reset-password', 'delete'],
        ],
        'roles' => [
            'label' => 'Roles',
            'group' => 'System',
            'actions' => ['view', 'create', 'update', 'delete', 'assign-permissions'],
        ],
        'permissions' => [
            'label' => 'Permissions',
            'group' => 'System',
            'actions' => ['view'],
        ],
        'activity-logs' => [
            'label' => 'Activity Logs',
            'group' => 'System',
            'actions' => ['view'],
        ],

        // ---- Module 2: Factory & Department Management --------------
        'factories' => [
            'label' => 'Factories',
            'group' => 'Factory Setup',
            // access-all: data scope covering every factory; without it a
            // user only sees factories they are assigned to.
            'actions' => ['view', 'create', 'update', 'deactivate', 'access-all'],
        ],
        'departments' => [
            'label' => 'Departments',
            'group' => 'Factory Setup',
            'actions' => ['view', 'create', 'update', 'deactivate', 'delete', 'assign-users', 'assign-managers'],
        ],
        'warehouses' => [
            'label' => 'Warehouses',
            'group' => 'Factory Setup',
            'actions' => ['view', 'create', 'update', 'deactivate'],
            // Warehouses are internal (one storage area per factory) and not managed in the UI.
            'hidden' => true,
        ],
        'locations' => [
            'label' => 'Storage Locations',
            'group' => 'Factory Setup',
            'actions' => ['view', 'create', 'update', 'deactivate', 'delete'],
        ],
        'factory-structure' => [
            'label' => 'Factory Structure',
            'group' => 'Factory Setup',
            'actions' => ['view', 'export'],
        ],
        'structure-types' => [
            'label' => 'Department & Warehouse Types',
            'group' => 'Factory Setup',
            'actions' => ['view', 'create', 'update'],
        ],

        // ---- Stock management ----------------------------------------------
        'stock-overview' => ['label' => 'Stock Overview', 'group' => 'Inventory', 'actions' => ['view', 'export']],
        'opening-stock' => ['label' => 'Opening Stock', 'group' => 'Inventory', 'actions' => ['view', 'create', 'update', 'post', 'reverse', 'edit-posted', 'delete']],
        'skus' => ['label' => 'SKU Master', 'group' => 'Inventory', 'actions' => ['view', 'create', 'update', 'deactivate', 'delete', 'import', 'export']],
        'vendors' => ['label' => 'Vendor Master', 'group' => 'Inventory', 'actions' => ['view', 'create', 'update', 'deactivate', 'delete', 'import', 'export']],
        'vendor-skus' => ['label' => 'Vendor-SKU Mapping', 'group' => 'Inventory', 'actions' => ['view', 'create', 'update', 'delete', 'import']],
        'inward' => ['label' => 'Inward Material', 'group' => 'Inventory', 'actions' => ['view', 'create', 'update', 'post', 'reverse', 'edit-posted', 'delete', 'export', 'override-vendor-check']],
        'outward' => ['label' => 'Outward Material', 'group' => 'Inventory', 'actions' => ['view', 'create', 'update', 'post', 'reverse', 'edit-posted', 'delete', 'export']],

        // ---- Reserved namespaces for future modules (no functionality yet)
        'orders' => [
            'label' => 'Order Sheet',
            'group' => 'Operations',
            'reserved' => true,
            'actions' => ['view', 'create', 'update', 'delete', 'approve', 'export'],
        ],
        'production-planning' => [
            'label' => 'Production Planning',
            'group' => 'Production',
            'reserved' => true,
            'actions' => ['view', 'create', 'update', 'delete', 'approve', 'export'],
        ],
        'production-tracking' => [
            'label' => 'Production Tracking',
            'group' => 'Production',
            'reserved' => true,
            'actions' => ['view', 'create', 'update', 'export'],
        ],
        'quality-control' => [
            'label' => 'Quality Control',
            'group' => 'Production',
            'reserved' => true,
            'actions' => ['view', 'create', 'update', 'approve', 'export'],
        ],
        'dispatch' => [
            'label' => 'Dispatch',
            'group' => 'Operations',
            'reserved' => true,
            'actions' => ['view', 'create', 'update', 'approve', 'export'],
        ],
        'reports' => [
            'label' => 'Reports',
            'group' => 'Analytics',
            'reserved' => true,
            'actions' => ['view', 'export'],
        ],
    ],

    /*
    | The primary administrator role. It always holds every permission, can
    | never be deleted or deactivated, and at least one active user must
    | hold it. This is used only for those safeguards — authorization
    | everywhere else is permission based.
    */
    'primary_admin_role' => 'Admin',

    /*
    | Core roles created by the seeder. Core roles cannot be deleted through
    | the UI/API. Permissions listed here are applied only when the role is
    | first created, so later changes made by administrators are preserved.
    | "module.*" grants every action registered for that module.
    */
    'default_roles' => [
        'Admin' => [
            'description' => 'Full system administration.',
            'permissions' => ['*'],
        ],
        'Factory Manager' => [
            'description' => 'Factory operations and production management.',
            'permissions' => [
                'dashboard.view', 'company.view',
                'production-planning.*', 'production-tracking.*',
                'orders.view', 'inventory.view', 'quality-control.view', 'dispatch.view',
                'reports.view', 'reports.export',
            ],
        ],
        'Store Keeper' => [
            'description' => 'Inventory, warehouse and material movement.',
            'permissions' => [
                'dashboard.view',
                'inventory.view', 'inventory.create', 'inventory.update', 'inventory.export',
                'dispatch.view', 'orders.view',
            ],
        ],
        'Accountant' => [
            'description' => 'Authorized accounting and financial information.',
            'permissions' => [
                'dashboard.view', 'company.view',
                'orders.view', 'dispatch.view',
                'reports.view', 'reports.export',
            ],
        ],
        'QC' => [
            'description' => 'Quality inspection and quality approval.',
            'permissions' => [
                'dashboard.view',
                'quality-control.*', 'production-tracking.view',
            ],
        ],
    ],

    /*
    | Module 2: factory & physical structure.
    */
    'structure' => [
        'factory_types' => [
            'manufacturing' => 'Manufacturing',
            'warehouse' => 'Warehouse',
            'other' => 'Other',
        ],

        'location_types' => [
            'zone' => 'Zone',
            'room' => 'Room',
            'rack' => 'Rack',
            'shelf' => 'Shelf',
            'bin' => 'Bin',
            'floor_area' => 'Floor Area',
            'other' => 'Other',
        ],

        // Maximum nesting depth of storage locations (1 = top level only).
        'max_location_depth' => (int) env('ERP_MAX_LOCATION_DEPTH', 6),

        /*
        | Tables that will reference structure records once future modules
        | exist, as [table => foreign key column]. A record referenced by any
        | of them cannot be permanently deleted. Inventory registers e.g.
        | 'stock_movements' => 'location_id' here.
        */
        'location_references' => [],

        // Suggested types seeded once; administrators can add more.
        'suggested_types' => [
            'department' => [
                'Stores', 'Production', 'Casting', 'Machining', 'Finishing', 'Polishing', 'Coating',
                'Assembly', 'Quality Control', 'Packing', 'Dispatch', 'Maintenance', 'Administration',
            ],
            'warehouse' => [
                'Raw Material Store', 'Work-in-Progress Store', 'Finished Goods Store', 'QC Hold Area',
                'Rework Area', 'Rejected Material Area', 'Packing Area', 'Dispatch Staging Area', 'General Store',
            ],
        ],
    ],

    'company' => [
        'default_name' => env('ERP_COMPANY_NAME', 'My Company'),
        'default_code' => env('ERP_COMPANY_CODE', 'MAIN'),
        'logo_disk' => env('ERP_LOGO_DISK', 'public'),
        'logo_max_kb' => (int) env('ERP_LOGO_MAX_KB', 2048),
    ],

    'activity_logs' => [
        // Days to keep activity logs. Null keeps them indefinitely.
        // Pruned daily by `php artisan model:prune` (see routes/console.php).
        'retention_days' => env('ACTIVITY_LOG_RETENTION_DAYS') !== null && env('ACTIVITY_LOG_RETENTION_DAYS') !== ''
            ? (int) env('ACTIVITY_LOG_RETENTION_DAYS')
            : null,
    ],

    // Where the SPA lives; used to build password reset / invitation links.
    'frontend_url' => env('FRONTEND_URL', env('APP_URL', 'http://localhost')),
];
