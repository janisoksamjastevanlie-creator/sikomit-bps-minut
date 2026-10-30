---
name: SiKomit Builder
description: Build, test, and prepare the BPS SiKomit web, Laravel API, Android, SPK/BAST workflows, and Hostinger deployment in this repository.
---

# SiKomit Builder

Use this skill whenever implementing or maintaining SiKomit in this repository. Treat the user's latest **MASTER PROMPT FINAL SIKOMIT** and the supplied design/template files as the current product requirements. Read the current project docs and code before changing behavior; preserve existing files and operational data unless a migration has been explicitly planned and backed up.

## Product boundary

SiKomit manages BPS partners, statistical activities, assignments, work-result verification, SPK, BAST, notifications, signatures, reports, and private archives. The application ends when required final SPK/BAST documents are archived.

**Do not implement payment or financial transaction features:** no honor/payment processing, disbursements, bank transfers, payment status, tax calculations, accounting, ledgers, or financial dashboards/reports. If an approved contract template requires a contract value, keep it only in the document snapshot/rendering needed for that official document; do not make it a business workflow, calculation, aggregate, or report.

## Contract types and document modes

Support exactly these contract categories:

1. `PENDATAAN_LAPANGAN` — field data collection/mapping.
2. `PENGOLAHAN` — data processing.
3. `PENDATAAN_DAN_PENGOLAHAN` — both work types; keep their tasks, targets, schedules, actuals, and BAST results separately reviewable.

Separate SPK/BAST documents are the default. Combined documents must remain disabled until authorized BPS officials confirm the procedure and approve a combined template. Do not silently merge values, targets, or document numbers. Changing a mode after signature requires a valid revision/cancellation workflow.

## Technology and deployment target

- The environment supports PHP 8.2, Composer, PDO MySQL, Node.js, Kotlin/Gradle, and XAMPP MariaDB. The target application stack is **Laravel 12 + MySQL/MariaDB**, Blade/Vite for web, Sanctum REST API v2, and Kotlin/Jetpack Compose Android.
- Android and web must use the same Laravel API/database. Use parameterized Eloquent/query-builder operations and migrations; do not use the legacy JSON file as the production source of truth.
- The final hosting target is Hostinger. Develop/test on localhost first. Production requires HTTPS, private file storage, a least-privilege DB account, environment-managed secrets, backup/restore checks, security review, and UAT.
- The root Node server, JSON data, old UI, backups, and uploads are legacy. Do not publish, deploy, migrate, overwrite, or delete them as part of the Laravel app without explicit backup and migration approval.
- Never commit `.env`, tokens, private keys, signing keys, runtime databases, uploaded documents, or filled sample templates. Never put secrets in an APK or source control.

## Authorization and privacy

Enforce RBAC and resource scope in backend policies/queries, not only in the UI. Mitra users can access only their linked partner, assignments, work reports, documents, and files. Separate maker, reviewer, approver, and signer duties. Keep audit logs append-only from application routes.

Collect only required partner data. Store documents/evidence on a private disk and authorize every download. Validate upload extension/content, MIME, size, and hashes. Do not claim antivirus scanning or certified electronic signatures unless those services are actually integrated and verified.

GPS/background location is optional and **off by default**. Enable only after purpose, legal basis, user notice/consent as applicable, access, retention, Android foreground-service requirements, and security review are confirmed. Prefer check-in/out when sufficient; location is never the sole proof of work.

## Templates and visual reference

Use the supplied BPS visual reference and `docs/DESAIN-UI-SIKOMIT.md`: responsive government UI, collapsible navy sidebar, BPS blue `#0B5CAB`, clean cards/tables, status badges, timelines, and Bahasa Indonesia throughout. Do not embed machine-specific absolute paths in public documentation.

Treat the supplied DOCX files as **filled examples**, not production seed data:

- User-supplied SPK/BAST DOCX reference examples; local copies, if present, are under `templates/` and excluded from Git because they contain personal/example data.

Preserve official wording, layout, clauses, and appendices. Do not silently repair apparent inconsistencies or invent legal wording, numbering rules, signers, SOP, or authority. The examples contain payment clauses/values; retain them only if the approved template requires them, without implementing payment features. Do not use example names, NIP, addresses, numbers, values, signatures, or completed results as operational data. Require a separately reviewed/approved dynamic template version before final document generation. Draft rendering is not approval, signature, or finalization.

## Required workflow

Partner verification → activity and work targets/schedules → assignment → SPK draft/review/approval/signature → work submission and verification → BAST from accepted work linked to the correct SPK → review/approval/signature → final archive. BAST must not finalize until all required results, checks, signatures, and document prerequisites pass server-side.

Keep the dashboard/report scope administrative only. Counts come from the database and obey the user's resource scope. Provide loading/empty/success/error/forbidden UI states.

## Excel import rules

- The authorized Excel import page is `/impor-excel`; the XLSX template is generated by `GET /api/v2/excel-import/template`. Sheets are `Mitra`, `Kegiatan`, `Penugasan`, and `Laporan`; import one type/sheet per request.
- Imports are atomic per selected sheet. Reject formula cells, altered headers, invalid references, duplicates, and row counts above the documented limit; return row-specific errors and write nothing on failure.
- Excel cannot bypass workflow states: imported partners stay `MENUNGGU_VERIFIKASI`; activities stay `DRAFT`; assignments require existing active activities and active verified non-demo partners; reports can only be imported by the linked mitra account for its own SPK final and remain `DRAFT`.
- Never add financial, tax, bank, NIK/NPWP, signature, or TTE columns. Do not let Excel activate, verify, accept, approve, sign, or finalize records.
- Keep PhpSpreadsheet as a maintained Composer dependency; exercise each import type, authorization boundary, atomic rollback, and generated workbook in tests.

## Demo data

- `DemoDataSeeder` is explicitly local-only and must refuse any database except `sikomit_local`. Every demo entity must be marked `is_demo` or derive demo scope from its demo assignment and stay excluded from operational counts/workflows.
- Demo archive uses `SIMULASI_SELESAI`, conspicuous “DEMO — BUKAN DOKUMEN RESMI” PDFs, and no template/signature/TTE records. Never convert this simulation to `FINAL` or treat it as approval evidence.

## Development and reporting

- Work incrementally; add migrations, API contracts, RBAC tests, integration tests, and Android tests alongside each module.
- Local Laravel DB is a dedicated database (`sikomit_local`); never point tests at legacy `simhon_bps_minut` or operational JSON.
- Admin accounts must be created with the explicit bootstrap command and a strong environment-provided password; do not seed `admin/admin123` or any default credential.
- Verify migrations, `php artisan test`, Composer validation, Vite build, Android Gradle build, and local API flows. Report the actual command results.
- Current implementation status and local run steps are documented in `docs/RANCANGAN-LARAVEL-SIKOMIT.md`, `docs/API-LARAVEL.md`, `docs/LOCALHOST.md`, and `docs/DEPLOY-HOSTINGER.md`. Update these when implementation status changes.
- Never claim production readiness, Hostinger deployment, UAT, legal compliance, official template approval, antivirus scanning, or certified TTE unless it has actually been completed and verified.
