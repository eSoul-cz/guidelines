---
title: Tools
order: 130
summary: Versions, composer and pnpm scripts, tool caches, read-only checks and autofix commands for an LSR project.
---
# Project guidelines - Tools

Company-wide standards are at https://dev.docs.esoul.cz; this file lists the tools the project uses and how to run them.

## Versions

| Area                 | Tool                                                                                             |
| -------------------- | ------------------------------------------------------------------------------------------------ |
| PHP                  | 8.5                                                                                              |
| Framework            | LSR packages (`lsr/*`) at the versions locked in `composer.lock`                                 |
| Runtime              | RoadRunner (`rr`), PSR-7 worker                                                                  |
| Static analysis      | PHPStan (level and paths in `phpstan.neon`; the level MUST NOT be lowered)                       |
| Code style           | PHP-CS-Fixer (`.php-cs-fixer.php` is the PHP formatting contract)                                |
| PHP tests            | PHPUnit 13, paratest                                                                             |
| Node                 | 24                                                                                               |
| Package manager      | pnpm 10.20.0 (`packageManager` in `package.json`)                                                |
| Frontend build       | Vite 8, Vue 3, Inertia 3, TypeScript, Tailwind CSS 4                                             |
| Frontend checks      | ESLint (flat config), Prettier (printWidth 150, tabWidth 4), `vue-tsc`, Vitest                   |

- `composer.json`, `composer.lock`, `package.json` and `pnpm-lock.yaml` are authoritative for installed versions.
- Use API documentation and installed source that match the locked `lsr/*` versions and the PHPUnit major/minor, even when linked documentation is newer. Read the installed `vendor/lsr/*` source before concluding that a feature is unavailable.
- Keep dependency changes within the scope the task authorizes.
- When chaining a method on a newly instantiated class, write `new Foo()->bar()`; only the constructor call's parentheses are needed.

## Where tools run

- Run tools through the `composer` and `pnpm` scripts, never through globally installed binaries, so local runs and CI use the same versions and configuration.
- PHP tools run in the project's PHP container or a matching local PHP 8.5; prefix PHPUnit with `XDEBUG_MODE=off` unless you need the debugger or coverage.
- Jenkins runs the same scripts in the shared testing images; a check that passes only locally is not a pass.
- RoadRunner runs the application in development and production (`rr serve -c .rr.yaml`); the container start scripts prepare the runtime and start it ([deployment.md](deployment.md)).

## Tool caches

Tool caches live under `temp/tools/` (ignored with the rest of `temp/`):

| Tool         | Cache                              |
| ------------ | ---------------------------------- |
| PHPStan      | `temp/tools/phpstan`               |
| PHPUnit      | `temp/tools/phpunit`               |
| PHP-CS-Fixer | `temp/tools/php-cs-fixer.cache`    |
| ESLint       | `temp/tools/eslint`                |
| Prettier     | `temp/tools/prettier`              |
| Vitest       | `temp/tools/vitest`                |

CI persists `temp/tools` between runs. The rest of `temp/` is the application runtime cache (DI container, Latte, models, catalogs, sessions); do not clear one when you mean the other. Clear the generated DI container with `php bin/console container:clean` after changing DI configuration.

## Read-only verification

Run the checks before pushing. They MUST NOT rewrite tracked source files.

### PHP

```bash
composer phpstan
composer cs
composer test
```

`composer test:parallel` runs the same suite with paratest.

### Frontend

```bash
pnpm eslint
pnpm format:check
pnpm tsc
pnpm test:fe
pnpm build
```

## Formatting and lint autofixes

Mutating commands are explicit developer actions: run them deliberately, inspect the diff, then rerun the read-only checks. CI MUST use the read-only commands.

### PHP

```bash
composer cbf
```

`composer cbf` (alias of `composer cs:fix`) rewrites PHP files; `composer cs` is its read-only `--dry-run --diff` counterpart.

### Frontend

```bash
pnpm lint:fix
pnpm format
```

`pnpm lint:fix` (ESLint `--fix`) and `pnpm format` (Prettier `--write`) rewrite files. Formatting is required before committing: `composer cbf` for PHP and `pnpm format` for frontend and other Prettier-managed files.

## Runtime commands

```bash
php ./bin/console list           # console entrypoint
php ./bin/console install        # apply database installation and migrations
pnpm dev                         # Vite dev server
pnpm build                       # production frontend build
```

`install --fresh` is only for disposable databases.

## Documentation checks

When the repository ships a documentation launcher, validate and build the developer guides before publishing them:

```bash
docs/documentation validate
docs/documentation build
```

The build requires Docker. Generated PDFs are written to the ignored `docs/pdf/` directory and disposable review artifacts to `docs/.documentation-work/`. Guide sources and outputs are configured in `docs/documentation.toml`.
