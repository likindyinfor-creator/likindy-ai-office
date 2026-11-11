# Likindy AI Office API

Laravel 12 API foundation for the Next.js web client and future Flutter client.
API routes are versioned under `/api/v1`. The PostgreSQL schema uses UUID
identifiers. Documents are private by default; access to private documents is
limited to the active workspace owner or a member with an explicit access grant.
Workspace administrators do not receive implicit access to private documents.
New workspace memberships default to `invited` and must be activated explicitly.
Documents are constrained to an owner membership in the same workspace.

## Local prerequisites

- PHP 8.2 or newer with `pdo_pgsql` enabled.
- Composer 2.
- PostgreSQL server and a local development database/role.
- Laravel framework 12; Sanctum is installed by `php artisan install:api`.

The current environment has PHP 8.2 and PostgreSQL 18 installed, but the
PostgreSQL Windows service is stopped. The PHP `pdo_pgsql` DLL is present but is
not enabled in the active `php.ini`. Enable it in the local PHP configuration
before connecting to PostgreSQL. No database password is provided here.

## Configure local environment

1. Copy `.env.example` to `.env` if needed.
2. Set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` to
   values provisioned for your local PostgreSQL instance. The example database
   and username are local placeholders, not production credentials.
3. Set `FRONTEND_ORIGINS` and `SANCTUM_STATEFUL_DOMAINS` to the exact web
   development origins/domains. Do not use wildcard origins with credentials.
4. Generate a local application key with `php artisan key:generate`.
5. Start PostgreSQL and create the local database/role using your approved
   machine setup. This repository does not create database roles or passwords.
6. Apply migrations only after confirming the selected database and credentials.

## API and tests

- `GET /api/v1/health` is an unauthenticated liveness endpoint.
- `POST /api/v1/auth/register` creates an unverified user and requests a
  verification notification after registration commits.
- The notification URL targets
  `GET /api/v1/auth/email/verify/{id}/{hash}`. It is temporary and signed;
  verification does not require an existing browser session. Invalid or
  expired links return a generic `403` JSON response, while repeated valid
  links return an idempotent success response.
- Verification notifications are queued. Production deployments must configure
  a durable queue and run workers; registration responses report whether
  notification dispatch was accepted or failed. A dispatch failure is logged
  and does not undo or misreport the already committed registration.
- Next.js web authentication uses Sanctum's stateful session cookie flow:
  request `GET /sanctum/csrf-cookie`, then send credentialed requests with the
  `X-XSRF-TOKEN` header. Use `POST /api/v1/auth/login`,
  `GET /api/v1/auth/me`, and `POST /api/v1/auth/logout`; do not store bearer
  tokens in the browser. Login and protected application APIs require verified
  email. Login is rate-limited per IP and normalized email.
- Password reset uses Laravel's password broker. Reset links target the
  configured `FRONTEND_URL/reset-password` page and carry the short-lived token
  and normalized email as query parameters; the frontend should remove those
  parameters from browser history after consuming them. The broker expires
  tokens after 60 minutes and throttles token creation to once per email every
  60 seconds. Forgot-password responses do not disclose account existence.
- Reset notifications are queued and encrypted in the queue payload. Production
  must run a queue worker and use a durable queue. Successful password resets
  revoke Sanctum tokens and, when `SESSION_DRIVER=database`, delete that user's
  database sessions. File, Redis, and other session stores are not globally
  revoked by this endpoint; use the database session driver when global
  invalidation is required.
- Flutter/mobile authentication uses `POST /api/v1/mobile/auth/login` to issue
  a Sanctum bearer token with the explicit `likindy-mobile` name and a 30-day
  per-token expiry. Store that token only in secure device storage. Send it as a
  bearer token to `GET /api/v1/mobile/auth/me` and
  `POST /api/v1/mobile/auth/logout`; logout revokes only the current token.
  Login is limited per IP and normalized email. No mobile token is returned by
  `/me`, and verified email is required for issuance and protected mobile APIs.
- Verified users can create institution or business workspaces with
  `POST /api/v1/workspaces` and list their active memberships with
  `GET /api/v1/workspaces`. Creation assigns the caller as creator and active
  owner in one transaction; workspace IDs, slugs, and membership privileges
  are server-controlled. Workspace creation is limited to 3 requests per
  minute per IP and user. Listing excludes invited, suspended, removed, and
  soft-deleted workspaces.
- Active workspace owners and admins can manage invitations and memberships.
  `POST /api/v1/workspaces/{workspace}/invitations` accepts `email` and a
  permitted `role`; owners may invite admins, staff, or members, while admins
  may invite staff or members. Invitations expire after
  `WORKSPACE_INVITATION_EXPIRATION_DAYS` (default 7), and acceptance/decline
  requires a verified account whose email matches the invitation. The
  invitation notification links to
  `FRONTEND_URL/invitations/accept#token=...`; the frontend submits the token
  from the URL fragment to the authenticated accept/decline API. Only a
  SHA-256 token hash is stored. Invitation mail delivery refuses `log`
  transports (including log-based failover) because they write complete
  messages to application logs. Configure a real mail transport; ensure reverse
  proxy/access logs redact invitation-token API path segments.
