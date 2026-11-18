---
title: Deployment and runtime
order: 140
summary: How an LSR application is built into a RoadRunner image, delivered by Jenkins and run as a Komodo stack.
---
# Project guidelines - Deployment and runtime

Every merge to the main branch goes through Jenkins: checks ([tools.md](tools.md#read-only-verification)), image build, publish and deployment. Agents never deploy, push images or change Jenkins credentials.

## Delivery path

```text
push to main
  └─ Jenkins (shared libraries: testing, dockerHelpers, deploy)
       ├─ PHP checks      composer phpstan / cs / test
       ├─ frontend checks pnpm eslint / format:check / tsc / test:fe / build
       ├─ image build     rg.fr-par.scw.cloud/app-images/<app>:latest (+ Git tags)
       ├─ smoke checks    production container answers its health endpoint
       └─ deploy          Komodo stack
```

- The pipeline is composed from the shared Jenkins libraries `testing`, `dockerHelpers` and `deploy`. A dedicated `lsrPipeline` in the shared `pipelines` library is planned; until it exists the `Jenkinsfile` composes the same stages by hand and MUST keep the stage order above.
- The `Jenkinsfile` stays a small, readable pipeline. Release certification, evidence capture and orchestration beyond build, check, publish and deploy do not belong in it.
- Secrets reach Jenkins only through `withCredentials`; they are never echoed, committed or baked into an image.

## Image

- The runtime image is based on `rg.fr-par.scw.cloud/esoul-starters/php-cli-roadrunner:8.5`; the application runs as a RoadRunner process, not behind php-fpm.
- The `Dockerfile` is multi-stage: Composer dependencies (`composer install --no-dev --optimize-autoloader` in the production stage), a Node stage with `pnpm install --frozen-lockfile` and `pnpm build` (Node 24, pnpm 10.20.0), a `development` target and a `production` target. The production stage copies only what runs: the application, `vendor`, and the built `build/` directory.
- `.dockerignore` keeps host `node_modules`, `vendor`, `temp`, `logs`, `.env` and private configuration out of the build context.
- Development and production use the same entrypoint structure: a start script prepares the runtime (writable `temp/`, `logs/`, uploads; DI, Latte, ORM and route caches) and then runs `rr serve -c .rr.yaml`. Production does not run `composer install` or `pnpm install` at start.
- The container runs a healthcheck against the application's health endpoint (a route without database or session dependencies) and stops gracefully on `SIGTERM`.

## RoadRunner configuration

`.rr.yaml` configures the HTTP pool, the jobs pool and the scheduler service. These values are part of the application contract:

- `server.command` is the PSR-7 worker entrypoint (`php psr-worker.php`).
- The HTTP pool sets `num_workers`, `max_jobs` and `max_worker_memory` so workers are recycled before they leak; correctness MUST NOT rely on that ([architecture.md](architecture.md#long-running-worker-state)).
- The jobs pool consumes the queue named in the DI configuration (`config/di/extensions/roadrunner.neon`); queue and RPC names MUST agree between `.rr.yaml`, the DI configuration and the code.
- Static files either go through RoadRunner's `static` middleware with an extension allow-list or are served by the reverse proxy; the Vite output (`build/`) is publicly readable, nothing else under the application root is.
- Status and metrics endpoints are not exposed outside the internal network.
- Optional server-side rendering runs as a RoadRunner-supervised Node service (`node build-ssr/ssr.js`) bound to localhost, built with a second Vite build. It is enabled only by decision; error pages render with SSR disabled so an SSR failure never hides the real error.

## Environment and secrets

- Runtime configuration comes from environment variables and uncommitted private configuration mounted by the Komodo stack. `.env.example` lists every variable with a placeholder value; real values are never committed.
- Environment-dependent values are read in `config/di/*.php|neon`, not scattered through the code.
- Database credentials, Redis, mail, third-party API keys and signing secrets are runtime secrets. Public build-time values are limited to what the browser is allowed to see.

## Komodo stack

- The application is deployed as a Komodo stack (`deployKomodoStack`) that pulls the image tag built by Jenkins together with its backing services (database, Redis).
- Persistent state (uploads, private data) lives in named volumes listed in the repository documentation together with the backup and restore procedure; the container filesystem is disposable.
- Database installation and migrations (`php bin/console install`) run as an explicit step of the deployment or the start script before the HTTP pool accepts traffic; a failure exits the container instead of serving errors.
- A new release is verified after deployment by the production smoke check; rolling back means deploying the previous image tag.

## Reverse proxy

- TLS ends at the reverse proxy. The proxy sends `X-Forwarded-Host`, `X-Forwarded-Proto` and an `X-Forwarded-For` that it replaces; the application trusts forwarded headers only from the proxy.
- Security headers (CSP, `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `frame-ancestors`) are set on every response, static files included.
