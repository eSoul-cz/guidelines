---
title: Deployment and runtime
order: 140
summary: Laravel runtime detail of the deployment scheme - php-fpm images, local development stack, healthcheck, proxy buffers, Jenkins laravelPipeline, migrations, file storage and configuration.
---
# Project guidelines - Deployment and runtime

Laravel runtime detail added to the deployment scheme: how the application is built into images, how it runs and what the pipeline checks. The application runs only in eSoul's Docker images; building, releasing, stacks, shared services and secrets follow the scheme.

## Images

- Every image starts from the shared eSoul base image `rg.fr-par.scw.cloud/esoul-starters/php-fpm-laravel:8.5`, which already provides the common PHP extensions. A missing extension is added to the base image, not installed ad hoc in a project Dockerfile (a temporary exception names the reason and the issue in the Dockerfile).
- **PHP runs as php-fpm and is reached through FastCGI on port 9000.** The host NGINX serves the files of `public/` and passes every other request to php-fpm ([Reverse proxy and TLS](#reverse-proxy-and-tls)). Octane (Swoole, RoadRunner, FrankenPHP) is not used and MUST NOT be added; code may rely on one process per request.
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
- Trusted proxies are the `TRUSTED_PROXIES` setting.
- Body and buffer sizes match the application's limits. NGINX accepts a request body at least as large as the largest upload the application allows (the manifest's `nginx.client_max_body_size`, in step with PHP's `upload_max_filesize` and `post_max_size` and the validation limit), and FastCGI response headers of at least 32 KB (`fastcgi_buffer_size 32k; fastcgi_buffers 16 32k;` is the minimum; the rendered sites use 64k for the first buffer). The preload `Link` header of a large page is the reason ([frontend.md](frontend.md#vite-and-bundling)).

## Local development

On Laravel, running the application on the host (`artisan serve`, Sail, a host database) is not supported. `docker-compose.yml` starts:

| Service | Purpose |
|---|---|
| `laravel` | php-fpm, queue worker, scheduler and Vite under Supervisor; the repository is bind-mounted |
| `nginx` | Serves the application on `${APP_PORT:-8080}` (local only) |
| database service | The production engine, named volume, health-checked |
| `mailpit` | Catches all outgoing e-mail. `.env.example` points the mail settings at it |

`.env.example` points the application at these services.

## Pipeline

The `Jenkinsfile` loads eSoul's shared Jenkins libraries (`dockerHelpers`, `testing`, `deploy` and `pipelines`) and calls the shared `laravelPipeline` step as its only statement; only what differs from the step's defaults is configured (image names and targets, prepare command, deploy rule, timeouts; an extra check only as the list below allows). The step reports the required `Jenkins` check on every branch and pull request. Its stages, in order:

1. **Checkout**, **Docker Login**, **Pull testing images**.
2. **Prepare**: `composer install`, an `.env` from `.env.example` with a fresh key, config cleared; a frontend layer adds its generation steps.
3. **Static checks**, in parallel: PHP static checks (Composer validation, Larastan, `pint --test`), **Dependency audit** (`composer audit` and, with a JavaScript frontend, `pnpm audit --audit-level critical`, which fails only on critical advisories; the target is the unfiltered audit of [dependencies.md](dependencies.md#install-hardening)), with a JavaScript frontend, JS static checks (install and the read-only checks of the frontend layer's tools guideline) and **Guidelines check** (`esoul-guidelines check` from the project's own `vendor/`, [tools.md](tools.md#guidelines); runs when the project has `esoul-guidelines.lock`, and `guidelinesCheck: false` in the pipeline configuration switches it off).
4. **Run Tests**, only after every static check passed, in parallel: **PHP Tests** (PHPUnit in parallel; on SQLite `:memory:` unless the pipeline's `testDatabase` option starts a throwaway database of the production engine), with a JavaScript frontend **JS tests** (the project's tests and the production build) and, on pull requests, **Test Docker Build** of every release image.
5. **Build and push image** on a branch with a deploy rule (`main` by default): multi-architecture images tagged as the deploy rule says (`latest` for `main`) and with any Git tag on the commit, pushed to `rg.fr-par.scw.cloud/app-images/`.
6. **Smoke test** (optional, the pipeline's `smoke` option, off by default because Jenkins capacity is limited): a Compose smoke test of the pushed images by their immutable reference, after `Build and push image` and before `Deploy`. The stage does not exist without the option; where it exists, a failure stops `Deploy`.
7. **Deploy**: the rule's Komodo stack is redeployed. A rule without a stack only publishes the images.

Keep the stage names stable: the required check is the multibranch job's `Jenkins` status. Mutation testing does not run in CI.

Jenkins is shared by every eSoul project, so build time matters:

- **The pipeline has timeouts.** A hung build is aborted instead of holding an executor until somebody notices: `laravelPipeline`'s `timeoutMinutes` sets the limits (`total`, `testing`, `images`), and a changed value has a comment saying why.
- A newer push to the same pull request aborts its running build.
- **A build runs what its change can affect** (the pipeline's `changeFilter`, on by default; its log says in `Checkout` what was compared and what is left out). A pull request that changes only documentation (`docs/`, Markdown at the top of the repository, `.github/`, agent and tool files) runs only the **Guidelines check**; one that changes only the backend runs no JavaScript stage (a change of the routes or of an HTTP class keeps the type check), one that changes only the frontend runs no PHP static check. A merge to a branch with a deploy rule runs every stage, unless it changes only documentation: then nothing is tested, no image is built and nothing is deployed. `Build Now` in Jenkins runs everything for a commit.
- A top-level directory or file the pipeline does not know (a `scripts/` directory, a data migration, a second package) makes every change to it run everything. Name it in the `Jenkinsfile` instead: `changeFilter: [areas: [php: [add: ['scripts/']]]]`. `changeFilter: false` is not the answer to an unknown path, and switching the filter off has a comment saying why.
- Push a coherent unit, and push review fixes together once they pass locally; every push starts a build. A red build is reproduced locally before its fix is pushed; never push to see whether CI agrees.
- When the multibranch job discovers both branches and pull requests, a commit with an open pull request can be built twice; building each commit once is a job setting, not a reason to skip stages in one of the two jobs.
- Integration tests follow [testing.md](testing.md#keeping-the-integration-run-short), and the test stage stays within the [suite time budget](testing.md#suite-time-budget).
- The release-image test build is the slowest stage; it runs on pull requests. It always uses the layer cache of the agent, and first asks the registry for the base images (`--pull`), so a pull request is built on the base the release will use. `testDockerBuild: [pull: false]` in the pipeline configuration switches that check off. A build without the cache is not available: `testDockerBuild.noCache` is deprecated and ignored.
- Paratest processes (and the worker count of a JS test runner, where there is one) match the Jenkins agent; a value of 1 has a comment saying why.
- `extraChecks` and any other stage added to `laravelPipeline` are new checks in the push build ([How an application reaches production](#how-an-application-reaches-production)): they need an accepted ADR and a human's approval first. The built-in `Guidelines check` stage is not one of them: it is part of the step, needs no ADR, and a project does not write it as an `extraChecks` entry. A check that needs the production engine (locking, collations, engine-specific SQL) is a `db-engine` test in the scheduled job, never an `extraChecks` entry.
- A separate scheduled Jenkins job runs what the push build does not (the `sandbox`, `quarantine` and `db-engine` groups on the production engine, [testing.md](testing.md#test-groups)). It does not report the required check, and every failure becomes an issue.

## Environments and releases

- Each environment has its own `APP_KEY` and OAuth redirects, next to the stack, database, buckets and secrets the scheme gives it.

## Migrations and seeders

- Migrations run once per deployment in the start entrypoint (`docker/production/start`) of the container with `RUN_MIGRATIONS=true` (`php artisan migrate --force --no-interaction`); worker containers never migrate. They are forward-only and safe to run while the previous release still serves requests ([architecture.md](architecture.md#persistence)). **A failing migration does not stop the container**: the entrypoint writes `MIGRATION FAILED` with the exit code to standard error and starts Supervisor anyway ([The production start entrypoint](#the-production-start-entrypoint)); the person monitoring the deployment reads the log and reacts.
- A production deployment that contains a migration is preceded by an on-demand database backup and, where the project has a staging stack, has already run there; whoever deploys watches the migration output in the log ([Monitoring a deployment](#monitoring-a-deployment)).
- **The start entrypoint never seeds**, and none of the heavy data tasks (recalculations, syncs, imports) runs in front of php-fpm: the entrypoint stays quick, caching configuration and routes and linking storage aside, and such a task is a Supervisor program or a background process.
- **The production seeder is its own class** (`ProductionSeeder`), run by hand in the container (`docker exec <container> php artisan db:seed --class=ProductionSeeder --force`). It creates only what every environment needs. Every seeder it calls is idempotent and create-only: it never overwrites rows staff may have changed, and none creates an account ([security-and-data.md](security-and-data.md#sessions-and-accounts)).
- **A demo seeder** (for example `DemoSeeder`) is another deliberate seeder: deliberate demo content, run by hand the same way (`--class=DemoSeeder`) and allowed in staging and production. It SHOULD NOT use Faker or factories that call it: the production image installs Composer packages with `--no-dev`, and `fakerphp/faker` is a development dependency. It creates no account with credentials outside local development, except demo accounts on an instance that holds only invented data, with passwords from the stack's secrets, never from code ([security-and-data.md](security-and-data.md#sessions-and-accounts)). A project MAY define further deliberate seeders for its own environments.
- **`DatabaseSeeder` is the general seeder, for local development.** It seeds synthetic data (Faker, factories, a development account) and MAY call the production and demo seeders so that local data has their rows too. It refuses to run outside `local` and `testing`: its `run()` starts with that check and throws, so `php artisan db:seed` without `--class` fails in staging and production instead of writing synthetic data. Any other general seeder that uses Faker or synthetic data without being a deliberate demo seeder gets the same guard.
- Data corrections ship as migrations or as console commands that dispatch commands, never as manual SQL ([security-and-data.md](security-and-data.md#production-access)).

## File storage

- Private files (uploads, documents) live on a private disk and reach a browser only through a **short-lived signed URL** or through a controller that authorizes against the owning record and streams the file. A public URL to a private file does not exist.
- **Code never names a disk driver.** Command handlers reach the disk through a contract in `App\Application\Contract`, implemented in `App\Infrastructure`; the driver is configuration (`config/filesystems.php`).
- Every response that serves a private file sends `X-Content-Type-Options: nosniff` and `Cache-Control: private, no-store`.
- The type and size limits of uploads are configuration, validated in the form request ([requests-and-validation.md](requests-and-validation.md#validation-rules)); the proxy and PHP accept at least the largest upload ([Reverse proxy and TLS](#reverse-proxy-and-tls)).

## Configuration and secrets

- The image carries `.env.example` as its `.env`, so a variable left out of the stack silently falls back to the local default; this is why a new variable is named in the pull request's deploy notes. The stack sets at least `APP_ENV`, `APP_DEBUG=false`, `APP_KEY`, `APP_URL`, the database, mail and storage settings and **`SESSION_SECURE_COOKIE=true` in every deployed stack**; local `.env.example` does not set it, because local development runs over plain HTTP. The application clock (`app.timezone`) stays UTC.
- Production caches configuration, routes and views in the container start script (`config:cache`, `route:cache`, `view:cache`); code reads configuration only through `config()`, never `env()` outside `config/`.