- Owners can manage all member roles; admins can manage staff/member roles but
  cannot edit themselves or admins/owners, assign admin/owner, or remove
  admins/owners. Only owners can assign or remove owner status, and a workspace
  must retain at least one active owner. Invited memberships activate only
  through accepting a verified, matching invitation. Member removal/suspension
  takes effect immediately in workspace authorization; private documents remain
  private unless explicitly granted/shared.
- Run the additive workspace invitation migration during deployment. It adds
  a unique pending-email key to prevent concurrent duplicate invitations; it
  stops safely if existing unexpired duplicate invitations need manual
  resolution. Configure mail and a queue worker in production; invitation
  notifications are queued and encrypted.
- Set `FRONTEND_ORIGINS` and `SANCTUM_STATEFUL_DOMAINS` to the exact deployed
  frontend origins and host/port values. For HTTPS production set
  `SESSION_SECURE_COOKIE=true`; keep cookies HttpOnly and SameSite=Lax for
  same-site deployments. Sanctum's stateful middleware enforces SameSite=Lax;
  a frontend and API on different sites need a deliberate middleware override
  and Secure/SameSite=None plus a CSRF and browser-policy review.
- Document list/detail routes require `auth:sanctum` and active workspace
  membership and verified email. Private documents are filtered by owner or
  explicit grant.
- Only a document's owner can manage sharing through
  `GET|POST /api/v1/workspaces/{workspace}/documents/{document}/sharing`,
  `PATCH /api/v1/workspaces/{workspace}/documents/{document}/sharing/{member}`,
  and `DELETE` on the same member route. Grant requests use `user_id` and a
  `permission` of `read` or `edit`; grantees must be active members of that
  workspace. Suspended members are excluded from access listings and lose
  document access immediately. Workspace roles and document grants do not
  authorize non-owners to manage sharing.
- Document version history is available at
  `GET /api/v1/workspaces/{workspace}/documents/{document}/versions` and
  `GET /api/v1/workspaces/{workspace}/documents/{document}/versions/{version}`.
  Users who can view the parent document can view its history; restoring a
  numbered version uses `POST` on
  `/api/v1/workspaces/{workspace}/documents/{document}/versions/{version}/restore`
  and requires the document update permission. Restoration creates a new
  version entry and records the restoring user and timestamp.
- Document lists support `search` across titles and stored HTML/LaTeX content,
  `type`, `workspace_id` (within the workspace in the route), `created_from`,
  and `created_to` (`YYYY-MM-DD`). Use `sort=title`, `sort=created_at`, or
  `sort=updated_at`; the default is `updated_at` descending, while title sorts
  ascending. Pagination defaults to 20 results and accepts `per_page` from 1
  through 100. Search and filters run only on documents visible to the active
  workspace member, so private documents remain excluded unless owned or
  explicitly shared.
- Document attachments are private and available only through authenticated
  API routes: `POST /api/v1/workspaces/{workspace}/documents/{document}/attachments`,
  `GET /api/v1/workspaces/{workspace}/documents/{document}/attachments`,
  `GET /api/v1/workspaces/{workspace}/documents/{document}/attachments/{attachment}/download`,
  and `DELETE /api/v1/workspaces/{workspace}/documents/{document}/attachments/{attachment}`.
  Uploads use the `file` multipart field; PDF, PNG, JPEG, and plain-text files
  up to 10 MiB are accepted. Owners and document editors can upload/delete;
  document readers can list/download. Files use server-generated names on the
  non-public `attachments` disk, and API responses never expose storage paths
  or public URLs.
- Owners can soft-delete documents with
  `DELETE /api/v1/workspaces/{workspace}/documents/{document}` and restore them
  within the retention period with
  `POST /api/v1/workspaces/{workspace}/documents/{document}/restore`. Soft-deleted
  documents are excluded from normal document, search, version, and attachment
  routes. The retention period is configured with
  `DOCUMENT_DELETED_RETENTION_DAYS` (default 30 days; minimum 1 day).
- Permanently purge only expired soft-deleted documents by explicitly running
  `php artisan documents:purge-expired`. First inspect eligible records with
  `php artisan documents:purge-expired --dry-run`. The command is not scheduled
  or automatically run in development or tests. Purging removes that document's
  attachment records and private files and writes a metadata-only audit event.
  Deletion, restoration, and purge audit events contain IDs, event type, actor
  where applicable, and timestamp; document and attachment contents are never
  recorded.
- Feature tests use an isolated in-memory SQLite database and do not require a
  running PostgreSQL server.

Run `php artisan route:list --path=api/v1`, `php artisan test`, and
`php artisan migrate:status` to inspect the local installation. Do not use
`migrate:fresh` against a database containing data.
