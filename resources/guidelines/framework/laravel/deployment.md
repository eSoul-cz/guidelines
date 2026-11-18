---
title: Deployment and runtime
order: 140
summary: Docker images, php-fpm with nginx in its own container, Jenkins laravelPipeline, Komodo stacks, environments, migrations, file storage, secrets and healthchecks.
---
# Project guidelines - Deployment and runtime

The application runs only in eSoul's Docker images: Jenkins builds and tests them, the Scaleway
registry stores them, Komodo deploys them. **Laravel Cloud is not used**, and neither are Forge
and Sail-based production deployments. Any Laravel Cloud text in generated framework
documentation (for example the Laravel Boost block in `AGENTS.md`) does not apply to this
project; ignore generic Laravel guidance that suggests it.

## Images

- Every image starts from the shared eSoul base image
  `rg.fr-par.scw.cloud/esoul-starters/php-fpm-laravel:8.5`, which already provides the common PHP
  extensions. A missing extension is added to the base image, not installed ad hoc in a project
  Dockerfile (a temporary exception names the reason and the issue in the Dockerfile).
- **PHP runs as php-fpm; nginx runs in its own container.** The app image contains no web
  server. Octane (Swoole, RoadRunner, FrankenPHP) is not used and MUST NOT be added; code may rely
  on one process per request.
- One multi-stage `docker/production/Dockerfile` builds the release images (one target per image);
  `docker/dev/Dockerfile` builds the development image. **The Composer packages are their own
  stage**, rebuilt only when `composer.json` or `composer.lock` change.

| Image | Runs | Never runs |
|---|---|---|
| App image | php-fpm under Supervisor, with the queue worker and the scheduler switched on by environment | nginx |
| Worker image (optional target) | only queue workers | php-fpm, the scheduler, migrations |

- Environment variables decide what runs in a container (`RUN_MIGRATIONS`, `RUN_SCHEDULER`,
  `RUN_QUEUE_WORKER`). **Exactly one container in a stack runs the scheduler.**
- A queue worker needs the `pcntl` extension to enforce `--timeout`; without it a hanging job
  blocks its worker for good. Check it with `php -m` and have it added to the base image.
- Every process logs to the container's output, so `docker logs` and Komodo show it. A log line
  names records by id and carries no personal data or amounts.
- **Healthchecks.** Each application image has a Docker `HEALTHCHECK`; Supervisor restarts a
  crashed process, the healthcheck reports one that stays down. The application exposes Laravel's
  `/up` endpoint and an external check calls it.
- Large design and source material kept in Git LFS stays outside the Docker build context
  (`.dockerignore`); the pipeline sets `GIT_LFS_SKIP_SMUDGE=1`.

### Proxy

- The application trusts only the proxies listed explicitly in its trusted-proxies setting
  (`TRUSTED_PROXIES`), **never `*`**, and is reachable only through them. The proxy sets `Host` and
  the `X-Forwarded-*` headers itself and drops values a client sent.
