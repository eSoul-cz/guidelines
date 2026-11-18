---
title: Testing
---
# Project guidelines - Testing

PHPUnit tests live in `tests/` under the `Tests` namespace; frontend (Vitest) tests are covered in [frontend.md](frontend.md).

## Error and validation messages

- For `Lsr\ObjectValidation\Exceptions\ValidationException` assert the `property` (and `object`); for `ValidationMultiException` assert the `property` of each entry in `exceptions`.
- A JSON request failing validation: assert status `422` and `array_keys($payload['errors'])`. A web form failing validation: assert the redirect status, `Location` and the keys of the flashed errors.
- Error text comes from the translation catalog or is written inline; it depends on locale and copy edits and MUST NOT be pinned by a test.

## Writing tests

- Test classes are `final`, declare `strict_types` and extend `PHPUnit\Framework\TestCase`.
- Test method names are `snake_case` and describe the behaviour (`test_open_orders_can_be_cancelled`); the code-style fixer enforces it (`php_unit_method_casing`).
- Use `assertSame()` for strict values and object identity; compare object state with an appropriate equality assertion or explicit typed values.
- Data providers use the `#[DataProvider('name')]` attribute, are `public static` camelCase methods in the same test class, return an iterable documented in the docblock, and SHOULD be generators with a descriptive key per scenario (`yield 'unknown alpha-two code' => ['ZZ'];`). When a scenario needs instance data, the provider yields closures with a documented shape.
- HTML escaping tests (mail templates and other rendered HTML) MUST assert the encoded markup (`&lt;script&gt;`) and reject raw executable tags.
- Migrations, models and data objects MUST NOT be tested in isolation as the subject of a committed test. Schema, mappings, relations and data transformations are verified through the domain or application behaviour that depends on them; logic placed on a data object is covered through the unit that uses it.

## Test layout

Tests mirror the technical layers of `src/`: `CQRS`, `Console`, `Core`, `Http`, `Install`, `Models`, `Serializer`, `Services`, `Tasks` and so on. Shared helpers and fakes live in `tests/Support`, fixtures in `tests/Fixtures`. There is no separate unit/feature split; a test exercises the smallest real collaborator set that proves the behaviour.

- A test case SHOULD be placed in the directory of the layer it tests.
- CQRS tests live in `tests/CQRS`, HTTP controller and middleware tests in `tests/Http`.
- `tests/bootstrap.php` defines the constants and loads the autoloader; it does not boot the application or open a database connection. Code that needs the container or a database builds exactly what the test requires.

## Test doubles

Test doubles (mocks, stubs, fakes) isolate the code under test from external services and the database.

- Keep behaviour-specific fakes inside the individual test. Suite-wide safety guards MAY live in a shared base class or in `tests/Support`.
- Do not install blanket exception or sleep fakes only to make the suite faster.
- Prefer a small real collaborator over a mock that merely echoes the implementation.

## Database in tests

- The default suite runs without a database. A test that needs one MUST use an isolated throw-away database: a temporary SQLite file created through a support trait in `tests/Support` and registered with `DB::init()`, created per test and removed afterwards.
- Tests MUST NOT depend on a shared or real database, on the order of other tests, or on data left by another test.
- Schema is created from the installer or from the test's own minimal definition, never from a hand-copied production dump.

## Running tests

Run the whole suite with `composer test`, or `composer test:parallel` (paratest) for a faster run. Tool caches live under `temp/tools/` (PHPUnit uses `temp/tools/phpunit`).

Narrower runs; prefix them with `XDEBUG_MODE=off` unless you need the debugger or coverage:

```bash
XDEBUG_MODE=off ./vendor/bin/phpunit tests/CQRS/SomeTest.php
XDEBUG_MODE=off ./vendor/bin/phpunit --filter=test_name
XDEBUG_MODE=off ./vendor/bin/phpunit tests/CQRS
```

## CQRS testing

- Tests for command and query handlers live in `tests/CQRS`.
- Tests MUST cover every command handler and query of the application: the expected behaviour, the changes in the database, the returned data, and both successful and unsuccessful paths (validation errors, unauthorized access where applicable).
- A handler can be exercised in two ways:
  - instantiated directly, for handlers without complex dependencies: `new SomeCommandHandler(new SomeDependency())->handle(new SomeCommand(...))`;
  - through `Lsr\CQRS\CommandBus`, so that the handler is resolved from the container the way production code resolves it.
- A test-local `Lsr\CQRS\CommandBus` subclass MAY route a command to a fake or real handler (kept in `tests/Support`), which isolates the unit under test.

## Controller testing

- Tests for HTTP controllers and middleware live in `tests/Http`. A complex controller MAY be split into several test cases by functionality.
- Every test asserts the response status code with an explicit assertion (`self::assertSame(200, $response->getStatusCode())`).
- Every controller endpoint MUST be covered.
- GET endpoints: assert the status and, for Inertia responses, the component and the props that carry behaviour.
- POST, PUT, PATCH and DELETE endpoints: assert the status and the changes in the database or in the response data.
- Cover successful and unsuccessful scenarios, including validation errors and unauthorized access.
- Request DTO validation: assert error keys (`property`), including every custom validation rule.
- Middleware applied to the endpoint (authentication, authorization, rate limits) is covered in its own scenarios.
- An endpoint that is a thin wrapper around a command or query SHOULD repeat the essential scenarios of the underlying handler.
- JSON responses: assert the structure, so the response is guaranteed to contain the expected data.

## Testing workflow

- Narrow run: `XDEBUG_MODE=off ./vendor/bin/phpunit <path>`; full suite: `composer test`.
- Format with `composer cbf` before the pull request ([tools.md](tools.md#formatting-and-lint-autofixes)).
