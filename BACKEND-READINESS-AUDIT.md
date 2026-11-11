# Likindy AI Office - Backend Readiness Audit

**Audit scope:** Existing frontend source and configuration in this workspace
**Target architecture:** Next.js + TypeScript web, Laravel REST API, PostgreSQL, Flutter client
**Status:** Inspection and planning only. No existing files or dependencies were changed.

## Executive summary

The repository is a frontend-only Next.js App Router prototype. It has no Laravel application, API routes, database migrations, test suite, or network API calls. Authentication, document storage, template recency, profile and workspace settings, usage figures, notifications, and AI responses are either browser-local or illustrative demo data.

The UI already has useful domain concepts and flows to carry forward: document types/statuses, language, local document CRUD, template metadata, user preferences, and the distinction between private and workspace-shared content is a future requirement, not an existing feature. The principal backend design decision should be to make every persisted record belong to a workspace and to authorize every request through active workspace membership. A personal workspace can give individual accounts the same data model as institutions.

The recommended approach is an API-first Laravel service with PostgreSQL as the source of truth. Keep the web and Flutter clients on the same versioned API. Replace direct browser-storage access behind client-side repositories rather than exposing database or authorization logic to either client.

## 1. Existing project architecture

The source is small and organized by route/domain under the Next.js App Router:

```text
likindy-ai-office-starter/
  app/
    page.tsx                         # Login UI
    layout.tsx                       # Root layout and global stylesheet
    globals.css                      # Login, dashboard, editor, library, template, settings styles
    dashboard/
      page.tsx                       # Dashboard with mock metrics and recent documents
      components.tsx                 # Shared Sidebar, Header, StatCard, QuickAction, notices
    documents/
      page.tsx                       # My Documents library and actions
      storage.ts                     # Shared browser-local document CRUD and sanitizer
      components.tsx                 # Document actions menu and preview dialog
      new/page.tsx                   # Document editor and mock AI assistant
    templates/
      page.tsx                       # Search, category/language filters, recent templates
      components.tsx                 # Template cards and preview modal
      data.ts                        # Static template catalog and sample content
    settings/
      page.tsx                       # Profile, workspace, preferences and security placeholders
      storage.ts                     # Browser-local settings model and persistence
      useLocalSettings.ts            # Shared settings hook and same-tab synchronization
  public/
    images/lds-logo.png
  eslint.config.mjs
  next.config.ts
  tsconfig.json
  package.json
  README.md
```

`node_modules/` and `.next/` are local dependency/build output and are not application source. `package.json` defines Next.js, React, TypeScript, ESLint, and Lucide React. It has `dev`, `build`, `start`, and `lint` scripts; no test script or backend dependency is present. `next.config.ts` enables React strict mode. TypeScript strict checking is enabled.

There are no `app/api` routes, server actions, API clients, environment-specific API configuration, Laravel files, SQL migrations, or automated test files in the inspected source tree. No frontend code calls `fetch` or an HTTP client.

## 2. Existing data models and TypeScript types

### Persisted document

[`app/documents/storage.ts`](app/documents/storage.ts) defines:

- `StoredDocumentType`: `official-letter | report | proposal | meeting-minutes | general-document`
- `StoredDocumentLanguage`: `en | sw`
- `StoredDocumentStatus`: `draft | completed`
- `StoredDocument`: `id`, `title`, `type`, `language`, HTML `content`, `typeset`, `status`, derived `wordCount`, `createdAt`, and `updatedAt`

IDs are generated in the browser with `crypto.randomUUID()` for new documents and copies. A migrated legacy draft uses the fixed ID `legacy-draft`. The model has no workspace, owner, sharing, permissions, version history, deletion timestamp, or server revision.

### Templates

[`app/templates/data.ts`](app/templates/data.ts) defines:

