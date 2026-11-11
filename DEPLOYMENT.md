# Cloud deployment

## Architecture

- **Vercel** serves Next.js and proxies `/api/*` and `/sanctum/*` to Laravel.
- **Render** builds `backend/Dockerfile` and serves Laravel over HTTPS.
- **Supabase PostgreSQL** stores application data and database-backed sessions.
- **Cloudflare R2** stores document attachments in a private S3-compatible bucket.

The browser calls same-origin relative URLs. Sanctum session credentials remain
in secure cookies; do not add bearer-token storage or expose backend/R2 secrets
to `NEXT_PUBLIC_*` variables.

## Deployment order

1. Provision the private Supabase database and private R2 bucket; enable HTTPS
   access and keep the R2 bucket's public access disabled.
2. Create the Render web service from the repository root with **Root
   Directory** set to the repository root and **Dockerfile Path** set to
   `backend/Dockerfile`. Railway is pinned to the same image by
   `railway.toml` (`builder = "DOCKERFILE"` and `dockerfilePath =
   "backend/Dockerfile"`); do not select Railpack or override the Dockerfile
   with a custom startup command. Configure environment variables below and set
   the health check path to `/api/v1/health`.
   Railway's `railway.toml` is Config-as-Code for an existing legacy service;
   Railway documents that this configuration stops being read after
   2026-12-01. Before that cutoff, migrate the service to Railway's supported
   Infrastructure-as-Code configuration or explicitly select this Dockerfile
   in the service settings. This repository configuration cannot verify or
   change a remote Railway service's current settings by itself.
3. Generate an application key with `php artisan key:generate --show` in a
   trusted local PHP/Laravel environment and add it as Render's `APP_KEY`.
   Never commit the generated value.
4. Only after an authorized operator explicitly approves the target database
   and release, run `php artisan migrate --force` as a separate one-off
   deployment operation. Never attach it to build, release, or container-start
   commands. Do not seed production data automatically; any seed operation
   requires its own explicit authorization and separate operation. The Docker
   build and entrypoint do not run migrations or seeders.
5. Deploy the Vercel project from the repository root and set
   `LARAVEL_BACKEND_URL` to the Render service origin (scheme and host only,
   with no trailing slash or path). Deploy the frontend on HTTPS.
6. Smoke-test `/api/v1/health`, CSRF-cookie initialization, sign-in, an
   authenticated request, sign-out, private attachment upload/download, and
   unauthorized attachment access. Do not treat a successful health check as
   proof that authentication or R2 has been tested.

## Vercel environment

Set this server-only variable for each applicable environment:

| Variable | Value |
|---|---|
| `LARAVEL_BACKEND_URL` | `https://YOUR_RENDER_SERVICE.onrender.com` |

No `NEXT_PUBLIC_API_URL` is used. Local development defaults the rewrite target
to `http://localhost:8000`; override it in `.env.local` only if Laravel runs at
a different origin. The frontend is normally served at `http://localhost:3000`.

## Render environment

Set these variable names in the Render service; enter actual secret values only
in Render's private environment settings.

