---
title: Architecture
order: 100
summary: LSR application structure - RoadRunner worker, Nette DI, routing, controllers, request DTOs, Latte shell and technical-layer layout of `src/`.
---
# Project guidelines - Architecture

The application is a PHP 8.5 LSR Framework application served by RoadRunner workers. This file describes the structure that new code MUST fit into; command and query rules are in [cqrs.md](cqrs.md), the frontend layout in [frontend.md](frontend.md).

## Runtime and entrypoints

- A pool of long-lived PSR-7 workers serves HTTP (`psr-worker.php`, started by `rr serve -c .rr.yaml`); background jobs and the scheduler run under the same RoadRunner server as separate pools/services. The console entrypoint is `bin/console` (Symfony Console).
- `include/load.php` bootstraps the application once per process: constants, configuration, the DI container and the database connection. `psr-worker.php` and `bin/console` MUST stay thin; application logic does not live in entrypoints.
- Each request is handled as "one request, one response", but inside a worker process that is **reused** across requests. Read [Long-running worker state](#long-running-worker-state) before adding caches, statics or singletons.
- Server-side rendering of the Inertia frontend (a separate Node process) is opt-in and described in [deployment.md](deployment.md); the architecture below applies with or without it.

## Directory layout

Application code is grouped by **technical layer** under `src/` (PSR-4 namespace `App\` maps to `src/`). Do not introduce a domain or feature top-level structure unless a decision records it.

| Path                                         | Holds                                                                                          |
| -------------------------------------------- | ---------------------------------------------------------------------------------------------- |
| `src/Http/Controllers`                       | HTTP controllers                                                                               |
| `src/Http/Requests`                          | request DTOs (validated input)                                                                 |
| `src/Http/Templates`                         | page and template parameter DTOs (Inertia props, Latte parameters)                             |
| `src/Http/Middleware`                        | route middleware                                                                               |
| `src/CQRS`                                   | commands, handlers, queries and their DTOs ([cqrs.md](cqrs.md))                                |
| `src/Models`                                 | ORM models                                                                                     |
| `src/Services`                               | services composing queries, models and commands; pure calculations                             |
| `src/Tasks`                                  | background job tasks                                                                           |
| `src/Console`                                | console commands                                                                               |
| `src/Core`                                   | application kernel (`App`, `Loader`, session, cookies)                                         |
| `src/Install`                                | database installation and migration runner                                                     |
| `config/`                                    | `services.php`, `di/*.neon`, `migrations/*.neon`                                               |
| `routes/`                                    | route files, loaded in file-name order (`00-…`, `10-…`)                                        |
| `templates/`                                 | Latte templates (the layout and the Inertia page shell)                                        |
| `assets/`                                    | frontend sources ([frontend.md](frontend.md))                                                  |
| `tests/`                                     | PHPUnit tests mirroring the layers ([testing.md](testing.md))                                  |

- Every PHP file declares `strict_types` and exactly one class, interface, trait or enum (PSR-4).
- Files stay in the existing technical layers (`Controllers`, `Requests`, `Models`, `CQRS`, `Tasks`, ...). A new layer needs a decision.
- Public service and CQRS boundaries MUST return typed DTOs, ORM models or collections of them; they MUST NOT expose `Dibi\Row` or unstructured associative arrays.
- Closed sets (status, mode, source, reason, type) are PHP backed enums, not strings or class constants.

## Dependency injection

- Services are wired with **Nette DI** NEON files. `config/services.php` returns the list of root files; shared wiring lives in `config/di/services-common.neon`, per-environment differences in thin environment files, and local overrides in an uncommitted `services.local.neon`.
- Keep NEON modular: root files are indexes that `include` focused files (`config/di/extensions/*.neon` for framework extensions, `config/di/configs/*.neon` for application concerns). Do not grow one application-wide file.
- Broad technical layers are **auto-discovered** (`search:` over `src/Http/Controllers`, console commands, CQRS handlers and queries). Specific or named services are defined explicitly.
- Constructor injection only. Controllers and services MUST NOT call `App::getService()` / `App::getServiceByType()`; the container is resolved at the bootstrap and in the route handler.
- After changing DI configuration, clear the generated container (`php bin/console container:clean`) before runtime checks.
- Secrets and machine-specific values come from the environment or from uncommitted private configuration; only `.env.example` (key names, placeholder values) is committed.

## Routing and controllers

- Routes are defined in `routes/*.php` through the router group API, every route has a `->name(...)`, and shared behaviour is attached through **named middleware groups** (for example `web`, `csrf`, `guest`, `authenticated`, `admin`) declared in one middleware route file loaded first.
- Controllers extend `Lsr\Core\Controllers\Controller`; Inertia controllers add `Lsr\Inertia\Http\WithInertia`. A controller action is thin: it maps the request, calls a command, query or service, and builds the response.
- Action arguments are resolved by the route handler: route parameters for scalars and models, the container for services, and `#[MapRequest]` for request DTOs. Controllers MUST NOT read `$_GET`/`$_POST` directly.
- Business rules and persistence do not live in controllers: writes go through CQRS commands ([cqrs.md](cqrs.md)), reads through queries or ORM models.
- Authorization is enforced on the server by middleware or inside the command; the frontend only reflects it.
- When adding a route, check the whole chain together: route file, controller signature, request DTO, response DTO and the frontend page name.

## Request DTOs and template parameters

- Every endpoint that accepts input maps it to a **request DTO** in `src/Http/Requests` with `Lsr\ObjectValidation\Attributes`. Validation errors are returned or flashed per field; raw request arrays do not travel past the controller.
- Page and template data are **typed parameter DTOs** in `src/Http/Templates` (extending `Lsr\Core\Controllers\TemplateParameters`), not plain arrays. DTOs carry only what the page needs and never expose sensitive attributes.

## Persistence

- Database access goes through `Lsr\Db\DB` (the Dibi-based fluent query builder) and `lsr/orm` models. Models extend the project's base model.
- App-owned writes use ORM `save()`. A raw DB write MUST clear the affected cache tags explicitly, unless it is install/migration code.
- Reads through the DB facade use fluent queries with explicit cache tags and `fetchDto()` / `fetchAllDto()` for projections; simple one-table reads use `Model::query()`.
- SQL table and column names are `snake_case`, ORM properties `camelCase`. Tables use plural `snake_case` names with an explicit `TABLE` constant and primary keys `id_{model}` declared with `#[PrimaryKey(...)]`.
- Models declare every mapped field and every relation as an attribute.
- Schema lives in `config/migrations/*.neon` and is applied by the installer (`php bin/console install`). A change to a model or query that needs new columns changes the migration NEON in the same pull request. `install --fresh` is only for disposable databases.

## Templates and frontend shell

- Latte templates render the HTML shell; the application UI is Vue + Inertia ([frontend.md](frontend.md)). Controllers do not build HTML fragments.
- Shared document structure (head, SEO tags, asset tags, Inertia outlet) lives in one layout template; reusable presentation lives in partials or Latte extensions registered as DI services.
- Output is escaped by Latte at the rendering boundary. Do not pre-mark database, request or translated text as safe HTML.

## Long-running worker state

RoadRunner workers do **not** reset memoized or static state between requests or jobs.

- Request- or job-dependent memoization MUST live on request- or job-local objects.
- Persistent objects (services, singletons), static properties and global calls MUST NOT hold per-request data (the current user, locale, request, tenant); if they must, the lifecycle clearing is explicit and tested.
- Workers are recycled by `max_jobs` and `max_worker_memory` in `.rr.yaml`; correctness MUST NOT depend on that recycling.
- Anything that must survive a worker (sessions, cache, queues) goes to the shared backends (Redis, the database, the RoadRunner jobs pipeline), never to local memory or local files.

## Background work

- Asynchronous work is a command dispatched through the async command bus and executed by the RoadRunner jobs pool; scheduled work runs in the scheduler service (`scheduler:run`).
- A task MUST be idempotent or guard against double execution, because jobs can be retried.
- Tasks receive serializable payloads (command DTOs), not services or connections.