- `TemplateCategory`: seven fixed categories
- `TemplateLanguage`: `English | Kiswahili`
- `TemplateDocumentType`: the same five document types
- `DocumentTemplate`: `id`, `name`, `description`, `category`, `language`, `documentType`, `format` (`document | latex`), `structure`, `content`, optional `formattedContent`, and `tags`

The catalog is a static TypeScript array of ten templates, not a database-backed entity. HTML and LaTeX source fields have no creator, scope, publication status, revision, or server-side access policy.

### Settings/profile

[`app/settings/storage.ts`](app/settings/storage.ts) defines `LocalSettings` with nested objects:

- `profile`: `fullName`, `email`, `jobTitle`
- `preferences`: `language`, `timezone`, `dateFormat`, `appearance`
- `workspace`: `name`, `type`, `description`, `contact`
- `notifications`: three booleans for document activity, product updates, and usage alerts

Workspace type is `Individual | Institution | Business`. Profile and workspace details currently represent a single demo identity/workspace, not authenticated entities or memberships.

### Other UI-only types

The code also defines component props/navigation types, document-library filter types, and an editor `Message` shape (`role`, `text`, optional `suggestion`). These are UI implementation details rather than durable chat, membership, or authorization models.

## 3. Browser storage keys, utilities, and hooks

| Key / event | Current owner | Stored value and behavior |
|---|---|---|
| `likindy-documents-v1` | `documents/storage.ts` | JSON array of `StoredDocument` objects; read, validate, write, create/update, duplicate, delete, and status change |
| `likindy-document-draft` | Legacy reader in `documents/storage.ts` | Prior single-draft object. `getDocuments()` migrates it once into the collection as `legacy-draft`; the old key is not removed |
| `likindy-local-settings-v1` | `settings/storage.ts` | JSON `LocalSettings` object; used by settings page, sidebar/header, and dashboard |
| `likindy-local-settings-changed` | `settings/storage.ts` | Same-tab custom event dispatched after saving settings; not a persisted key |
| `likindy-recent-templates` | `templates/page.tsx` | Array of template IDs, capped at eight; recent-section defaults are also hard-coded |

No `sessionStorage` use was found. Login form inputs, remember-me checkbox, editor assistant chat, transient notices, and filters remain in component state and are not persisted.

`useLocalSettings()` in [`app/settings/useLocalSettings.ts`](app/settings/useLocalSettings.ts) is shared by the dashboard shell and settings page. It loads from `localStorage`, listens to browser `storage` changes and the custom same-tab event, and reports read/write errors. The document library refreshes on `storage` events. Document edits performed in another component in the same tab are not delivered by the native `storage` event; the current UI relies on route navigation/refresh for those views.

All localStorage data is per browser profile and same-origin accessible. It is not a user account store, cloud sync, encrypted vault, or safe place for credentials.

## 4. Document lifecycle and export

The current UI flow is:

1. `/documents/new` initializes an empty document, applies a `?type=` selection, loads a template from `?template=`, or loads a saved item from `?documentId=`. It also attempts to load the migrated legacy draft when none of those parameters are present.
2. The editor uses a browser `contentEditable` element. Formatting buttons call `document.execCommand`; word count is derived from the editor text.
3. Save calls `saveDocument()` in the shared storage utility. It sanitizes the HTML through an allowlist, recalculates word count, keeps the prior `createdAt` on update, sets `updatedAt`, and replaces the route with the saved document ID.
4. `/documents` reads that same collection and supports title search, type/language/status filters, sorting by modification time or title, and empty states.
5. The action menu supports open/edit, preview, duplicate, status change, TXT download, and delete with browser confirmation. Duplicate creates a new UUID and starts as a draft. Delete permanently removes the item from the local array.
6. Editor and library exports produce plain text `.txt` files from rendered text. PDF export is a placeholder; rich-text formatting is omitted. No cloud save, real-time collaboration, document sharing, server export, or version history exists.

