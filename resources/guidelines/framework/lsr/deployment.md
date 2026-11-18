---
title: Deployment and runtime
order: 140
summary: How an LSR application is built into a RoadRunner image, delivered by Jenkins and run as a Komodo stack, including the health endpoint.
---
# Project guidelines - Deployment and runtime

Every merge to the main branch goes through Jenkins: checks ([tools.md](tools.md#read-only-verification)), image build, smoke check and deployment.

## Delivery path

The template `Jenkinsfile` loads the shared libraries `dockerHelpers`, `testing`, `deploy` and `pipelines` and calls `lsrPipeline` with:

- a prepare command (`php bin/console list --no-interaction`);
- PHP checks: `composer phpstan:ci` and `composer test:parallel`, with `temp/tools` mounted as the tool cache;
- frontend checks: `pnpm run eslint --quiet` and `pnpm run format:check`;
- a smoke check that starts the production image from `docker-compose.ci.yml` and runs the `http-smoke` service against it;
- the production-target image build from `docker/Dockerfile`;
- deployment of the image to the project's Komodo stack from the `main` and `master` branches.

`lsrPipeline` is **planned**: the shared `pipelines` library has `laravelPipeline` only, so the template `Jenkinsfile` does not run until `lsrPipeline` exists. Its parameters mirror `laravelPipeline` and the `smoke` key is a proposal. Until then a project composes the same steps from the shared `testing`, `dockerHelpers` and `deploy` libraries.

- The `Jenkinsfile` stays a small, readable pipeline: check, build, publish and deploy only. Release certification, evidence capture and other orchestration do not belong in it.

## Health endpoint

- The application serves `GET /up`, a route named `health`. It checks the database connection and returns `200` when the application is ready and `503` when it is not.
- The route carries no session, CSRF or Inertia middleware and does not depend on the session, so a broken session store cannot hide a healthy application or the other way round.
- The `Dockerfile` declares a `HEALTHCHECK` that requests `/up`. Compose files only adjust its timing; the check itself is not redefined per environment.

## Image

- The runtime image is based on `rg.fr-par.scw.cloud/esoul-starters/php-cli-roadrunner:8.5`; the application runs as a RoadRunner process, not behind php-fpm.
- The `Dockerfile` is multi-stage: Composer dependencies (`composer install --no-dev --optimize-autoloader`), a Node 26 stage (pnpm from `packageManager` installed with npm, `pnpm install --frozen-lockfile`, `pnpm run build`), a `development` target and a `production` target (the last stage, so the default build target).
- The production image holds only what runs: `bin`, `config`, `include`, `routes`, `src`, `templates`, `.rr.yaml`, `psr-worker.php`, `vendor` (without dev dependencies) and the built `build/`. Tests, frontend sources, `node_modules` and tooling configuration are not in it.
- Only the compiled `build/assets` directory is public (linked into RoadRunner's static directory); the Vite manifest and the application tree stay private.
- `.dockerignore` keeps host `node_modules`, `vendor`, `temp`, `logs`, `.env` and private configuration out of the build context.
- Development and production use the same entrypoint structure: a start script runs `/prepare-runtime` (writable `temp/`, `logs/` and upload directories, generated-cache cleanup, ORM, Latte and route caches, database installation) and then `rr serve -c .rr.yaml`. Production does not run `composer install` or `pnpm install` at start.

## RoadRunner configuration

`.rr.yaml` configures the HTTP pool, the jobs pool and the scheduler service. These values are part of the application contract:

- `server.command` is the PSR-7 worker entrypoint (`php psr-worker.php`).
- The HTTP pool sets `num_workers`, `max_jobs` and `max_worker_memory` so workers are recycled before they leak; correctness MUST NOT rely on that ([architecture.md](architecture.md#long-running-worker-state)).
- The jobs pool consumes the queue named in the DI configuration (`config/di/extensions/roadrunner.neon`); queue and RPC names MUST agree between `.rr.yaml`, the DI configuration and the code.
- Static files go through RoadRunner's `static` middleware with an extension allow-list; the compiled Vite assets are publicly readable, nothing else under the application root is.
- The `status` (`2114`) and `metrics` (`2112`) endpoints bind `0.0.0.0` inside the container: the compose and stack definitions MUST NOT publish those ports and the reverse proxy MUST NOT route to them, so they stay reachable on the internal network only.
- Server-side rendering is not part of the default runtime (no Node service in `.rr.yaml`, no Node in the production image); see [frontend.md](frontend.md#inertia-on-the-server) before enabling it.

## Environment and secrets

- Runtime configuration comes from environment variables and uncommitted private configuration mounted by the Komodo stack; `.env.example` names every variable ([security and data](security-and-data.md)).
- The application reads these variables: `APP_NAME`, `APP_DEBUG` (`false` outside development), `UPLOAD_DIR`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_COLLATE`, `DB_LAZY`, `DB_DRIVER` and `RR_LOG_LEVEL`. A new variable is added to `.env.example` in the same pull request.
- Environment-dependent values are read in `config/di/*.php|neon`, not scattered through the code.

## Komodo stack

- The application is deployed as a Komodo stack (`deployKomodoStack`) that pulls the image tag built by Jenkins together with its backing services; the template stack has a MariaDB database, and Redis only when the project chose it ([architecture.md](architecture.md#caching)).
- Persistent state (uploads, private data) lives in named volumes listed in the repository documentation together with the backup and restore procedure; the container filesystem is disposable.
- Database installation and migrations (`php bin/console app:install`) run in the start script before the HTTP pool accepts traffic; a failure exits the container instead of serving errors.
- A new release is verified after deployment by the container health state (`GET /up`); rolling back means deploying the previous image tag.

## Reverse proxy

- TLS ends at the reverse proxy. The proxy sends `X-Forwarded-Host`, `X-Forwarded-Proto` and an `X-Forwarded-For` that it replaces; the application trusts forwarded headers only from the proxy (the browser CSRF middleware relies on them).
- Security headers (CSP, `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `frame-ancestors`) are set on every response, static files included.
