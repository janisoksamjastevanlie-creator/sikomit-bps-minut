# SiKomit Laravel API v2

Laravel implementation under `backend/`. Routes are prefixed `/api/v2`; API clients authenticate with Sanctum bearer tokens. Web pages use Laravel session authentication.

## Public endpoints

- `GET /api/v2/health` — process health only; no private data.
- `POST /api/v2/auth/login` — JSON `{ username, password }`; returns a short-lived/revocable Sanctum token and public user/role identity.

## Authenticated endpoints

All routes below require `Authorization: Bearer <token>`. Actions are checked by backend role middleware and data scope.

### Session and dashboard

- `GET /api/v2/auth/me`
- `POST /api/v2/auth/logout`
- `POST /api/v2/auth/change-password` — `current_password`, `password`, `password_confirmation`.
- `GET /api/v2/dashboard/administrasi` — DB-backed counts only; no honor/payment/tax metrics. Results are limited by role and assignments.
- A bootstrap account marked `must_change_password` receives `428 PASSWORD_CHANGE_REQUIRED` on other protected API routes until password change.

### Mitra

- `GET /api/v2/mitra?q=&status=&limit=&offset=`
- `GET /api/v2/mitra/{id}`
- `POST /api/v2/mitra`
- `PUT /api/v2/mitra/{id}`
- `POST /api/v2/mitra/{id}/verification`

Create/edit accepts the administrative allowlist (`nama`, `sobatId`, `partnerCode`, `jenis`, contact and necessary address fields). IDs such as NIK/NPWP and bank/rekening data are not collected by this first Laravel slice. Creation starts at `MENUNGGU_VERIFIKASI`; changed data must be reviewed again. Verification status changes are recorded in `partner_verification_histories`. Creator/editor cannot verify the same record. Mitra reads only their linked profile; examiners read only the pending queue.

### Kegiatan

- `GET /api/v2/activities?q=&status=&tahun=&limit=&offset=`
- `GET /api/v2/activities/{id}`
- `POST /api/v2/activities`
- `PUT /api/v2/activities/{id}` — only draft activities without assignments.
- `POST /api/v2/activities/{id}/activate`

Responses/requests allowlist administrative properties: work descriptions, target volumes, units, schedules, areas, requirements, partner need, responsible party, and status. No tariff, honor, budget, tax, or payment fields exist in the Laravel activity schema.

### Penugasan

- `GET /api/v2/assignments?q=&status=&limit=&offset=`
- `GET /api/v2/assignments/options`
- `GET /api/v2/assignments/{id}`
- `POST /api/v2/assignments`

Creation requires active non-demo activity and active verified non-demo partner(s). Schedule overlap is checked transactionally. Group assignments are supported by `mitraIds`; category is one of `PENDATAAN_LAPANGAN`, `PENGOLAHAN`, or `PENDATAAN_DAN_PENGOLAHAN`. Combined assignments must have separate work items for both Pendataan and Pengolahan. There are no payment fields.

Responses include `members[].assignmentMemberId` and each `targetPekerjaan[].assignmentWorkItemId` so a mobile client can link a work report to the correct assignment target without trusting client-supplied descriptions.

### Template, SPK, and BAST