The workspace sets saved edits to `draft`, including edits to a document previously marked `completed`. There is no conflict detection, autosave, retry queue, or multi-device consistency. The local HTML sanitizer is useful for the demo but must not be treated as a server trust boundary.

## 5. User profile and workspace data

The only profile/workspace record is the browser-local `LocalSettings` object. Defaults are the demo user Amara Mushi and demo workspace Kilimanjaro Group. Dashboard/sidebar/header read those values through the shared hook, so changes in Settings are reflected across these screens in that browser.

This structure does not represent:

- A registered user, verified email, credential, or authentication session.
- A workspace owner versus an administrator or staff/member.
- Workspace membership invitations, status, or role changes.
- Per-user settings when multiple people share a browser.
- Documents private to a person versus shared with an institution.
- Server-managed notification delivery, subscription, invoice, or usage events.

`Settings` shows password, active-session, sign-out, avatar/logo upload, and upgrade controls only as explicit placeholders. No password is stored by Settings.

## 6. Template catalog and LaTeX source handling

The fixed catalog categories are Official Letters, Business Reports, Project Proposals, Meeting Minutes, Education Documents, Business Documents, and General Documents. The ten sample records include English and Kiswahili documents plus two English LaTeX examples: Research Report and Academic Thesis.

For regular templates, `content` is HTML. For LaTeX templates, `content` contains a raw `.tex` source string while `formattedContent` contains a separately authored HTML/typeset-style preview. When a LaTeX template is used, the editor chooses `formattedContent` (falling back to `content`) and sets `typeset: true`. Consequently:

- Raw LaTeX source is static client-bundled sample data, not a persisted document source.
- A document saved from a LaTeX template stores the editor's sanitized HTML content and a boolean `typeset` marker; it does not retain the `.tex` source.
- The LaTeX display is a formatted HTML mock, not compilation by a TeX engine.
- TXT export exports the rendered text, not `.tex`, PDF, or a compiled artifact.

If editable/reproducible LaTeX is a product requirement, store the source separately from the rendered representation, identify compiler/engine and template revision, and run any compilation in an isolated service with strict resource/network limits. Do not compile arbitrary user TeX inside the Laravel web process.

## 7. Demo authentication and AI behavior

### Authentication

The login screen at [`app/page.tsx`](app/page.tsx) validates required email/password fields in the browser and displays a translated demo notice on submit. Registration, password reset, and Google sign-in are placeholders. The password exists only in component state for the lifetime of the page and is not submitted to an API or saved. The remember-me checkbox is UI-only. There is no authenticated user context, route guard, sign-in state, token, or logout.

### AI assistant

The workspace assistant is a deterministic mock implemented in `documents/new/page.tsx`. It classifies a few instruction keywords locally, constructs sample suggestions from the first portion of editor text, and displays a timed response. Nothing is sent to an AI service. Applying a suggestion replaces editor text only after user action. The dashboard AI numbers and Settings usage/credits are illustrative hard-coded values; notification popovers are also static demo UI.

## 8. Frontend actions that need backend API support

| Module | API-backed capability needed |
|---|---|
| Authentication | Registration, login, logout/revocation, current user, email verification, password reset, optional MFA, session/device list |
| Profile | Read/update name, verified email change, job title, avatar upload |
| Workspaces | Create/list/update workspace, workspace type/description/contact/logo, select active workspace |
| Memberships/RBAC | Invite users, accept/expire invitation, list members, assign/revoke roles, suspend/remove users, permission checks |
| Documents | Paginated listing/search/filter/sort; create/read/update; status changes; duplicate; soft delete/restore; ownership and shared access |
| Document history | Save immutable revisions, compare/restore, optimistic concurrency and conflict handling |
| Document sharing | Private/workspace visibility, user grants, share/revoke and permission-aware reads |
| Exports/files | TXT/PDF/DOCX export jobs as required; private upload/download; logo assets and future attachments |
| Templates | List/search/categories/language; detail/preview; create a document from a versioned template; optionally manage institution templates |
| LaTeX | Secure server-side compile/export job only if needed; source storage/versioning distinct from rendered output |
| AI | Authenticated assist/summarize/rewrite/translate requests, suggestion results, quota checks, consent and usage recording |
| Preferences | Read/update locale, timezone, date format, appearance, per-user notification preferences |
| Notifications | Deliver/list/read/dismiss notification events; subscription to email/push channels if introduced |
| Usage/subscription | Entitlement/usage query, plan catalog, checkout/webhooks, invoices and limits; never trust client counters |
| Audit/admin | Security-sensitive activity records and institution-admin review where product policy requires it |