| Variable | Purpose |
|---|---|
| `APP_NAME` | Application display name |
| `APP_ENV` | `production` |
| `APP_KEY` | Generated Laravel application key |
| `APP_DEBUG` | `false` |
| `APP_URL` | Render HTTPS service origin |
| `FRONTEND_URL` | Canonical Vercel HTTPS origin |
| `FRONTEND_ORIGINS` | Comma-separated exact browser origins allowed by CORS |
| `SANCTUM_STATEFUL_DOMAINS` | Vercel frontend hostname(s), without scheme or path |
| `TRUSTED_PROXIES` | `*` only when deployed behind Render ingress; see proxy note below |
| `DB_CONNECTION` | `pgsql` |
| `DB_HOST` | Supabase PostgreSQL host |
| `DB_PORT` | Supabase PostgreSQL port |
| `DB_DATABASE` | Supabase database name |
| `DB_USERNAME` | Supabase database user |
| `DB_PASSWORD` | Supabase database password |
| `DB_SSLMODE` | `require` |
| `SESSION_DRIVER` | `database` |
| `SESSION_ENCRYPT` | `true` |
| `SESSION_SECURE_COOKIE` | `true` |
| `SESSION_HTTP_ONLY` | `true` |
| `SESSION_SAME_SITE` | `lax` |
| `SESSION_DOMAIN` | Leave unset or `null` for a host-only proxy cookie |
| `QUEUE_CONNECTION` | `database` |
| `CACHE_STORE` | `database` unless a shared production cache is configured |
| `FILESYSTEM_DISK` | `local` for unrelated Laravel files |
| `DOCUMENT_ATTACHMENTS_DISK` | `r2` |
| `R2_ACCESS_KEY_ID` | R2 S3-compatible access key |
| `R2_SECRET_ACCESS_KEY` | R2 S3-compatible secret |
| `R2_REGION` | `auto` |
| `R2_BUCKET` | Private R2 bucket name |
| `R2_ENDPOINT` | Account-specific R2 S3 endpoint |
| `R2_USE_PATH_STYLE_ENDPOINT` | `true` |
| `DOCUMENT_DELETED_RETENTION_DAYS` | Soft-deleted document retention period (default `30`) |
| `MAIL_MAILER` | `smtp` after configuring a mail provider |
| `MAIL_SCHEME` | Provider-required SMTP scheme |
| `MAIL_HOST` | SMTP host |
| `MAIL_PORT` | SMTP port |
| `MAIL_USERNAME` | SMTP username |
| `MAIL_PASSWORD` | SMTP password |
| `MAIL_FROM_ADDRESS` | Verified sender address |
| `MAIL_FROM_NAME` | Sender display name |
| `LOG_CHANNEL` | `stderr` |

Set `FRONTEND_ORIGINS` and `SANCTUM_STATEFUL_DOMAINS` to the canonical Vercel
host. Add exact preview hostnames only if preview deployments need
authentication; do not use broad wildcard origins. The browser calls the Vercel
origin, and Next.js forwards its API and Sanctum paths to Render. Keep CORS
credentials enabled and origins explicit. The session cookie should remain
host-only, `Secure`, `HttpOnly`, and `SameSite=Lax`; Laravel's `XSRF-TOKEN`
cookie remains readable by the first-party client for the CSRF header.

The Docker entrypoint validates the platform's numeric `$PORT`, prepares
writable Laravel runtime directories, and starts Apache on that port. It does
not invoke Artisan, generate keys, run migrations, or start a queue worker. The
Docker build also disables Composer scripts and runs only Laravel package
discovery (not migrations or seeding). The entrypoint rejects arbitrary
container commands; its only supported override is `php artisan queue:work`
for a separately configured background worker. Render's ingress is
trusted only for forwarded client IP/port/protocol headers; forwarded host is
not trusted. Use `TRUSTED_PROXIES=*` only while the service is exclusively
reachable through Render's proxy layer and that layer overwrites forwarded
headers.

## Queued mail and worker

The API uses the configured queue connection for queued mail. A web service
alone does not process queued email. Create a separate Render background worker
using the same repository, Dockerfile, and environment variables, with start
command:

```text
php artisan queue:work --sleep=3 --tries=3 --timeout=90
```

Verify the provider's SMTP settings and run an end-to-end mail test before
relying on verification, invitations, or other queued mail. Password reset
remains unavailable in the frontend until its UI flow is implemented; do not
advertise it as working.

## Storage and privacy

Set `DOCUMENT_ATTACHMENTS_DISK=r2` in production and configure the R2 bucket
without public access. The R2 disk has private visibility and no public URL;
attachments are delivered only through Laravel's authenticated,
document-authorized download endpoint. Never generate public object URLs or
expose storage paths. Render's local filesystem is ephemeral and is not a
production attachment store.

## Readiness and operational notes

`/api/v1/health` returns a generic readiness status and performs a lightweight
database query. It does not disclose database details and does not check the
SMTP provider, queue worker, or R2 credentials. Keep `APP_DEBUG=false`, use
HTTPS for both services, restrict database network access where supported, and
rotate credentials through the providers' secret managers.