- `GET /api/v2/templates` — list metadata for template versions; files remain private.
- `POST /api/v2/templates` — upload a DOCX version as `DRAFT`.
- `GET /api/v2/templates/{version}/file` — authorized private download.
- `POST /api/v2/templates/{version}/approve` — different Admin BPS approves required placeholders and signer capacities.
- `POST /api/v2/spk/drafts` — create one or more SPK drafts from assignment-member targets.
- `POST /api/v2/bast/drafts` — create a BAST draft from a final SPK only after every related work target has an accepted report.
- `PUT /api/v2/documents/{document}/draft` — update document snapshot fields; per-item values are accepted only when an approved template explicitly requires the `work_item_value` placeholder.
- `GET /api/v2/documents`, `GET /api/v2/documents/{document}`, `GET /api/v2/documents/{document}/preview`
- `POST /api/v2/documents/{document}/submit`, `/review`, and `/approve` — maker/reviewer/approver workflow with scoped backend authorization.
- `POST /api/v2/documents/{document}/signatures` — record manual hard-copy signature evidence. `TTE_PROVIDER` is rejected until an actual provider is integrated.
- `POST /api/v2/documents/{document}/signatures/{signature}/validate` — manual validation by a different reviewer.
- `POST /api/v2/documents/{document}/finalize` — archive the final signed PDF after required signatures and BAST prerequisites pass.
- `GET /api/v2/documents/{document}/file` — private final archive download.

Only explicitly marked demo records may use status `SIMULASI_SELESAI`; their private PDFs contain a prominent “DEMO — BUKAN DOKUMEN RESMI” warning and the download response includes `X-SiKomit-Demo: true`. Demo simulations are excluded from operational document/archive counts. They have no approved template version and no signature/TTE record.

Current DOCX files are filled examples and are not approved templates. A separately reviewed placeholder template is required before production use. Manual signature scans are not certified electronic signatures.

### Work reports and verification

- `GET /api/v2/work-reports?status=&limit=&offset=` and `GET /api/v2/work-reports/{report}` — role-scoped list/detail.
- `POST /api/v2/work-reports` — mitra submits all target items linked to a final SPK.
- `PUT /api/v2/work-reports/{report}` — owner revises a draft or requested correction; prior revisions are retained.
- `POST /api/v2/work-reports/{report}/submit` and `/review` — submit and manually accept/request changes/reject.
- `POST /api/v2/work-reports/{report}/evidence` — private evidence upload; a separate manual review is required before accepting a report with evidence.
- `POST /api/v2/work-reports/{report}/evidence/{evidence}/review` and `GET /api/v2/work-reports/{report}/evidence/{evidence}` — manually review and download private evidence.

Android currently provides scoped report listing and review actions. Evidence-file review is available through the authorized web/API flow; Android does not claim automated virus scanning.

### Excel import

- `GET /api/v2/excel-import/template` — downloads a blank XLSX workbook with sheets `Mitra`, `Kegiatan`, `Penugasan`, and `Laporan`.
- `POST /api/v2/excel-import/import` — multipart fields `type` (`mitra`, `kegiatan`, `penugasan`, or `laporan`) and `file` (XLSX, max 5 MB). Import is atomic: any invalid row cancels the whole selected sheet.
- Mitra rows are always created as `MENUNGGU_VERIFIKASI`; activity rows as `DRAFT`; assignment rows require existing active activities and verified non-demo partners; report rows are available only to the linked mitra account and require its SPK final, then create a `DRAFT` report.
- Excel import cannot verify partners, activate activities, submit/accept reports, attach evidence, approve templates, or sign/finalize documents. There are no finance/payment columns.

## Roles

Seeded role codes: `ADMIN_BPS`, `PJK`, `OPERATOR`, `PEMERIKSA`, `PETUGAS_MITRA`, `PEJABAT_PERSETUJU`, `PENANDATANGAN`, `AUDITOR`. Role permissions are seeded and can be revised through approved admin tooling. Route role checks are enforced server-side; resource scoping is additionally applied to partner/activity/assignment queries.

## Local and production configuration

- Laravel local environment is configured for a dedicated MariaDB database `sikomit_local` in XAMPP; it is separate from the old Node JSON store.
- Do not put `backend/.env` or production secrets in GitHub.
- Implemented locally: template versioning and private downloads, SPK/BAST drafts and workflow, manual signature capture/validation, final archive, work reports/evidence/review, and Android report listing/review.
- Not implemented: certified TTE provider, official WhatsApp notifications, GPS, automated malware scanning, backup/restore validation, security review, and UAT.