Prefer resource-oriented endpoints in a versioned `/api/v1` namespace. Lists should be paginated and filter parameters explicitly allowlisted. Mutations should return canonical server records, use structured validation errors, and use idempotency keys for retryable create/import/export/AI operations.

## 9. Recommended PostgreSQL schema

The following is a logical schema proposal, not existing database DDL. Use UUID primary keys and UTC `timestamptz` timestamps. Store only fields required by the product; add database migrations and indexes through Laravel migrations.

| Table | Important fields and constraints |
|---|---|
| `users` | `id`, normalized unique `email`, `email_verified_at`, `password_hash`, `name`, `job_title`, `avatar_object_key`, `status`, timestamps, `deleted_at` if soft deletion is required. Store only a password hash generated by Laravel's password hasher. |
| `workspaces` | `id`, `name`, unique `slug`, `type` (`individual`, `institution`, `business`), `description`, `contact`, `logo_object_key`, `created_by_user_id`, timestamps, `deleted_at`. |
| `workspace_memberships` | `id`, `workspace_id`, `user_id`, `role` (`owner`, `admin`, `staff`, `member`), `status` (`invited`, `active`, `suspended`, `removed`), invitation/join timestamps; unique `(workspace_id,user_id)`, index by `(user_id,status)`. |
| `workspace_invitations` | `id`, `workspace_id`, normalized `email`, role, hashed single-use token, inviter, expiry, accepted/revoked timestamps; unique active invitation policy per workspace/email. |
| `templates` | `id`, nullable `workspace_id` for system or workspace scope, `slug`, `name`, description, category, language, document type, content format, `structure` JSONB, rendered HTML, optional raw `latex_source`, tags, version, publication status, creator, timestamps. Unique scoped slug/version; workspace templates must be authorized. |
| `documents` | `id`, `workspace_id`, `owner_user_id`, `created_by_user_id`, nullable `template_id`, title, document type, language, `visibility` (`private`, `workspace`), status, content format, canonical editor content, rendered/sanitized HTML and plain-text search projection as appropriate, optional LaTeX source, version number, timestamps, `deleted_at`. Index `(workspace_id,updated_at DESC)` and owner/status/type filters. |
| `document_versions` | `id`, `workspace_id`, `document_id`, monotonic `version_number`, content/title snapshot, author, optional change summary, timestamp; unique `(document_id,version_number)`. Keep immutable once written. |
| `document_access_grants` | `id`, `workspace_id`, `document_id`, `user_id`, permission (`read`, `edit`, optionally `share`), grantor, timestamps; unique `(document_id,user_id)`. Use composite FKs or equivalent transaction checks to ensure document and grantee belong to the same workspace. |
| `user_preferences` | `user_id` primary/FK, locale, IANA timezone, date format, appearance, timestamps. |
| `notification_preferences` | `user_id` primary/FK, document activity/product updates/usage alerts channel preferences, timestamps. Keep delivery state separate from preferences. |
| `plans` | `id`, code, name, limits/entitlements JSONB or normalized entitlement rows, active flag. |
| `subscriptions` | `id`, `workspace_id`, `plan_id`, provider/reference, status, period start/end, cancellation state, timestamps. Never trust a browser's plan value. |
| `usage_events` | `id`, `workspace_id`, actor `user_id`, event type, quantity/unit, provider request ID, idempotency key, metadata with sensitive content excluded, occurred timestamp. Index `(workspace_id,occurred_at)`; derive totals server-side. |
| `notifications` | `id`, `user_id`, nullable `workspace_id`, event type, safe payload, read/dismissed timestamps, created timestamp. |
| `audit_logs` | `id`, nullable workspace/actor, action, target type/id, request/correlation ID, minimal redacted metadata, timestamp. Append-only and access-controlled. |
| `personal_access_tokens` | Laravel Sanctum-managed token records for mobile/API clients; use framework migrations, expiry policy, scopes where appropriate, and per-device revocation. |

