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
            'actions' => ['view', 'create', 'update', 'deactivate', 'assign-users', 'assign-managers'],
        ],
        'warehouses' => [
            'label' => 'Warehouses',
            'group' => 'Factory Setup',
            'actions' => ['view', 'create', 'update', 'deactivate'],
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

        // ---- Module 3: Master Data Management -------------------------
        'master-data' => [
            'label' => 'Master Data (dashboard & import)',
            'group' => 'Master Data',
            'actions' => ['view', 'import'],
        ],
        'item-categories' => [
            'label' => 'Item Categories & Attributes',
            'group' => 'Master Data',
            'actions' => ['view', 'create', 'update', 'deactivate'],
        ],
        'items' => [
            'label' => 'Items / Products',
            'group' => 'Master Data',
            'actions' => ['view', 'create', 'update', 'deactivate', 'export'],
        ],
        'uoms' => [
            'label' => 'Units of Measurement',
            'group' => 'Master Data',
            'actions' => ['view', 'create', 'update'],
        ],
        'material-grades' => [
            'label' => 'Material Families & Grades',
            'group' => 'Master Data',
            'actions' => ['view', 'create', 'update'],
        ],
        'processes' => [
            'label' => 'Manufacturing Processes',
            'group' => 'Master Data',
            'actions' => ['view', 'create', 'update'],
        ],
        'machines' => [
            'label' => 'Machines & Workstations',
            'group' => 'Master Data',
            'actions' => ['view', 'create', 'update'],
        ],
        'boms' => [
            'label' => 'Bills of Materials',
            'group' => 'Master Data',
            'actions' => ['view', 'create', 'update', 'approve', 'export'],
        ],
        'routings' => [
            'label' => 'Product Routings',
            'group' => 'Master Data',
            'actions' => ['view', 'create', 'update', 'approve'],
        ],
        'qc-specifications' => [
            'label' => 'QC Parameters & Specifications',
            'group' => 'Master Data',
            'actions' => ['view', 'create', 'update', 'approve'],
        ],
        'business-partners' => [
            'label' => 'Customers & Suppliers',
            'group' => 'Master Data',
            'actions' => ['view', 'create', 'update', 'deactivate'],
        ],

        // ---- Reserved namespaces for future modules (no functionality yet)
        'inventory' => [
            'label' => 'Inventory',
            'group' => 'Operations',
            'reserved' => true,
            'actions' => ['view', 'create', 'update', 'delete', 'approve', 'export'],
        ],
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

    /*
    | Module 3: master data. Everything listed under "default_*" is seeded
    | once as ordinary, editable records — nothing here is hardcoded into
    | business logic.
    */
    'master_data' => [
        'item_types' => [
            'raw_material' => 'Raw Material',
            'semi_finished' => 'Semi-Finished Goods',
            'finished_good' => 'Finished Goods',
            'consumable' => 'Consumables',
            'packing_material' => 'Packing Materials',
            'tool' => 'Tools & Accessories',
            'scrap' => 'Scrap Material',
        ],

        // Item types that may own a BOM / routing (plus any item flagged is_manufactured).
        'manufactured_item_types' => ['semi_finished', 'finished_good'],

        'default_categories' => [
            ['code' => 'RM', 'name' => 'Raw Material', 'sku_prefix' => 'RM', 'item_type' => 'raw_material'],
            ['code' => 'SFG', 'name' => 'Semi-Finished Goods', 'sku_prefix' => 'SFG', 'item_type' => 'semi_finished'],
            ['code' => 'FG', 'name' => 'Finished Goods', 'sku_prefix' => 'FG', 'item_type' => 'finished_good'],
            ['code' => 'CON', 'name' => 'Consumables', 'sku_prefix' => 'CON', 'item_type' => 'consumable'],
            ['code' => 'PK', 'name' => 'Packing Materials', 'sku_prefix' => 'PK', 'item_type' => 'packing_material'],
            ['code' => 'TL', 'name' => 'Tools & Accessories', 'sku_prefix' => 'TL', 'item_type' => 'tool'],
            ['code' => 'SCR', 'name' => 'Scrap Material', 'sku_prefix' => 'SCR', 'item_type' => 'scrap'],
        ],

        /*
        | UOM dimensions. Universal conversions are allowed only between
        | units of the same *convertible* dimension. Count and packaging
        | units (PCS, BOX, SET) are never universally convertible — use
        | item-specific conversions instead.
        */
        'uom_dimensions' => [
            'count' => ['label' => 'Count', 'convertible' => false],
            'packaging' => ['label' => 'Packaging', 'convertible' => false],
            'mass' => ['label' => 'Mass / Weight', 'convertible' => true],
            'length' => ['label' => 'Length', 'convertible' => true],
            'area' => ['label' => 'Area', 'convertible' => true],
            'volume' => ['label' => 'Volume', 'convertible' => true],
            'time' => ['label' => 'Time', 'convertible' => true],
        ],

        'default_uoms' => [
            ['PCS', 'Pieces', 'count', 0],
            ['KG', 'Kilogram', 'mass', 3],
            ['GM', 'Gram', 'mass', 2],
            ['MTR', 'Meter', 'length', 3],
            ['MM', 'Millimeter', 'length', 2],
            ['CM', 'Centimeter', 'length', 2],
            ['SQFT', 'Square Feet', 'area', 3],
            ['SQM', 'Square Meter', 'area', 4],
            ['BOX', 'Box', 'packaging', 0],
            ['SET', 'Set', 'packaging', 0],
            ['LTR', 'Liter', 'volume', 3],
        ],

        // [from, to, factor]: 1 from = factor × to.
        'default_uom_conversions' => [
            ['KG', 'GM', '1000'],
            ['MTR', 'MM', '1000'],
            ['MTR', 'CM', '100'],
            ['SQM', 'SQFT', '10.7639104167'],
        ],

        'default_material_families' => [
            ['SS', 'Stainless Steel'],
            ['BRASS', 'Brass'],
            ['AL', 'Aluminium'],
            ['OTHER', 'Other'],
        ],

        'default_material_grades' => [
            ['SS202', 'SS 202', 'SS'],
            ['SS304', 'SS 304', 'SS'],
            ['SS316', 'SS 316', 'SS'],
            ['SS316L', 'SS 316L', 'SS'],
        ],

        'default_processes' => [
            ['CAST', 'Investment Casting', 'internal', true],
            ['FORGE', 'Forging', 'internal', false],
            ['CNC', 'CNC Turning', 'internal', false],
            ['VMC', 'VMC Machining', 'internal', false],
            ['CUT', 'Cutting', 'internal', false],
            ['DRILL', 'Drilling', 'internal', false],
            ['GRIND', 'Grinding', 'internal', false],
            ['POLISH', 'Polishing', 'internal', false],
            ['PVD', 'PVD Coating', 'internal', true],
            ['ANOD', 'Anodizing', 'internal', true],
            ['PCOAT', 'Powder Coating', 'internal', true],
            ['ASSY', 'Assembly', 'internal', false],
            ['PACK', 'Packaging', 'internal', false],
            ['JOBWORK', 'External Job Work', 'external', true],
        ],

        // [code, name, data_type, default UOM code|null]
        'default_qc_parameters' => [
            ['DIA', 'Diameter', 'numeric', 'MM'],
            ['HEIGHT', 'Height', 'numeric', 'MM'],
            ['THICK', 'Thickness', 'numeric', 'MM'],
            ['FLAT', 'Flatness', 'numeric', 'MM'],
            ['SURF', 'Surface Finish', 'selection', null],
            ['VISUAL', 'Visual Defect', 'pass_fail', null],
            ['GRADE', 'Material Grade', 'text', null],
            ['COLOR', 'Color', 'text', null],
            ['WEIGHT', 'Weight', 'numeric', 'GM'],
            ['DIMTOL', 'Dimensional Tolerance', 'pass_fail', null],
        ],

        'qc_data_types' => [
            'numeric' => 'Numeric',
            'pass_fail' => 'Pass / Fail',
            'text' => 'Text',
            'selection' => 'Selection',
        ],

        'time_units' => ['second' => 'Seconds', 'minute' => 'Minutes', 'hour' => 'Hours'],
        'capacity_periods' => ['hour' => 'Hour', 'shift' => 'Shift', 'day' => 'Day'],

        /*
        | SKU / item code generation.
        |   manual   – the user always enters the code;
        |   auto     – always generated;
        |   optional – generated only when left blank (default).
        | Pattern tokens: {category} (category SKU prefix, inherited from the
        | parent), {grade} (material grade code; segment dropped when empty),
        | {seq} (per-prefix running number).
        */
        'sku' => [
            'mode' => env('ERP_SKU_MODE', 'optional'),
            'pattern' => env('ERP_SKU_PATTERN', '{category}-{grade}-{seq}'),
            'sequence_padding' => (int) env('ERP_SKU_PADDING', 3),
        ],

        /*
        | Tables in future modules that reference items, as [table => column].
        | A referenced item's base UOM and item type become locked. Inventory
        | registers e.g. 'stock_ledger_entries' => 'item_id'.
        */
        'item_references' => [],

        'documents' => [
            'disk' => env('ERP_DOCUMENT_DISK', 'local'),
            'max_kb' => (int) env('ERP_DOCUMENT_MAX_KB', 20480),
            'extensions' => ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'dwg', 'dxf', 'step', 'stp', 'igs', 'iges', 'xlsx', 'xls', 'docx', 'csv', 'txt'],
            'image_extensions' => ['png', 'jpg', 'jpeg', 'webp'],
        ],

        'import' => [
            'max_rows' => (int) env('ERP_IMPORT_MAX_ROWS', 20000),
            // Commits above this many rows run on the queue (needs a worker).
            'queue_threshold' => (int) env('ERP_IMPORT_QUEUE_THRESHOLD', 500),
            'max_kb' => (int) env('ERP_IMPORT_MAX_KB', 10240),
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
