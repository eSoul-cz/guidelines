---
title: Deployment and runtime
order: 140
summary: Jenkins delivery of a static (or Node) Astro site, build arguments, preview protection, proxy and headers.
---
# Project guidelines - Deployment and runtime

An Astro project has no `lsrPipeline`: its `Jenkinsfile` composes the shared libraries (`testing`, `dockerHelpers`, `deploy`) itself, with the `Checks` stage first ([tools.md](tools.md#jenkins-checks-stage)). A shared `astroCmsPipeline` for the Astro frontend with a Laravel/Filament CMS backend is planned. The hosting choice is recorded in the project's decision record.

## Delivery paths

### Docker image to Komodo

Used by archetypes `A-static` with a PHP API and `B-admin`; archetype `D-static-api` uses it when its hosting decision says so.

```text
push to main
  └─ Jenkins
       ├─ Checks stage
       ├─ dockerBuildMultiArch → rg.fr-par.scw.cloud/app-images/<app>:latest (+ Git tags)
       └─ deployKomodoStack    → the project's Komodo stack
```

- The `Dockerfile` is multi-stage. The build stage runs `pnpm install --frozen-lockfile` and `pnpm build` (it needs network: the Fonts API downloads fonts). The runtime stage holds only what runs: the built site (served by nginx) and, where it exists, the server code or the PHP API with its production dependencies.
- `.dockerignore` keeps host `node_modules`, `.env`, runtime data, `docs/`, source photos and tests out of the build context. Native modules are installed inside the build, for the target platform.
- The container runs as an unprivileged user under an init process; an entrypoint MAY start as root only to hand persistent directories to that user.
- A static site served by a separate nginx container is copied into a shared volume on **every** start, so a new image never leaves the old site in the volume.
- Migrations, seeds and settings validation run before the server listens; a failure exits the container instead of serving errors. The server stops gracefully on `SIGTERM`.
- Every image has a healthcheck. Persistent state (database, uploads, form backups, logs) lives in named volumes listed in `README.md`, with the backup and restore procedure.

### Static build to rsync

Used by archetype `C-headless` and by static sites without a PHP API.

A push to `main`, or the publish button in the backend, starts the Jenkins job: install, the `Checks` stage (which includes `pnpm build` and `pnpm verify`), then a deployment of `dist/` to the server's release directory (`deployStaticSite`). A failed build leaves the previous version live; releases are kept so a rollback re-points the current release. HTML is served `no-cache`; hashed assets (`/_astro/*` and the media path) get a long, immutable cache.

### Publish flow (archetype `C-headless`)

- The build agent fetches the export from the backend over its public URL, so **`CMS_API_ALLOWED_IPS` of the backend MUST contain the address of the build agent**. A changed agent address makes the export answer 403 and blocks every publish until the list is updated; record the address in `README.md`.
- A publish that has no result after **`CMS_PUBLISH_TIMEOUT`** minutes (default 30) is marked failed in the backend, so the publish button cannot stay blocked. The Jenkins callback to the backend is best effort and never fails the build; a late callback is still recorded.
- Concurrent builds are disabled: two publishes would overwrite each other's release.
- In CI the build fails when the export is unreachable or invalid; it never falls back to a fixture or to old data ([content.md](content.md#c-headless-edited-in-a-separate-backend)).

## Build arguments

A static build bakes public values in at build time, so they are passed as build arguments from the `Jenkinsfile`, never read from a runtime `.env`. The process environment wins over a local `.env`, so Jenkins needs no file:

| Build argument      | Source        | Meaning                                                       |
| ------------------- | ------------- | ------------------------------------------------------------- |
| `SITE_URL`          | `Jenkinsfile` | Canonical origin (feeds `site` in `astro.config.mjs`)         |
| `PUBLIC_NOINDEX`    | `Jenkinsfile` | `true` while the preview is protected                         |
| `PUBLIC_<NAME>`     | `Jenkinsfile` | Any other public value (analytics id, form endpoint URL); documented in `.env.example` |

- An empty analytics or consent id means the script is not included at all.
- Secrets (SMTP, database, captcha secret, API tokens) are **never** build arguments and never baked into an image. The container receives them at runtime from the Komodo stack ([security-and-data.md](security-and-data.md#secrets)).
- `.env.example` lists every runtime variable with a placeholder.

## Preview protection

Until launch, the deployed site is a protected preview:

- **HTTP basic auth** in the web server, with a throwaway password kept in the repository on purpose. It is a barrier against accidental visitors, not a secret. `robots.txt` stays reachable without auth.
- **`noindex`**: `PUBLIC_NOINDEX=true` puts `noindex` on every page and `robots.txt` disallows everything.
- The preview URL is recorded in `README.md`.

Switching protection off is a launch step in the project's launch checklist: remove basic auth, set `PUBLIC_NOINDEX=false`, allow crawling in `robots.txt`, deploy, then verify headers, sitemap and indexability. Nothing else changes in the same deploy.

## Reverse proxy and headers

- TLS ends at the reverse proxy; the site is HTTPS-only with HSTS, and `www` and non-`www` redirect to the canonical host.
- The proxy sends `X-Forwarded-Host`, `X-Forwarded-Proto: https` and an `X-Forwarded-For` that it **replaces**, not appends; an SSR site trusts forwarded headers only for its own domains (`security.allowedDomains`). Rate limits depend on the client address being right.
- The **Content-Security-Policy of a static site comes from Astro's `security.csp`** at build time (a `<meta>` tag per page; the build computes the hashes of inline scripts and styles, so no `unsafe-inline` is needed). `frame-ancestors` does not work in `<meta>`.
- nginx sets the other response headers on every response, static files included: `X-Frame-Options` (or `frame-ancestors` in a header CSP), `X-Content-Type-Options: nosniff`, `Referrer-Policy` and HSTS. In nginx, a `location` with its own `add_header` repeats the shared header snippet, because nginx drops inherited headers.
- On a site that shares one domain with its backend (`C-headless`, `D-static-api`), only the backend's own paths are routed to it; everything else is the static site. A runtime API call from the browser needs those paths in `connect-src` of `security.csp`.
- On `C-headless`, nginx serves the media under `/_cms/` from the backend's public volume on the same host. The site makes no runtime call to the backend, but its media depend on that volume being readable by nginx.

## Forms in production

- The form's captcha keys are registered for the production domain, SMTP is configured from the stack, and a test submission is sent and received after every launch-relevant deploy.
- The retention period for stored submissions is recorded in the project's decisions ([security-and-data.md](security-and-data.md#personal-and-client-data)); submissions kept in the database, the backup table or the last-resort file are all covered by it.
