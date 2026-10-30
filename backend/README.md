# SiKomit Laravel Backend

Backend/web target for the SiKomit application. This Laravel 12 service is separate from the retained Node/JSON prototype at the repository root. The Laravel database is a dedicated MySQL/MariaDB database; legacy JSON records are not imported automatically.

## Requirements

- PHP 8.2+ with `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `zip`, and XML extensions.
- Composer 2.
- MySQL 8 or MariaDB (the local XAMPP MariaDB 10.4 instance is used for development tests; verify Hostinger's supported version before deployment).
- Node.js/npm only to build the Vite frontend assets.

## Local XAMPP setup

1. Create a dedicated database, for example `sikomit_local`, with `utf8mb4` collation. Do not reuse `simhon_bps_minut` or import the legacy JSON automatically.
2. Copy `.env.example` to `.env`, set `APP_KEY`, database credentials, and a unique `SIKOMIT_ADMIN_PASSWORD` only for the one-time local bootstrap.
3. Install dependencies and migrate:

```powershell
composer install
npm install
npm run build
php artisan migrate --force
php artisan db:seed --class=RolePermissionSeeder --force
```

4. Create the first Admin BPS with no default password:

```powershell
php artisan sikomit:admin:create adminbps "Administrator BPS"
```

The command requires `SIKOMIT_ADMIN_PASSWORD` (minimum 12 characters, letters and numbers) from the local environment. It does not print the password. The initial account must change that password on first login.

5. Run the web/API service:

```powershell
composer run start:local
```

Web login: `http://127.0.0.1:8000/login`
API health: `http://127.0.0.1:8000/api/v2/health`

The Android debug build can target `http://10.0.2.2:8000/` on an emulator. For a USB-connected phone, use `adb reverse tcp:8000 tcp:8000` and build with `-PDEBUG_API_BASE_URL=http://127.0.0.1:8000/`.

## Security and scope

- Sanctum bearer tokens are kept in Android memory only and expire according to `SANCTUM_EXPIRATION`.
- Authorization is enforced in middleware/controllers; IDs and resource scopes are also checked in queries.
- Uploaded documents use private storage; never call `storage:link` for private files.
- No payment, transfer, tax, accounting, bank, or payment-status tables/routes are part of the Laravel schema/API.
- Any amount explicitly required by an approved SPK template may exist only in the document snapshot; it is not used for calculations or reporting.
- Never commit `.env`, local database files, personal documents, or signing keys.

## Current implementation status

Laravel authentication, RBAC seed roles, database-backed partner verification, activity drafts/activation, group assignments with work categories and overlap checks, dashboard counts, audit logs, and login/dashboard Blade views are implemented. SPK/BAST generation and approval, work-report verification, private document upload/download, template approval, and archives are still in progress. Do not deploy as production until these workflows, migrations, security review, backup/restore, and UAT are complete.
