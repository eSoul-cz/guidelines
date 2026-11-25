---
title: Deployment and runtime
order: 140
summary: How an LSR application is built into a RoadRunner image, delivered by Jenkins and run as a Komodo stack behind FastCGI, including the health endpoint.
---
# Project guidelines - Deployment and runtime

LSR runtime detail added to the deployment scheme: the RoadRunner image, the pipeline the template configures and what the application expects from its stack. Every merge to a branch with a deploy rule goes through Jenkins: checks ([tools.md](tools.md#read-only-verification)), image build, optional smoke check and deployment.

## Delivery path

The template `Jenkinsfile` loads the shared libraries `dockerHelpers`, `testing`, `deploy` and `pipelines` and calls `lsrPipeline` with:

- a prepare command (`php bin/console list --no-interaction`);
- PHP checks: `composer phpstan:ci`, `composer cs`, `composer test:parallel` and `composer audit --locked` (any advisory fails), with `temp/tools` mounted as the tool cache;
- frontend checks: `pnpm run eslint`, `pnpm run format:check`, `pnpm run tsc`, `pnpm run test`, `pnpm audit` (any advisory fails) and `pnpm run build`;
- the `Guidelines check` stage: `esoul-guidelines check` from the project's own `vendor/` ([tools.md](tools.md#guidelines)); it runs when the project has `esoul-guidelines.lock`, and `guidelinesCheck: false` switches it off;
- a smoke check that starts the production image from `docker-compose.ci.yml` and runs the `http-smoke` service against it (optional, as in the scheme; with `lsrPipeline` it also runs on pull requests, on the image of the test build);
- the production-target image build from `docker/Dockerfile`;
- deployment of the image to the project's Komodo stack by the project's deploy rules (`main` and `master` in the template; see [Environments and releases](#environments-and-releases)).

The step, its stages and its parameters are documented in `pipelines/vars/lsrPipeline.txt` of the shared libraries repository (eSoul-cz/jenkins-libraries).

- The `Jenkinsfile` stays a small, readable pipeline: check, build, publish and deploy only. Release certification, evidence capture and other orchestration do not belong in it.

## Health endpoint

- The route is named `health` and also checks the database connection: it answers `503` while the database is unreachable.
- The route carries no session, CSRF or Inertia middleware, so a broken session store cannot hide a healthy application or the other way round.
- The `Dockerfile` declares the `HEALTHCHECK` that requests `/up` on the HTTP port (8001). Compose files only adjust its timing; the check itself is not redefined per environment.

## Image

- The runtime image is based on `rg.fr-par.scw.cloud/esoul-starters/php-cli-roadrunner:8.5`; the application runs as a RoadRunner process, not behind php-fpm.
- The `Dockerfile` is multi-stage: Composer dependencies (`composer install --no-dev --optimize-autoloader`), a Node stage (pnpm from `packageManager` installed with npm, `pnpm install --frozen-lockfile`, `pnpm run build`), a `development` target and a `production` target (the last stage, so the default build target).
- The production image holds only what runs: `bin`, `config`, `include`, `routes`, `src`, `templates`, `.rr.yaml`, `psr-worker.php`, `vendor` (without dev dependencies) and the built `build/`. Tests, frontend sources, `node_modules` and tooling configuration are not in it.
- Only the compiled `build/assets` directory is public; the Vite manifest and the application tree stay private. In a deployed stack the deploy hook copies `/app/build/assets` out of the image into the run directory's `build/assets/`, which the host NGINX serves under `/build/assets/`; nothing else of the run directory is reachable.
- `.dockerignore` keeps host `node_modules`, `vendor`, `temp`, `logs`, `.env` and private configuration out of the build context.
- Development and production use the same entrypoint structure: a start script runs `/prepare-runtime` (writable `temp/`, `logs/` and upload directories, generated-cache cleanup, ORM, Latte and route caches, database installation) and then `rr serve -c .rr.yaml`. Production does not run `composer install` or `pnpm install` at start.

## RoadRunner configuration

`.rr.yaml` configures the HTTP pool, the jobs pool and the scheduler service. These values are part of the application contract:

- `server.command` is the PSR-7 worker entrypoint (`php psr-worker.php`).
- **FastCGI.** The host NGINX reaches the application through FastCGI on port 9000 (`fcgi.address`); the HTTP server on port 8001 serves local development, the smoke check and the container healthcheck. A deployed stack's `.rr.yaml` is a config file in the run directory, mounted over the image's. **Target.** The template's `.rr.yaml` configures HTTP only; the `fcgi` listener is added in the stack's file until the template has it.
- The HTTP pool sets `num_workers`, `max_jobs` and `max_worker_memory` so workers are recycled before they leak; correctness MUST NOT rely on that ([architecture.md](architecture.md#long-running-worker-state)).
- The jobs pool consumes the queue named in the DI configuration (`config/di/extensions/roadrunner.neon`); queue and RPC names MUST agree between `.rr.yaml`, the DI configuration and the code.
- Static files go through RoadRunner's `static` middleware with an extension allow-list; the compiled Vite assets are publicly readable, nothing else under the application root is.
- The `status` (`2114`) and `metrics` (`2112`) endpoints bind `0.0.0.0` inside the container: the stack publishes no ports and the proxy MUST NOT route to them, so they stay reachable on the internal network only.
- Server-side rendering is not part of the default runtime (no Node service in `.rr.yaml`, no Node in the production image); see [frontend.md](frontend.md#inertia-on-the-server) before enabling it.

## Configuration and secrets

- Runtime configuration comes from environment variables and from uncommitted private configuration files in the run directory (`services.local.neon`, `.rr.yaml`), which the stack mounts; `.env.example` names every variable ([security and data](security-and-data.md)).
- The application reads these variables: `APP_NAME`, `APP_DEBUG` (`false` outside development), `UPLOAD_DIR`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_COLLATE`, `DB_LAZY`, `DB_DRIVER` and `RR_LOG_LEVEL`. A new variable is added to `.env.example` in the same pull request.
- Environment-dependent values are read in `config/di/*.php|neon`, not scattered through the code.

## Stack runtime

- The database is the shared MariaDB of the host (`DB_HOST=host.docker.internal`, one login per database); it is not a service of the stack. A cache, when the project chose one ([architecture.md](architecture.md#caching)), is the stack's own Valkey service; the Redis-compatible `redis` alias resolves to it.
- Persistent state (uploads, private data, logs, `temp/` and the `boltdb` jobs queue file) is bind-mounted from the stack's run directory (`./upload`, `./logs`, `./temp`, next to `.env`, `services.local.neon` and `.rr.yaml`); the container filesystem is disposable. The jobs queue file lives in `/app/temp/queue/` (`./temp/queue/` in the run directory) and is excluded from `cache:clean` and from the generated-cache cleanup of `/prepare-runtime` ([Background work](architecture.md#background-work)). The repository documentation lists the persistent paths; the infrastructure's backups cover the run directory.
- Database installation and migrations (`php bin/console app:install`, never with `--fresh`) run in the start script (inside `/prepare-runtime`) before the HTTP pool accepts traffic, as the [production start entrypoint](#the-production-start-entrypoint) rules say: **a failure does not stop the container.** The script logs `app:install failed` on standard error and RoadRunner starts anyway; the person monitoring the deployment reads it. `app:install` creates and alters tables and seeds nothing, and the start script never runs a seeder; a project that has one runs it by hand in the container. It also drops indexes that `migrations.neon` does not declare, so every index is declared there like the rest of the schema; an index added by hand disappears on the next deployment. A heavy data task (a recalculation, a sync, an import) is started in the background after the server, not in `/prepare-runtime`.
- A new release is watched by the person who deployed it ([Monitoring a deployment](#monitoring-a-deployment)); the container health state (`GET /up`) is the first check. Rolling back is the pipeline's `previous-<stack>` image ([Rollback](#environments-and-releases)).

## Reverse proxy and TLS

- The host NGINX passes every path except `/build/assets/` to the application over FastCGI, with the client address, scheme, host and port that NGINX determined. The application trusts these values only because the connection comes from the proxy (the browser CSRF middleware relies on them). Plain HTTP on port 8001 reaches the application only in the specific cases the manifest names.
- Security headers (CSP, `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `frame-ancestors`) are set on every response, static files included.
