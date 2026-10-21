# Eminent Factory ERP

Manufacturing ERP built with Laravel 12 (REST API), React 18 + TypeScript (SPA), PostgreSQL,
Sanctum cookie authentication and Spatie Laravel Permission.

**Implemented:** Module 1 — System Settings & User Management (authentication, company settings,
users, roles, permission matrix, activity logs) and Module 2 — Factory & Department Management.
Later modules (Inventory, Orders, Production,
QC, Dispatch, Accounting, HR) have reserved permission namespaces only.

## Architecture

| Layer | Location | Notes |
|---|---|---|
| API | `routes/api.php` → `app/Http/Controllers/Api/V1` | All under `/api/v1`, permission middleware on every route |
| Auth | Sanctum stateful SPA (session cookie + CSRF) | No tokens in `localStorage`; no public registration |
| RBAC | `config/erp.php` permission catalogue (`module.action`) | Authorization is permission-based, never by role name |
| Safeguards | `app/Services/AccessGuard.php` | Privilege-escalation and last-administrator protection |
| Audit | `app/Services/ActivityLogger.php`, `activity_logs` table | Sanitized metadata, append-only, retention pruning |
| SPA | `resources/js` (served by `resources/views/app.blade.php`) | Same origin as the API |

## Requirements

- PHP 8.2+ with `pdo_pgsql`, `pgsql`, `gd`, `fileinfo`, `mbstring`
- Composer 2, Node.js 20+ (tested with 24), PostgreSQL 14+

## Setup

```bash
composer install
npm install
cp .env.example .env          # then edit DB_* and MAIL_* values
php artisan key:generate

# PostgreSQL: create the database first, e.g.
#   createdb -U postgres eminent_erp
php artisan migrate --seed    # company, 5 core roles, 50 permissions; no users
php artisan storage:link      # public URL for company logos
php artisan erp:create-admin  # interactive; prompts for email, name, password (hidden)
```

`erp:create-admin` refuses to run once an active administrator exists (use `--force` to add
another). On Windows the hidden password prompt needs a real console; it can't read piped input.

## Running

```bash
php artisan serve             # http://localhost:8000
npm run dev                   # Vite dev server with HMR (or `npm run build` for production assets)
php artisan schedule:work     # local scheduler: activity-log pruning, expired reset tokens
```

Open http://localhost:8000 and sign in with the administrator account.

## Environment variables (beyond Laravel defaults)

| Variable | Purpose |
|---|---|
| `SANCTUM_STATEFUL_DOMAINS` | Hosts allowed to use cookie auth (include host:port) |
| `FRONTEND_URL` | Base URL used in password reset / invitation emails |
| `CORS_ALLOWED_ORIGINS` | Comma-separated origins (only needed if the SPA is served from another origin) |
| `SESSION_SECURE_COOKIE` | Set `true` in production (HTTPS) |
| `SESSION_ENCRYPT` | `true` — session payload encrypted at rest |
| `AUTH_PASSWORD_RESET_EXPIRE` | Minutes a reset/invitation link is valid (default 60) |
| `ACTIVITY_LOG_RETENTION_DAYS` | Empty = keep forever; otherwise pruned daily |
| `ERP_COMPANY_NAME`, `ERP_COMPANY_CODE` | Initial company created by the seeder |
| `ERP_LOGO_MAX_KB` | Logo upload limit (default 2048) |
| `ERP_OPENING_STOCK_MAX_ROWS` | Maximum rows per opening stock file (default 20000) |
| `ERP_OPENING_STOCK_QUEUE_THRESHOLD` | Opening stock posts above this many rows run on the queue (default 2000) |

Mail must be configured (`MAIL_*`) for invitations and password resets. With `MAIL_MAILER=log`,
emails are written to `storage/logs/laravel.log`.

## Tests

```bash
php artisan test   # backend feature tests (SQLite in-memory)
npm test           # frontend (Vitest + Testing Library)
npm run typecheck
```

## Module 2 — Factory & Department Management

Factories (per company) → departments and warehouses (per factory) → nested storage locations
(per warehouse). Configuration only: no stock, receipts or movements.

- **Data scope:** permissions decide *what* a user may do; assignments decide *where*. Users with
  `factories.access-all` see every factory (Admin has it). Everyone else sees only their primary
  factory plus the factories of their primary/additional departments
  (`app/Services/Structure/StructureScope.php`). Out-of-scope records return 404.
- **Assignments:** `users.factory_id` (primary factory), `users.department_id` (primary department),
  `department_user` (additional departments). Managed via `PUT /users/{id}/assignments` or
  `PUT /departments/{id}/users`.
- **Status:** deactivation needs `{module}.deactivate`, reactivation `{module}.update`. Inactive
  records cannot receive new assignments or be selected; nothing cascades.
- **Locations:** cycle, same-warehouse and depth checks (`ERP_MAX_LOCATION_DEPTH`, default 6).
  Future modules should select locations with `WarehouseLocation::selectable()` and register their
  referencing tables in `config/erp.php` → `structure.location_references` so referenced locations
  cannot be deleted.

Setup on an existing Module 1 install:

```bash
php artisan migrate
php artisan db:seed --class=StructureSeeder   # new permissions (Admin only) + suggested types
```

