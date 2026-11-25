---
title: Architecture
---
# Project guidelines - Architecture

<!-- replace: framework/laravel -->
The application is a Laravel monolith: business operations are commands and queries (`esoul-cz/laravel-cqrs`) and there is one database. The user interface is the project's variant: Inertia (`@inertiajs/react`) with React pages, a Filament admin panel, or both. The versions are named only in [tools.md](tools.md#versions). The project's ADRs record every decision that deviates from this file.

| Part | Technology | Where | Details |
|---|---|---|---|
| User interface | The project's variant: Inertia and React pages and/or a Filament admin panel | `App\Infrastructure\Http`, plus the variant's own directories | [frontend.md](frontend.md) |
| Business operations | Commands and queries via `esoul-cz/laravel-cqrs` | `App\Domain\CQRS` | [cqrs.md](cqrs.md) |
| Business rules and calculations | Framework-free value objects, enums, domain services | `App\Domain\<Module>` | this file |
| Database | The engine the project's ADR chooses in production, SQLite `:memory:` in tests | `database/` | [testing.md](testing.md#database-in-tests) |
| Runtime | Docker images built by Jenkins, deployed by Komodo | `docker/`, `Jenkinsfile` | [deployment.md](deployment.md) |
