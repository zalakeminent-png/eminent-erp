# Eminent Factory ERP

Manufacturing ERP built with Laravel 12 (REST API), React 18 + TypeScript (SPA), PostgreSQL,
Sanctum cookie authentication and Spatie Laravel Permission.

**Implemented:** Module 1 — System Settings & User Management (authentication, company settings,
users, roles, permission matrix, activity logs). Later modules (Inventory, Orders, Production,
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

Mail must be configured (`MAIL_*`) for invitations and password resets. With `MAIL_MAILER=log`,
emails are written to `storage/logs/laravel.log`.

## Tests

```bash
php artisan test   # backend feature tests (SQLite in-memory)
npm test           # frontend (Vitest + Testing Library)
npm run typecheck
```

## Adding a future module's permissions

1. Register the module and its applicable actions in `config/erp.php` → `modules`.
2. Run `php artisan erp:sync-permissions` (creates the permissions and grants them to Admin).
3. Add sidebar entries in `resources/js/layout/navigation.ts` with the guarding permission.