No other role receives the new permissions automatically — grant them in Roles & Permissions.

## Module 3 — Master Data Management

Item categories (hierarchical) and configurable attributes, item/product master, units of measure
and conversions, material families/grades, manufacturing processes, machines & workstations,
BOMs, product routings, QC parameters/specifications, customers & suppliers, and Excel import.

- **Units:** each UOM has a dimension. Universal conversions only within convertible dimensions
  (mass, length, area, volume, time); PCS/BOX/SET convert only through item-specific conversions
  (e.g. 1 BOX = 100 PCS). Arithmetic is exact (bcmath); a conversion that would need more decimals
  than the target unit allows is refused instead of rounded. Future transactions should store the
  entered qty + UOM + factor and the base-UOM qty (`App\Services\MasterData\UomConverter`).
- **Versioned documents** (BOM, routing, QC spec): draft → approved (immutable) → inactive. Changes
  to an approved version go through "revise" (new draft version). Only one approved version per
  item may be effective on a date. Circular BOMs are rejected; approval never moves stock.
- **Items:** specification changes bump the item revision (history in `item_revisions`). Once an
  item is referenced by approved documents (or by tables listed in
  `config/erp.php` → `master_data.item_references`), its code, base UOM and type are locked.
  SKUs: `ERP_SKU_MODE` = `optional` (generate when blank, default) | `auto` | `manual`;
  pattern `ERP_SKU_PATTERN` (default `{category}-{grade}-{seq}` → `RM-SS316-001`).
- **Import:** upload `.xlsx`/`.csv` → map columns → validate & preview → confirm. Existing records
  change only with "update existing" enabled *and* confirmed. Imports above
  `ERP_IMPORT_QUEUE_THRESHOLD` rows (default 500) run on the queue — run `php artisan queue:work`.
- **Attachments** are stored on the private `local` disk and downloaded through
  `/api/v1/documents/{id}/download` (permission-checked).

Setup on an existing install (idempotent; recommended role permissions are granted once and
never re-applied, so permission-matrix changes survive re-seeding):

```bash
composer install            # adds openspout/openspout
php artisan migrate
php artisan db:seed --class=MasterDataSeeder
npm install && npm run build
```

## Warehouses are internal

All storage is inside the company, so warehouses are not managed in the UI. Each factory has one
internal storage area (its existing warehouse, or `MAIN`, created automatically by
`App\Services\Structure\DefaultWarehouse` when the first location is added). Users work with
**Factory → Storage locations** only; APIs accept `factory_id` (or a location) wherever a
warehouse used to be required. The `warehouses` table, API and permissions remain for data
integrity but are hidden (the Warehouses module is marked `hidden` in `config/erp.php`).

## Module 4 — Inventory & Warehouse Management

Stock ledger, opening stock (Excel), goods receipts, transfers, material issue/return, WIP and
finished-goods views, QC stock dispositions, scrap & rejection, reservations, adjustments,
physical counting, ledger, dashboard and 18 reports.

- **One writer:** every stock change goes through `App\Services\Inventory\StockPostingService`.
  It writes signed, immutable ledger entries (`inventory_ledger_entries`, base UOM) and updates
  the cached `inventory_balances` (item + location + status) in the same DB transaction, under
  row locks taken in a fixed order. UPDATE/DELETE on the ledger tables is refused by database
  triggers. Corrections are compensating entries (adjustments, reversals), never edits.
- **Idempotent:** each posting has a unique idempotency key (e.g. `grn:15:post`); repeating a
  request returns the original transaction. Partial transfer receipts carry a client request key.
- **Rules:** no negative stock (except items flagged `allow_negative_stock`, and only for
  available stock); available stock never drops below active reservations; locations in a
  running stock count are locked; new stock only enters active locations. QC items are received
  into *QC Pending*; damaged returns go to *QC Hold*. Rejected/scrap/QC stock is never reservable.
- **Values** use moving-average cost only where real costs exist; stock received without a cost
  has unknown value and is reported as "not valued" (never estimated). Quantities of different
  units are never added together.
- **Stock purpose:** warehouses/locations have an optional `stock_purpose` (Inventory Settings);
  a location inherits its warehouse's purpose. Material issues must go to Production / WIP.
- **Opening stock:** template → upload → map → validate & preview → submit → approve → post.
  Refused for any item/location/status that already has stock history. Imports above
  `ERP_OPENING_STOCK_QUEUE_THRESHOLD` accepted rows (default 2000) post on the queue.
- **Maintenance:** `php artisan inventory:verify-balances` (read-only check that balances equal
  the ledger) and `inventory:expire-reservations` (scheduled daily; needs `schedule:run`).

Setup on an existing install (additive; take a database backup first):

```bash
pg_dump -U postgres -Fc -f eminent_erp_before_module4.dump eminent_erp
php artisan migrate
php artisan db:seed --class=InventorySeeder   # permissions, reason codes, one-time role grants
npm install && npm run build
```

## Adding a future module's permissions

1. Register the module and its applicable actions in `config/erp.php` → `modules`.
2. Run `php artisan erp:sync-permissions` (creates the permissions and grants them to Admin).
3. Add sidebar entries in `resources/js/layout/navigation.ts` with the guarding permission.