Use composite tenant keys/FKs where practical: documents reference their workspace and owner membership; versions and grants reference the same workspace/document; grants target active workspace members. Keep workspace IDs in tenant-owned rows even if the relation can be derived. Add unique and partial indexes for membership, normalized email, active invitations, template slug/version, and active subscription. Use soft deletion for recoverable document deletion, with a retention/purge policy rather than silently deleting audit history.

For document content, choose one canonical editor representation and version it. The current HTML editor can initially map to `content_html` plus a derived plain-text projection; if the editor is replaced, a schema-versioned JSONB representation is a better canonical format. Sanitize/render on the server. Keep LaTeX source in a separate nullable field or related source table and do not conflate it with rendered HTML.

## 10. Entity relationships and tenant permission model

```text
User --< WorkspaceMembership >-- Workspace
Workspace --< Document >-- User (owner/creator)
Document --< DocumentVersion
Document --< DocumentAccessGrant >-- User
Workspace --< WorkspaceInvitation
Workspace --< Workspace-scoped Template
Document -- uses optional Template/version
User -- UserPreferences
User -- NotificationPreferences
Workspace --< Subscription >-- Plan
Workspace --< UsageEvent >-- User
User --< Notification
Workspace/User --< AuditLog
```

Create an `individual` workspace for each individual account rather than adding a second non-tenant document path. Institutional/business users join workspaces through memberships. A user can belong to multiple workspaces; every request selects an active workspace, and the API verifies active membership before serving that tenant's records.

Recommended baseline policy:

- `owner`: full workspace administration and ownership transfer controls.
- `admin`: manage workspace profile, invitations/members, workspace templates, and shared workspace documents; billing actions only if explicitly granted.
- `staff`: create and edit their own documents and workspace-shared items as allowed; no membership/billing administration.
- `member`: work with own private documents and read/edit shared documents only according to explicit grants and workspace policy.

Use Laravel Policies/Gates for each document, membership, template, export, and workspace action. A UUID is an identifier, not authorization. Resolve records within an authorized workspace scope to prevent cross-tenant IDOR. Decide explicitly whether administrators can view private member documents; do not silently grant that access. Optionally add PostgreSQL Row-Level Security as defense in depth after Laravel tenant scoping is correct and connection/context behavior has been tested.

## 11. Laravel model recommendations

Recommended Eloquent models:

- `User` (`HasApiTokens`, `HasMany` memberships, preferences, authored documents, notifications)
- `Workspace` (`HasMany` memberships/documents/invitations, subscriptions, usage events)
- `WorkspaceMembership` (belongs to user/workspace; role/status enum casts)
- `WorkspaceInvitation`
- `Document` (belongs to workspace, owner, creator, optional template; versions and access grants)
- `DocumentVersion`
- `DocumentAccessGrant`
- `Template` (system or workspace scope, optionally versioned)
- `UserPreference`, `NotificationPreference`
- `Plan`, `Subscription`, `UsageEvent`, `Notification`, `AuditLog`

Keep authorization in Policies and business operations in services/actions (for example `CreateDocumentFromTemplate`, `DuplicateDocument`, `ExportDocument`, `GenerateAiSuggestion`). Use Form Requests for validation and API Resources for stable response shapes. Avoid controllers directly manipulating models without tenant scoping or embedding AI/provider logic.

