---
title: Tools
order: 130
summary: Versions, where tools run, read-only checks, static analysis, autofixes, code generators and Laravel Boost.
---
# Project guidelines - Tools

The commands below are the project's contract with developers, agents and CI. The company-wide
standards are at `https://dev.docs.esoul.cz`.

## Versions

| Area | Tool | Version / source |
|---|---|---|
| Runtime | PHP | 8.5 |
| Framework | Laravel | 13 (exact version in `composer.lock`) |
| Commands and queries | `esoul-cz/laravel-cqrs` | see [cqrs.md](cqrs.md) |
| User interface | Inertia 3, Vue 3 (React is not supported), TypeScript, Tailwind CSS 4, shadcn-vue, Laravel Wayfinder | see [frontend.md](frontend.md) |
| Node | Node.js | 24 |
| Node package manager | pnpm | 10.20.0, pinned in `package.json` `packageManager` |
| Frontend build | Vite | 8 |
| Frontend quality | ESLint (flat config), Prettier (`printWidth` 150, `tabWidth` 4), TypeScript, Vitest | `eslint.config.js`, `.prettierrc`, `tsconfig*.json`, `vitest.config.ts` |
| PHP quality | Pint (`laravel` preset), Larastan (PHPStan level 10 on `app/`), PHPUnit 13 with Paratest | `pint.json`, `phpstan.neon`, `phpunit.xml` |
| Test database | SQLite `:memory:` | `phpunit.xml`, see [testing.md](testing.md#database-in-tests) |
| Runtime and delivery | Docker (base image `php-fpm-laravel:8.5`), Jenkins, Komodo | see [deployment.md](deployment.md) |
| Agent tooling | Laravel Boost (guidelines, skills, MCP), skills in `.agents/skills` | `AGENTS.md`, `.ai/guidelines/`, `skills-lock.json` |

`composer.json`, `package.json` and their lockfiles are authoritative for installed versions.
Use documentation that matches the installed majors (Laravel, PHPUnit, Inertia, Tailwind), even
when a skill links to a newer one; check the installed source before concluding that a feature
does not exist. Adding or upgrading a dependency needs the maintainer's approval.

## Where tools run

The application runs only in the Docker stack ([deployment.md](deployment.md)). Application
commands (migrations, seeders, generators, Tinker) run in the container:

```bash
docker compose exec laravel php artisan <command>
```

Lint, static analysis, formatting and the tests MAY run on the host (PHP, Composer, Node and pnpm
installed) or in the container. The tests use SQLite `:memory:` and never need the database
service. Coverage needs a driver (Xdebug or PCOV) that the container provides.

A fresh checkout or git worktree has no `vendor/` and no `.env`:

```bash
composer install
cp .env.example .env
php artisan key:generate
```

## Read-only verification

Run these before pushing. They never rewrite tracked files, and CI runs the same ones.

### PHP

```bash
composer phpstan   # Larastan (PHPStan level 10) over app/
composer cs     # pint --test
composer test   # php artisan test --parallel
```

### Frontend

```bash
pnpm eslint
pnpm format:check
pnpm tsc
pnpm test
pnpm build
pnpm frontend:check   # all of the above plus the structure and page-path checks, with a summary
```

### Narrow runs

```bash
php artisan test --parallel tests/Feature/<Area> tests/Unit/<Area>   # the touched area, before a commit
php artisan test --filter=<TestName>                                  # one behaviour while editing
```

The whole suite runs once on the code that will be pushed, not after every edit
([testing.md](testing.md#testing-workflow)). Mutation testing is optional and local; it is
described in [testing.md](testing.md#mutation-testing), and the test groups in
[testing.md](testing.md#test-groups).

## Static analysis

- Larastan runs PHPStan at **level 10** over `app/`, with `bleedingEdge`. The baseline stays
  empty: a new error is fixed, not baselined.
- An ignore is the narrowest possible (one identifier, one path) with a comment explaining why.
- Test code MAY be analysed as well (`phpstan/phpstan-phpunit`); when it is, type the data a test
  reads instead of suppressing an error.

## Formatting and lint autofixes

```bash
vendor/bin/pint --dirty --format agent   # PHP files changed in the working tree
composer pint                            # the whole PHP tree
pnpm eslint:fix
pnpm format:fix
```

- `pint.json` is the PHP formatting contract: the `laravel` preset plus the project rules
  declared there, including `declare(strict_types=1)`. The project does not claim blanket PSR-12.
- Prettier uses `printWidth` 150 and `tabWidth` 4; ESLint uses the flat config
  (`eslint.config.js`). Do not add a second formatter or a legacy `.eslintrc`.
- Mutating commands are explicit developer actions. Run them deliberately, review the diff, then
  rerun the read-only checks. CI uses only the read-only commands (`composer cs`,
  `pnpm eslint`, `pnpm format:check`).

## Tool caches

PHPStan, PHPUnit, Pint, ESLint, Prettier and Vitest keep their caches in the gitignored `tmp/`
directory, which Jenkins persists between builds. Delete `tmp/` if a cached result looks stale.

## Code generators

- Commands, queries and handlers: `make:cqrs-command`, `make:cqrs-query`, always with `--handler`
  and `--return` ([cqrs.md](cqrs.md#creating-commands-and-queries)).
- Everything else Laravel generates (`make:model`, `make:migration`, `make:policy`,
  `make:request`, `make:test`) is moved into its layer and module before committing
  ([architecture.md](architecture.md)).
- Wayfinder route and action modules are generated by the Vite plugin during `pnpm dev` and
  `pnpm build`; run `php artisan wayfinder:generate --with-form` when the plugin is unavailable.
  Generated files are never edited.

## Laravel Boost

- Laravel Boost, when installed, generates its own block in `AGENTS.md` between
  `<laravel-boost-guidelines>` tags from `.ai/guidelines/` and the installed packages. **Never
  edit inside that block**; `php artisan boost:update` rewrites it.
- The project guidelines live in `docs/project_guidelines/` and are the authority wherever they
  conflict with generated Boost text. In particular, Boost's advice to deploy with Laravel Cloud
  does not apply ([deployment.md](deployment.md)).
- Skills live in `.agents/skills/` and are committed; `.claude/skills/<name>` links to them.
- `boost.json` and `.mcp.json` are gitignored: every developer runs `php artisan boost:install`
  once after cloning. `php artisan boost:update` refreshes the guidelines and skills; commit the
  result only when updating them was the point of the work.
- Boost registers its commands only with `APP_ENV=local` or `APP_DEBUG=true`, so a fresh checkout
  needs its `.env` first. Its database tools need the database reachable, so the MCP server runs
  in the container (`docker compose exec -T laravel php artisan boost:mcp`).

## PHP conventions

- When chaining from a newly instantiated class, write `new Foo()->bar()`; the only parentheses
  are the constructor call.
- Long-running queue workers do not clear `once()` between jobs; keep job-dependent memoization
  on job-local objects.

## Documentation checks

When the project keeps application documentation that is built to PDF:

```bash
docs/documentation validate
docs/documentation build
```

The build requires Docker; PDFs are written to the gitignored `docs/pdf/`
([documentation.md](documentation.md#generated-documentation)).