- Proxy body and buffer sizes match the application's limits. nginx and every proxy in front of
  the application accept a request body at least as large as the largest upload the application
  allows (`client_max_body_size`, in step with PHP's `upload_max_filesize` and `post_max_size` and
  the validation limit), and response headers of at least 32 KB
  (`fastcgi_buffer_size 32k; fastcgi_buffers 16 32k;` in front of php-fpm, `proxy_buffer_size 32k;
  proxy_buffers 16 32k;` in a reverse proxy). The preload `Link` header of a large page is the
  reason ([frontend.md](frontend.md#vite-and-bundling)).

## Local development stack

Local development always runs in Docker; running the application on the host (`artisan serve`,
Sail, a host database) is not supported. `docker-compose.yml` starts:

| Service | Purpose |
|---|---|
| `laravel` | php-fpm, queue worker, scheduler and Vite under Supervisor; the repository is bind-mounted |
| `nginx` | Serves the application on `${APP_PORT:-80}` |
| database service | The production engine, named volume, health-checked |
| `mailpit` | Catches all outgoing e-mail; UI on localhost only. `.env.example` points the mail settings at it |

Every published port of a support service binds to `127.0.0.1`. Credentials come from `.env`,
copied from `.env.example`. Tests never use the development database
([testing.md](testing.md#database-in-tests)).

## Pipeline

The `Jenkinsfile` loads eSoul's shared Jenkins libraries (`dockerHelpers`, `testing`, `deploy`
and `pipelines`) and calls the shared `laravelPipeline` step as its only statement; only what
differs from the step's defaults is configured (image names and targets, prepare command, extra
checks, deploy rule, timeouts). The step reports the required `Jenkins` check on every branch and
pull request. Its stages, in order:

1. **Checkout**, **Docker Login**, **Pull testing images**.
2. **Prepare**: `composer install`, an `.env` from `.env.example` with a fresh key, config
   cleared, `php artisan wayfinder:generate`.
3. **Static checks**, in parallel: PHP static checks (Composer validation, Larastan, `pint
   --test`), **Dependency audit** (`composer audit` and `pnpm audit --audit-level critical`; the
   build fails on critical advisories), JS static checks (install, ESLint, Prettier, TypeScript).
4. **Run Tests**, only after every static check passed, in parallel: **PHP Tests** (PHPUnit in
   parallel; on SQLite `:memory:` unless the pipeline's `testDatabase` option starts a throwaway
   database of the production engine), **JS tests** (Vitest and the production build) and, on pull
   requests, **Test Docker Build** of every release image.
5. **Build and push image** on a branch with a deploy rule (`main` by default): multi-architecture
   images tagged `latest` and with any Git tag on the commit, pushed to
   `rg.fr-par.scw.cloud/app-images/`.
6. **Deploy**: the rule's Komodo stack is redeployed. A rule without a stack only publishes the
   images.

Keep the stage names stable: the required check is the multibranch job's `Jenkins` status.
Mutation testing does not run in CI.

Jenkins is shared by every eSoul project, so build time matters:

- **The pipeline has timeouts.** A hung build is aborted instead of holding an executor until
  somebody notices: `laravelPipeline`'s `timeoutMinutes` sets the limits (`total`, `testing`,
  `images`), and a changed value has a comment saying why.
- A newer push to the same pull request aborts its running build.
- Push a coherent unit, and push review fixes together once they pass locally; every push starts
  a full build. A red build is reproduced locally before its fix is pushed; never push to see
  whether CI agrees.
- When the multibranch job discovers both branches and pull requests, a commit with an open pull
  request can be built twice; building each commit once is a job setting, not a reason to skip
  stages in one of the two jobs.
- Integration tests follow [testing.md](testing.md#keeping-the-integration-run-short).
- The release-image test build is the slowest stage; it runs on pull requests and uses the
  registry layer cache rather than `--no-cache` unless a cache problem is being ruled out.
- Vitest worker count and Paratest processes match the Jenkins agent; a value of 1 has a comment
  saying why.
- A separate scheduled Jenkins job runs what the push build does not (the `sandbox`, `quarantine`
  and `db-engine` groups on the production engine, [testing.md](testing.md#test-groups)). It does
  not report the required check, and every failure becomes an issue.

## Environments

- **Staging is built from `main`.** Every merge to `main` that passes the pipeline is deployed to
  the staging stack as `latest`; a task is accepted there. There is no `staging` branch.
- **Production is deployed deliberately from an image carrying a release tag** that was accepted
  on staging. Production never runs `latest`.
- Each environment has its own stack, database, buckets, `APP_KEY` and OAuth redirects. Staging
  that holds real data is protected like production.

## Migrations and seeders

- Migrations run once per deployment in the entrypoint of the container with
  `RUN_MIGRATIONS=true` (`php artisan migrate --force`); worker containers never migrate. They
  are forward-only and safe to run while the previous release still serves requests
  ([architecture.md](architecture.md#persistence)).
- A production deployment that contains a migration is preceded by an on-demand database backup
  and has already run on staging; the deployer reads the migration output in the log.
- The production seeder creates only what every environment needs. Every seeder it calls is
  idempotent and create-only: it never overwrites rows staff may have changed, and none creates an
  account ([security-and-data.md](security-and-data.md#sessions-and-accounts)). Local-only data
  belongs in a demo seeder.
- Data corrections ship as migrations or as console commands that dispatch commands, never as
  manual SQL ([security-and-data.md](security-and-data.md#production-access)).

## File storage

- Private files (uploads, documents) live on a private disk and reach a browser only through a
  **short-lived signed URL** or through a controller that authorizes against the owning record and
  streams the file. A public URL to a private file does not exist.
- **Code never names a disk driver.** Command handlers reach the disk through a contract in
  `App\Application\Contract`, implemented in `App\Infrastructure`; the driver is configuration
  (`config/filesystems.php`).
- Every response that serves a private file sends `X-Content-Type-Options: nosniff` and
  `Cache-Control: private, no-store`.
- The type and size limits of uploads are configuration, validated in the form request
  ([requests-and-validation.md](requests-and-validation.md#validation-rules)); the proxy and PHP
  accept at least the largest upload ([Proxy](#proxy)).

## Configuration and secrets

- Secrets are never committed. `.env.example` holds only local defaults and no credential;
  `.env` is gitignored. Staging and production credentials are managed in Komodo and the company
  password manager.
- **The stack is defined in esoul-infrastructure**, in `apps/<project>/<project>.yaml`, and
  rendered by Ansible; applying it never deploys. A change to a stack is a change to that
  manifest, never a hand edit in Komodo.
- The image carries `.env.example` as its `.env`, so a variable left out of the stack silently
  falls back to the local default. The stack sets at least `APP_ENV`, `APP_DEBUG=false`,
  `APP_KEY`, `APP_URL`, the database, mail and storage settings and **`SESSION_SECURE_COOKIE=true`
  in every deployed stack**; local `.env.example` does not set it, because local development runs
  over plain HTTP. The application clock (`app.timezone`) stays UTC.
- Production caches configuration, routes and views in the container start script
  (`config:cache`, `route:cache`, `view:cache`); code reads configuration only through
  `config()`, never `env()` outside `config/`.
- Backups are taken by the infrastructure, outside the application: daily, of the production
  database and buckets, stored away from the production host.