## 12. Suggested API endpoint inventory

All endpoints below are proposals. Protect private endpoints with authentication and authorize resource access on every request.

### Authentication and account

- `POST /api/v1/auth/register`
- `POST /api/v1/auth/login`
- `POST /api/v1/auth/logout`
- `POST /api/v1/auth/refresh` only if a refresh-token design is selected
- `GET /api/v1/me`
- `PATCH /api/v1/me`
- `POST /api/v1/auth/email/verification`
- `POST /api/v1/auth/password/forgot`
- `POST /api/v1/auth/password/reset`
- `GET /api/v1/me/sessions`, `DELETE /api/v1/me/sessions/{session}`

### Workspaces and membership

- `GET /api/v1/workspaces`, `POST /api/v1/workspaces`
- `GET /api/v1/workspaces/{workspace}`, `PATCH /api/v1/workspaces/{workspace}`
- `GET /api/v1/workspaces/{workspace}/members`
- `POST /api/v1/workspaces/{workspace}/invitations`
- `POST /api/v1/workspace-invitations/{token}/accept`
- `PATCH /api/v1/workspaces/{workspace}/members/{user}` for role/status changes
- `DELETE /api/v1/workspaces/{workspace}/members/{user}`
- `POST /api/v1/workspaces/{workspace}/logo` and private asset retrieval as applicable

### Documents and sharing

- `GET /api/v1/workspaces/{workspace}/documents` with pagination, search, type, language, status, visibility, and sort allowlists
- `POST /api/v1/workspaces/{workspace}/documents`
- `GET /api/v1/workspaces/{workspace}/documents/{document}`
- `PATCH /api/v1/workspaces/{workspace}/documents/{document}` with expected version/ETag
- `DELETE /api/v1/workspaces/{workspace}/documents/{document}`; optional restore endpoint
- `POST /api/v1/workspaces/{workspace}/documents/{document}/duplicate`
- `GET /api/v1/workspaces/{workspace}/documents/{document}/versions`
- `POST /api/v1/workspaces/{workspace}/documents/{document}/versions/{version}/restore`
- `GET|POST /api/v1/workspaces/{workspace}/documents/{document}/access-grants`
- `DELETE /api/v1/workspaces/{workspace}/documents/{document}/access-grants/{user}`
- `POST /api/v1/workspaces/{workspace}/documents/{document}/exports` and `GET /api/v1/exports/{export}` for asynchronous formats

### Templates and LaTeX

- `GET /api/v1/templates` with category/language/type/format/search filters; return system and authorized workspace templates
- `GET /api/v1/templates/{template}`
- `POST /api/v1/workspaces/{workspace}/documents/from-template`
- `POST|PATCH|DELETE /api/v1/workspaces/{workspace}/templates` for authorized template managers
- Optional `POST /api/v1/workspaces/{workspace}/latex/compile` as a queued, sandboxed job; return job status/artifact, not a synchronous web-process compile

### AI, preferences, notifications, and usage

- `POST /api/v1/workspaces/{workspace}/ai/assist` or `POST /api/v1/workspaces/{workspace}/documents/{document}/ai-suggestions`
- `GET|PATCH /api/v1/me/preferences`
- `GET|PATCH /api/v1/me/notification-preferences`
- `GET /api/v1/me/notifications`, `PATCH /api/v1/me/notifications/{notification}`
- `GET /api/v1/workspaces/{workspace}/usage`
- `GET /api/v1/plans`; billing/checkout and provider-webhook routes only when billing is selected

Return consistent JSON errors (validation, unauthenticated, forbidden, not found, conflict, rate limited). For document updates, return the canonical new version and reject stale `If-Match`/version values with `409 Conflict` or `412 Precondition Failed`.

