---
title: Architecture
---
# Project guidelines - Architecture

Lunar is the shop's e-commerce core: products, variants, prices, carts, orders, customers, channels, taxes and the admin panel. The project extends it and does not replace it. A Lunar project also carries `package/filament` (its admin is a Filament panel) and, with an Inertia storefront, `package/laravel-inertia`; the rules here cover only what Lunar adds. The shop's integrations with payment gateways, carriers and ERP systems are in [integrations.md](integrations.md).

## Layers

### Lunar models in the domain core

Lunar is the shop's domain model, so this layer makes one explicit exception to the framework-free core ([`App\Domain\<Module>`](#appdomainmodule---the-framework-free-core)):

- Code in `App\Domain` MAY import `Lunar\*`: its models (`Lunar\Models\*`) and its value types (`Lunar\DataTypes\*`, `Lunar\Base\DataTransferObjects\*`) as parameter, return and property types. The core rule's ban on an Eloquent model does not cover them.
- Every other rule of the core and of the dependency direction stays. `Illuminate\*` is still forbidden in the core (database, facades, HTTP, the container, the session), and persistence (queries, saves, transactions, locks) stays in the CQRS handlers.
- The layer architecture test lists Illuminate namespaces and the application layers, not `Lunar\`, so it needs no change.

### Classes Lunar stores by name

Lunar saves the fully qualified class name of some classes in the database: the attribute field types (`lunar_attributes.type` and the JSON `attribute_data`), models in the morph map without an alias, and anything else registered by class name.

- Such a class MUST NOT be moved or renamed without a data migration that rewrites the stored names, deployed with the code. A refactor that fixes a layer violation ([Fixing a layer-dependency violation](#fixing-a-layer-dependency-violation)) leaves these classes where they are.
- Where the location of such a class breaks the layer rules, the class is a recorded exception: the project lists it in the project part of this file and in the layer test, with the column or registry that holds its name.
- A morph map alias is the way to stop depending on a class name for a new model; an existing stored name is changed only by the migration above.

## Versions and upgrades

- The project is on Lunar 1.x (Lunar 2 is still in alpha) and pins the minor line of `lunarphp/lunar` in `composer.json`. A Lunar addon (for example table-rate shipping) stays on the same minor line. The project MUST NOT require Lunar's sub-packages, Filament or Livewire directly; they follow Lunar.
- A move to another Lunar major is a decision with its own ADR, never a dependency bump: it changes the admin stack (the Filament major) and can change model classes and extension APIs.
- The project MUST NOT edit `vendor/`. Lunar's configuration is published to `config/lunar/*.php` and is project-owned; a change to it is a reviewed code change.
- A faulty vendor migration MAY be replaced by an application file with the same name (Laravel keys migrations by name). The copy MUST have a test that fails when the vendor file changes ([testing.md](testing.md#named-exceptions)), and it MUST be deleted when upstream fixes the migration.
- An upgrade follows this checklist, in one reviewed change:
  1. Move the pins: Lunar and its addons together.
  2. Diff the published `config/lunar/*.php` against the new vendor defaults and merge the differences.
  3. Run the tests that pin Lunar behaviour ([testing.md](testing.md#pinning-lunar-behaviour)); a failing pin is a decision, not a test to delete.
  4. Compare each vendor migration copy and each pipeline step the project replaced with its vendor original.

## Extension points

Behaviour changes use the first option that fits, in this order:

1. Configuration in `config/lunar/*.php`.
2. A documented extension point: pipeline steps (added or replaced in the pipeline configuration), modifiers, actions, validators, `Payments::extend()`, `Taxes::extend()`, `Discounts::addType()`, `FieldTypeManifest::add()`, `ModelManifest::replace()`, `LunarPanel::extensions()`.
3. A project class that extends a Lunar model, registered with `ModelManifest::replace()`.

If none fits, the gap is recorded in the issue before a workaround is written.

- Pipeline steps and modifiers run inside Lunar's calculation. They MUST NOT write state. A step that must persist something calls a command.
- An overridden action MAY keep Lunar's contract while adding events (for example saving the model instead of a bulk query update) when a test pins the contract.
- A project extension of a Lunar entity is a 1:1 table with a subclass in `App\Infrastructure\Models`, registered in `App\Domain\Providers`. Relations are added with `resolveRelationUsing()`. Queries go through the project class, because a query started on the Lunar class skips the subclass's eager loading.
- Lunar's entities are not copied into parallel tables. A module adds its own table only for data Lunar does not model.

## Process-wide state

Lunar keeps some registries as container singletons for the life of a process; the shipping manifest of resolved options is one. Queue workers and long-running commands process many carts or orders in one process.

- Code that fills such a registry MUST clear it at the start of the run and MUST NOT read the previous run's content.
- A cache of calculation results MUST NOT be process-wide and MUST NOT be shared between carts or orders. Its key includes the state it was computed from (for example a cart fingerprint), and it lives for one operation.
- A project singleton MUST NOT hold data of one cart or order (the handler lifetime rule in [cqrs.md](cqrs.md#handler-lifetime) applies to every singleton).

## Calculation cost

- Lunar requests the same derived value more than once per operation (the cart calculation and the tax breakdown both need the shipping option). A project modifier that runs queries MUST evaluate once per cart state per operation and reuse the result.
- Rates, prices, zones, exclusions and surcharges MUST be loaded in batch per evaluation. The number of queries MUST NOT depend on the number of candidate options or rows; a query inside a loop over them is a `Major` review finding.
- The rule that decides which options a cart may use lives in one query. Modifiers call it and MUST NOT repeat its logic.

## Money

- Lunar stores prices as integer minor units per currency. The project keeps `lunar.pricing.stored_inclusive_of_tax` `false`: prices are net, and the tax driver MUST refuse inclusive prices.
- Lunar's `CalculateLines` cart pipeline step converts a price through float before it rounds. The project replaces it in the cart pipeline with a step of its own that works in integer minor units with the project's rounding mode. Tests pin the replacement against Lunar's results on non-ambiguous amounts, the ones where float and integer rounding agree ([testing.md](testing.md#pinning-lunar-behaviour)).
- Lunar money math on the project's side never goes through float: project code does not read a Lunar price through `Price::unitDecimal()` or `decimal()` and every amount it writes to a Lunar column is an integer produced by the project's rounding.
- Lunar money columns are `UNSIGNED` on MariaDB and MySQL. A project MUST NOT write a negative amount to one. Signed adjustments are kept in project tables and Lunar receives the positive total. SQLite does not enforce `UNSIGNED`, so the write path MUST reject a negative result itself and test it.
- A project that sets its own exchange rates MUST switch off Lunar's price copy between currencies (`sync_prices` off, and no copy of the default prices into a new currency). Derived prices are project rows with a record of the rate used.
- Customer-group and quantity-tier prices are chosen by one project rule, installed as a pricing pipeline step. Lunar's own choice can pick a default-list tier over a cheaper group price; the rule and the group rows for every tier prevent it.

## Tax

- When a rate depends on more than Lunar's zones (delivery country, customer or VAT status, distance selling), the project provides a tax driver with `Taxes::extend()`. Lunar's system driver MUST NOT choose a rate in such a project.
- The shipping tax uses the same delivery country and customer context as the cart lines. The context is set once for the whole cart calculation, by a step at the start of the cart pipeline.

## Lunar admin panel

Lunar's admin is a Filament panel, so a Lunar project carries the `package/filament` layer ([Admin panels](#admin-panels)). It is a separate user interface to which the Inertia frontend rules do not apply.

- The panel is built by a dedicated provider, `App\Domain\Providers\Filament\LunarPanelProvider`, listed in `bootstrap/providers.php`. It takes the place of the `AdminPanelProvider` of the Filament layer. It configures the panel (`LunarPanel::panel(...)`, path, brand, middleware, pages, resources, extensions) and registers it. Like every provider it holds wiring only, never a business rule.
- Plain CRUD stays in Lunar's resources. Customisation is panel configuration, resource and page extensions, and project pages and actions in `App\Infrastructure\Admin`, registered by the provider.
- A custom admin action that changes state dispatches a command. It MUST NOT write the Lunar model directly.
- A Lunar action that writes state outside the project's command rules (status changes, direct refunds) is replaced or removed. The admin is not a bypass of the storefront's commands.
- The panel starts with Lunar's default theme; a brand logo and render hooks are allowed on it. A project MAY move to its own Filament theme. The theme rule of the Filament layer ([Tailwind CSS](frontend.md#tailwind-css)) then applies.
- Admin access is the `staff` guard plus the project's policies and panel middleware.
- Lunar ships no translations for some locales. The project provides them in `lang/vendor/`. A missing key falls back to the fallback locale; a page never mixes locales by environment.

## Storefront and search

- A Lunar model is an Eloquent model: the Inertia rule that no model is a page prop applies to it, and a query returns a DTO built from it ([frontend.md](frontend.md)). Route-model binding to a Lunar model in a controller signature is allowed.
- Search runs through Laravel Scout, which Lunar already requires. When search needs partitions or columns Lunar's indexers do not provide, the project adds a read model that is Scout-searchable. Lunar's indexers stay as they are where they cover the need.
