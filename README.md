# Likindy AI Office — UI Starter

Next.js frontend for Likindy AI Office, including the authenticated Documents workspace.

## Requirements
- Node.js 20.9+
- npm

## Run locally
```bash
npm install
npm run dev
```
Open http://localhost:3000

## Laravel API connection

The browser uses same-origin `/api/*` and `/sanctum/*` paths. Next.js rewrites
these paths to Laravel, so cookies and CSRF requests stay on the frontend origin.
Locally the rewrite target defaults to `http://localhost:8000`; when needed,
set `LARAVEL_BACKEND_URL` in `.env.local`. Production builds require that
server-only variable. Do not set `NEXT_PUBLIC_API_URL`.

Authentication uses Sanctum's first-party session cookies and CSRF-cookie flow.
Session credentials are not stored in browser storage. Sign in with an existing,
verified backend account; registration and password reset remain unavailable
from this frontend.

## Documents integration

The Documents workspace uses the authenticated, workspace-scoped Laravel API
for document search, filters, sorting, pagination, editing, sharing, version
history, private attachments, and soft-delete/restore. Select a workspace in
the sidebar when the account belongs to more than one. Existing documents in
the browser's previous local-only library are left untouched and are not
silently uploaded.

The backend currently has restore-by-ID but no endpoint for listing all
soft-deleted documents. The frontend therefore offers restore for the most
recently deleted document during the current browser session; it does not
present an incomplete trash list.

## Validation

```bash
npm run lint
npm run build
```

See [DEPLOYMENT.md](./DEPLOYMENT.md) for the Vercel, Render, Supabase PostgreSQL,
and private Cloudflare R2 deployment procedure.
