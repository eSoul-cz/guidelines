---
title: Testing
---
# Project guidelines - Testing

PHPUnit tests live in `tests/` under the `Tests` namespace; frontend (Vitest) tests are covered in [frontend.md](frontend.md#frontend-tests). The suite follows the general rules in this file; the sections below add what is specific to the LSR stack.

## Error and validation messages

- For `Lsr\ObjectValidation\Exceptions\ValidationException` assert the `property` (and `object`); for `ValidationMultiException` assert the `property` of each entry in `exceptions`.
- Request DTO validation: assert the error keys (`property`), including every custom validation rule.

## Writing tests

- Test classes are `final`, declare `strict_types` and extend `PHPUnit\Framework\TestCase`.
- Test method names are `snake_case` and describe the behaviour (`test_open_orders_can_be_cancelled`, or `#[Test]` with a descriptive name); the code-style fixer enforces the casing (`php_unit_method_casing`).
- Data providers use the `#[DataProvider('name')]` attribute and are `public static` methods in the same test class.

## Test layout

Tests sit in the directory that mirrors the layer of `src/` they cover (`tests/Http` for controllers and middleware); a directory appears when its first test does. There is no separate unit/feature split: a test exercises the smallest real collaborator set that proves the behaviour.

- Shared helpers and fakes go to `tests/Support` and fixtures to `tests/Fixtures`, created when first needed.
- `tests/bootstrap.php` defines the constants and loads the autoloader; it does not boot the application or open a database connection (`noDb=1` in `phpunit.xml`). Code that needs the container or a database builds exactly what the test requires.

## Database in tests

- The default suite runs without a database. A test that needs one uses a temporary SQLite file created through a support trait in `tests/Support` and registered with `DB::init()`, created per test and removed afterwards.

## Running tests

Run the whole suite with `composer test`, or `composer test:parallel` (paratest) for a faster run. PHPUnit keeps its cache in `temp/tools/phpunit`.

Narrower runs; prefix them with `XDEBUG_MODE=off` unless you need the debugger or coverage (`composer test` runs with `XDEBUG_MODE=coverage`):

```bash
XDEBUG_MODE=off ./vendor/bin/phpunit tests/Http/SomeTest.php
XDEBUG_MODE=off ./vendor/bin/phpunit --filter=test_name
XDEBUG_MODE=off ./vendor/bin/phpunit tests/Http
```

## Controller testing

- Tests for HTTP controllers and middleware live in `tests/Http`. A complex controller MAY be split into several test cases by functionality.
- Every test asserts the response status code with an explicit assertion (`self::assertSame(200, $response->getStatusCode())`).
- Every controller endpoint MUST be covered, with successful and unsuccessful scenarios (validation errors, unauthorized access).
- GET endpoints: assert the status and, for Inertia responses, the component and the props that carry behaviour.
- POST, PUT, PATCH and DELETE endpoints: assert the status and the changes in the database or in the response data.
- Middleware applied to the endpoint (authentication, authorization, rate limits) is covered in its own scenarios.
- An endpoint that is a thin wrapper around a service SHOULD repeat the essential scenarios of the code it calls.
- JSON responses: assert the structure, so the response is guaranteed to contain the expected data.

## Testing workflow

For this stack the steps above use these commands:

- Narrow run: `XDEBUG_MODE=off ./vendor/bin/phpunit <path>`; full suite: `composer test` ([Running tests](#running-tests)).
- Format with `composer cs:fix` before each commit; `composer cs` is the read-only check ([tools.md](tools.md#formatting-and-lint-autofixes)).
- Run `composer phpstan` once on the code that will be pushed.