## 13. Authentication and authorization strategy

Use Laravel Sanctum as a practical common authentication layer:

- **Next.js web:** for a same-site deployment, prefer Sanctum's first-party SPA cookie flow with secure, `HttpOnly`, `SameSite` cookies and CSRF protection. Do not put bearer tokens in localStorage. Configure exact stateful domains, HTTPS, CORS, and cookie domains deliberately.
- **Flutter:** use Sanctum personal access tokens over TLS, scoped to the minimum needed, with expiration and per-device revocation. Store tokens in the platform secure keystore/keychain, never ordinary app preferences. If long-lived rotating refresh tokens are required, specify and implement rotation/reuse detection as a separate design.
- Hash passwords with Laravel's configured Argon2id or bcrypt hasher. Add email verification, reset tokens with expiry/one-time use, login throttling, optional MFA, session revocation, and security audit events.
- Authenticate first, resolve the selected workspace from an explicit route/header context, verify active membership, and authorize the requested action through a policy. Never accept role, owner, workspace, plan, usage, or visibility claims as trusted client fields.

If the frontend and API are on different sites, revisit cookie/SameSite/CORS constraints before choosing cookie auth; keep the Flutter and web contracts consistent without weakening browser protections.

## 14. Local-storage migration strategy

1. Establish authenticated users and a personal/workspace context first. The current profile/workspace defaults are demo values and must not silently become real user data.
2. Add a client repository interface so UI components no longer call localStorage utilities directly. Implement API-backed and optional offline-cache adapters behind the same domain contract.
3. Offer an explicit, user-visible import after sign-in. Preview candidate local documents/settings, allow selection, and explain which workspace will receive them. Do not upload all browser data without consent.
4. Map `likindy-documents-v1` records to server document-create requests. Preserve display metadata/timestamps where policy permits, assign the authenticated owner and selected workspace on the server, and map `legacy-draft` idempotently. Treat client IDs only as import/idempotency references, never server authorization.
5. Provide an idempotency key/import batch ID and per-document result so retries do not duplicate data. Detect duplicate titles/content and resolve conflicts explicitly. Send large imports in bounded batches.
6. Map `LocalSettings.profile` and `.workspace` selectively: the authenticated profile should come from `/me`; only user-approved workspace fields should update a workspace. Map remaining preferences/notifications to user-preference endpoints.
7. `likindy-recent-templates` can be discarded or optionally migrated as a convenience preference. Do not treat it as important content.
8. Keep local data until the server confirms the imported record and the user accepts completion. Then offer export/delete of local copies. Define shared-device logout behavior and an account-switch purge boundary.
9. Prefer server-authoritative reads after migration. If offline editing is later added, cache records with schema version and last-known server version; reconcile through revision numbers/ETags instead of blind dual writes.
10. Keep secrets, credentials, auth tokens, and sensitive AI prompts/results out of localStorage and import payloads.

## 15. AI integration security recommendations

- Call providers only from a Laravel service/worker. Keep provider keys in server-side secret management; never bundle keys into Next.js or Flutter.
- Authenticate and authorize each operation against workspace membership and document permission. Enforce per-user/workspace rate, token, and cost quotas server-side; client-visible counters are not enforcement.
- Obtain clear consent before sending document content to a provider. Document data residency, retention, provider training policy, redaction options, and tenant controls.
- Send only the minimum required content; define prompt/history retention and deletion. Avoid logging full documents, credentials, personal information, or provider secrets.
- Treat document text as untrusted input. Defend against prompt injection by separating system instructions and content, restricting tools/data access, and never letting document instructions directly authorize operations.
- Validate provider response structure and size, apply timeouts/retries/circuit breakers, and sanitize HTML/links before rendering. Keep suggestions separate from the source document until a user explicitly applies them.
- Persist an AI request/usage event with actor, workspace, task, provider/model, token counts, status, latency, and idempotency/correlation ID; minimize or omit prompt/response bodies according to privacy policy.
- If offering LaTeX compilation, use a separate sandboxed worker/container with no network by default, tight CPU/memory/time/output quotas, restricted filesystem, and safe TeX engine configuration. Do not run arbitrary source in the API process.

