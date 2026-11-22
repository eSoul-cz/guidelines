---
title: Architecture
---
# Project guidelines - Architecture

## Admin panels

An admin panel built on Filament is a separate user interface: the frontend rules of the Inertia
pages do not apply to it.

- Its panel provider is `App\Domain\Providers\Filament\AdminPanelProvider`, listed in
  `bootstrap/providers.php`. Like every provider it holds wiring only, never a business rule.
- Filament resources MAY be used for plain CRUD: maintaining data that carries no business rule
  beyond validation.
- A custom action that performs a business operation dispatches the corresponding command
  ([cqrs.md](cqrs.md)) instead of reimplementing it.
