---
title: Testing
---
# Project guidelines - Testing

Lunar's own behaviour is Lunar's test suite. The project tests what it configures, extends and calls.

## Named exceptions

- The Lunar layer names one more: the test that a copied vendor migration still matches the vendor file ([architecture.md](architecture.md#versions-and-upgrades)). A project that copies a vendor migration records the copy in its Lunar ADR.

## How much to test

- Order placement, payment, price selection, tax, shipping price, order totals and refunds are the default tier-1 Lunar flows. The project's Lunar ADR confirms or changes this list, and the tier-1 list the project keeps for the general rule refers to it.
- A test of a tier-1 Lunar flow is a full scenario of its surface, under the same cost rules as every feature test.

## Database in tests

- A test that needs Lunar reference data (default currency, channel, language, customer group, tax class, tax zone) creates it through Lunar's factories, or reads the rows the production seeder created when the test's base class seeds them once per process. A test MUST NOT create a second default record.
- Shared fixtures that build a catalog are used only by a behaviour that needs the whole catalog ([How much to test](#how-much-to-test)).
- Behaviour that depends on the production engine (unsigned columns, locking, collation, Lunar's SQL) is a `db-engine` test ([Test groups](#test-groups)).

## Keeping the integration run short

- A placed order, a paid order or a cart in a given state is a precondition: it is built by a factory state or a helper that writes the rows. Only the test whose behaviour is order placement or a cart calculation runs `PlaceOrderCommand` or the cart pipeline, and only a payment test runs a payment flow.
- A helper that calls Lunar's actions to build a precondition is allowed once per test class and MUST say so in its docblock.

## Pinning Lunar behaviour

- Each Lunar behaviour the project relies on, and that the documentation does not make obvious, gets one pin test in `tests/Feature/Lunar`. A pin fails when a Lunar upgrade changes the behaviour. Its name describes the behaviour, not the version.
- A pin is deleted when project code covers the same behaviour, so the suite keeps no duplicate.
- A pipeline step, modifier or driver the project replaced is pinned against the Lunar original on non-ambiguous inputs, so an upgrade that changes the original's other behaviour fails the pin. The integer replacement of `CalculateLines` is pinned this way: its line totals equal Lunar's on amounts where float and integer rounding agree, and a separate unit test covers the amounts where they differ.

## Test doubles

- A test that replaces a Lunar driver (tax, payment, shipping) MUST say why in its docblock.
- Each project driver is exercised by the real driver in at least one test.

## Temporary tests

- A query-count or timing measurement of a Lunar calculation is a temporary test: it is used during development and deleted before the pull request is opened. There is no permanent query-count or timing test.

## Admin tests

- Admin tests live in `tests/Feature/Admin`. They authenticate as a Lunar `Staff` on the `staff` guard and use Livewire or Filament testing helpers against the project's own class.
- Lunar's built-in resources are not re-tested.