## 16. Security risks and production recommendations

### Current demo risks and boundaries

- No real authentication or authorization exists; every frontend route is directly accessible. Do not deploy the prototype as though its documents are private accounts.
- Browser-local profile, workspace, and document data are readable/modifiable by same-origin JavaScript and can be lost when browser data is cleared. Shared devices can expose prior demo data.
- The editor previews HTML using `dangerouslySetInnerHTML`. The current client allowlist strips attributes and disallowed tags, but client validation can be bypassed and is not a production security boundary. Sanitize and validate again on the server and use a strict safe renderer.
- Raw LaTeX is embedded in the static client bundle. This is acceptable only for public sample templates; private or institution-authored sources need authorization and controlled delivery.
- Demo AI, login, billing, notification, password, and session controls are placeholders and must remain clearly marked until implemented.

### Before production

1. Enforce TLS, secure cookies, CSRF, exact CORS origins, CSP, security headers, and safe cache directives for private responses.
2. Authorize every resource query and mutation with tenant-scoped lookups and Laravel Policies. Add automated cross-tenant authorization tests for each role and sharing mode.
3. Validate lengths, enums, content formats, uploads, and state transitions with Form Requests. Apply server-side HTML sanitization, output encoding, file-type checks, malware scanning, private object storage, and signed short-lived downloads.
4. Add rate limits, email verification, password reset hardening, MFA option, session/token revocation, audit logging, and alerting. Never return hashes, tokens, private object keys, or sensitive provider data.
5. Protect against concurrent document overwrites with revisions/ETags; use transactions for membership, sharing, import, and billing updates.
6. Back up PostgreSQL and object storage, define restore drills, retention/deletion policies, encryption/key rotation, data export, and breach response.
7. Add structured redacted logs, request IDs, metrics, queue health, provider cost limits, and production error reporting. Avoid logging document contents and credentials.
8. Review legal/privacy requirements for institution data, AI provider terms, user consent, data residency, and retention before enabling AI or billing.

## 17. Suggested backend implementation phases

1. **Contracts and foundations:** confirm tenancy, role matrix, private/shared semantics, document format, API response/error conventions, deployment domains, and privacy policy. Create Laravel app, PostgreSQL migrations, CI, API versioning, and OpenAPI contract.
2. **Identity and tenancy:** register/login/verification/reset, Sanctum web/mobile flows, user profile, personal workspace provisioning, institution workspaces, invitations, membership states, and policy tests.
3. **Documents:** tenant-scoped CRUD, server sanitization, stable IDs, status rules, soft delete/restore, revisions/ETag conflict handling, filters/pagination, sharing grants, and audit events.
4. **Frontend integration and migration:** API client/repository adapter, authenticated route handling, loading/error states, explicit local import, idempotency/conflict UX, and removal of localStorage as the authoritative store.
5. **Templates and exports:** versioned catalog, workspace templates/permissions, create-from-template, content-format preservation, queued export artifacts, and secure LaTeX decision/worker if required.
6. **AI and usage:** consent/settings, secure provider gateway, background jobs where needed, quotas, usage ledger, safe suggestion workflow, provider failure handling, and cost monitoring.
7. **Notifications, billing, operations:** notification delivery/preferences, subscriptions/payment webhooks if in scope, admin tools, observability, backups/restore, security review, performance tests, and production runbooks.
8. **Flutter client:** consume the same `/api/v1` contracts, implement secure token storage, workspace selection, offline policy if needed, and run shared API contract/authorization tests.

Do not begin with billing, AI, or LaTeX compilation before identity, workspace authorization, document ownership, and privacy boundaries have been implemented and tested.
