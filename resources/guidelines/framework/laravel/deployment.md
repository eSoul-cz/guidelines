---
title: Deployment and runtime
order: 140
summary: Laravel runtime detail of the deployment scheme - php-fpm images, local development stack, healthcheck, proxy buffers, Jenkins laravelPipeline, migrations, file storage and configuration.
---
# Project guidelines - Deployment and runtime

Laravel runtime detail added to the deployment scheme: how the application is built into images, how it runs and what the pipeline checks. The application runs only in eSoul's Docker images; building, releasing, stacks, shared services and secrets follow the scheme.

## Images

- Every image starts from the shared eSoul base image `rg.fr-par.scw.cloud/esoul-starters/php-fpm-laravel:8.5`, which already provides the common PHP extensions. A missing extension is added to the base image, not installed ad hoc in a project Dockerfile (a temporary exception names the reason and the issue in the Dockerfile).
- **PHP runs as php-fpm and is reached through FastCGI on port 9000.** The app image contains no web server and the stack has no nginx container: the host NGINX serves the files of `public/` and passes every other request to php-fpm ([Reverse proxy and TLS](#reverse-proxy-and-tls)). Octane (Swoole, RoadRunner, FrankenPHP) is not used and MUST NOT be added; code may rely on one process per request.
- One multi-stage `docker/production/Dockerfile` builds the release images (one target per image); `docker/dev/Dockerfile` builds the development image. **The Composer packages are their own stage**, rebuilt only when `composer.json` or `composer.lock` change.

| Image | Runs | Never runs |
|---|---|---|
| App image | php-fpm under Supervisor, with the queue worker and the scheduler switched on by environment | nginx |
| Worker image (optional target) | only queue workers | php-fpm, the scheduler, migrations |

- Environment variables decide what runs in a container (`RUN_MIGRATIONS`, `RUN_SCHEDULER`, `RUN_QUEUE_WORKER`). **Exactly one container in a stack runs the scheduler.**
- A queue worker needs the `pcntl` extension to enforce `--timeout`; without it a hanging job blocks its worker for good. Check it with `php -m` and have it added to the base image.
- Large design and source material kept in Git LFS stays outside the Docker build context (`.dockerignore`); the pipeline sets `GIT_LFS_SKIP_SMUDGE=1`.

## Health endpoint

- The application exposes Laravel's built-in `/up` endpoint, which reports that the framework boots; it does not check dependencies. The image's `HEALTHCHECK` requests it.

## Reverse proxy and TLS

- php-fpm listens on port 9000. The host NGINX serves existing files of `public/` from a copy taken out of the image at deploy time, and a path that is not a file there is a route of the application.
- The trusted-proxies list is the `TRUSTED_PROXIES` setting, **never `*`**.
- Body and buffer sizes match the application's limits. NGINX accepts a request body at least as large as the largest upload the application allows (the manifest's `nginx.client_max_body_size`, in step with PHP's `upload_max_filesize` and `post_max_size` and the validation limit), and FastCGI response headers of at least 32 KB (`fastcgi_buffer_size 32k; fastcgi_buffers 16 32k;` is the minimum; the rendered sites use 64k for the first buffer). The preload `Link` header of a large page is the reason ([frontend.md](frontend.md#vite-and-bundling)).

## Local development

Local development always runs in Docker; running the application on the host (`artisan serve`, Sail, a host database) is not supported. `docker-compose.yml` starts:

| Service | Purpose |
|---|---|
| `laravel` | php-fpm, queue worker, scheduler and Vite under Supervisor; the repository is bind-mounted |
| `nginx` | Serves the application on `${APP_PORT:-8080}` (local only) |
| database service | The production engine, named volume, health-checked |
| `mailpit` | Catches all outgoing e-mail. `.env.example` points the mail settings at it |
| further services | Whatever the application needs besides: Valkey, a search node, an S3-compatible container |

`.env.example` points the application at these services. Tests never use the development database ([testing.md](testing.md#database-in-tests)).

## Pipeline

The `Jenkinsfile` loads eSoul's shared Jenkins libraries (`dockerHelpers`, `testing`, `deploy` and `pipelines`) and calls the shared `laravelPipeline` step as its only statement; only what differs from the step's defaults is configured (image names and targets, prepare command, extra checks, deploy rule, timeouts). The step reports the required `Jenkins` check on every branch and pull request. Its stages, in order:

1. **Checkout**, **Docker Login**, **Pull testing images**.
2. **Prepare**: `composer install`, an `.env` from `.env.example` with a fresh key, config cleared; a frontend layer adds its generation steps.
3. **Static checks**, in parallel: PHP static checks (Composer validation, Larastan, `pint --test`), **Dependency audit** (`composer audit` and, with a JavaScript frontend, `pnpm audit --audit-level critical`; the build fails on critical advisories) and, with a JavaScript frontend, JS static checks (install and the read-only checks of the frontend layer's tools guideline).
4. **Run Tests**, only after every static check passed, in parallel: **PHP Tests** (PHPUnit in parallel; on SQLite `:memory:` unless the pipeline's `testDatabase` option starts a throwaway database of the production engine), with a JavaScript frontend **JS tests** (the project's tests and the production build) and, on pull requests, **Test Docker Build** of every release image.
5. **Build and push image** on a branch with a deploy rule (`main` by default): multi-architecture images tagged `latest` and with any Git tag on the commit, pushed to `rg.fr-par.scw.cloud/app-images/`.
6. **Smoke test** (optional, the pipeline's `smoke` option, off by default because Jenkins capacity is limited): a Compose smoke test of the pushed images by their immutable reference, after `Build and push image` and before `Deploy`. The stage does not exist without the option; where it exists, a failure stops `Deploy`.
7. **Deploy**: the rule's Komodo stack is redeployed. A rule without a stack only publishes the images.

Keep the stage names stable: the required check is the multibranch job's `Jenkins` status. Mutation testing does not run in CI.

Jenkins is shared by every eSoul project, so build time matters:

- **The pipeline has timeouts.** A hung build is aborted instead of holding an executor until somebody notices: `laravelPipeline`'s `timeoutMinutes` sets the limits (`total`, `testing`, `images`), and a changed value has a comment saying why.
- A newer push to the same pull request aborts its running build.
- Push a coherent unit, and push review fixes together once they pass locally; every push starts a full build. A red build is reproduced locally before its fix is pushed; never push to see whether CI agrees.
- When the multibranch job discovers both branches and pull requests, a commit with an open pull request can be built twice; building each commit once is a job setting, not a reason to skip stages in one of the two jobs.
- Integration tests follow [testing.md](testing.md#keeping-the-integration-run-short).
- The release-image test build is the slowest stage; it runs on pull requests. It reuses the layer cache by default; `testDockerBuild: [noCache: true]` in the pipeline configuration builds it without the cache, for a project that needs a clean build or while a cache problem is ruled out.
- Paratest processes (and the worker count of a JS test runner, where there is one) match the Jenkins agent; a value of 1 has a comment saying why.
- A separate scheduled Jenkins job runs what the push build does not (the `sandbox`, `quarantine` and `db-engine` groups on the production engine, [testing.md](testing.md#test-groups)). It does not report the required check, and every failure becomes an issue.

## Environments and releases

- Each environment has its own `APP_KEY` and OAuth redirects, next to the stack, database, buckets and secrets the scheme gives it.

## Migrations and seeders

- Migrations run once per deployment in the entrypoint of the container with `RUN_MIGRATIONS=true` (`php artisan migrate --force`); worker containers never migrate. They are forward-only and safe to run while the previous release still serves requests ([architecture.md](architecture.md#persistence)).
- A production deployment that contains a migration is preceded by an on-demand database backup and, where the project has a staging stack, has already run there; whoever deploys reads the migration output in the log.
- The production seeder creates only what every environment needs. Every seeder it calls is idempotent and create-only: it never overwrites rows staff may have changed, and none creates an account ([security-and-data.md](security-and-data.md#sessions-and-accounts)). Local-only data belongs in a demo seeder.
- Data corrections ship as migrations or as console commands that dispatch commands, never as manual SQL ([security-and-data.md](security-and-data.md#production-access)).

## File storage

- Private files (uploads, documents) live on a private disk and reach a browser only through a **short-lived signed URL** or through a controller that authorizes against the owning record and streams the file. A public URL to a private file does not exist.
- **Code never names a disk driver.** Command handlers reach the disk through a contract in `App\Application\Contract`, implemented in `App\Infrastructure`; the driver is configuration (`config/filesystems.php`).
- Every response that serves a private file sends `X-Content-Type-Options: nosniff` and `Cache-Control: private, no-store`.
- The type and size limits of uploads are configuration, validated in the form request ([requests-and-validation.md](requests-and-validation.md#validation-rules)); the proxy and PHP accept at least the largest upload ([Reverse proxy and TLS](#reverse-proxy-and-tls)).

## Configuration and secrets

- The image carries `.env.example` as its `.env`, so a variable left out of the stack silently falls back to the local default; this is why a new variable is named in the pull request's deploy notes. The stack sets at least `APP_ENV`, `APP_DEBUG=false`, `APP_KEY`, `APP_URL`, the database, mail and storage settings and **`SESSION_SECURE_COOKIE=true` in every deployed stack**; local `.env.example` does not set it, because local development runs over plain HTTP. The application clock (`app.timezone`) stays UTC.
- Production caches configuration, routes and views in the container start script (`config:cache`, `route:cache`, `view:cache`); code reads configuration only through `config()`, never `env()` outside `config/`.
