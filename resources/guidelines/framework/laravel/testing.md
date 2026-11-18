---
title: Testing
---
# Project guidelines - Testing

On this stack tests are PHPUnit 13 classes in `tests/` under the `Tests` namespace.

## Named exceptions

- Three tests are named exceptions on this stack: the layer-dependency test and its detection test
  ([Architecture tests](#architecture-tests)) and the schema identifier length test
  ([Database in tests](#database-in-tests)). Any further one needs its own ADR.

## Error and validation messages

- Form request or controller validation: `assertSessionHasErrors(['field'])` (web) or
  `assertJsonValidationErrors(['field'])` (JSON) with the field keys only, together with the
  status or redirect (`assertStatus(422)`, `assertRedirect()`).
- An exception that carries field errors: `array_keys($exception->errors())` and
  `assertNotEmpty($exception->errors()['field'])`. Laravel reports a field with an empty message
  list as no error at all, so the second assertion is what keeps the user from being sent back
  without an explanation.
- An error flashed from a caught exception: `assertRedirect()` and `assertSessionHas('error')`
  without a value.
- These are banned when they carry error text: `expectExceptionMessage()`,
  `expectExceptionMessageMatches()`, `getMessage()` compared with a literal,
  `assertSessionHasErrors(['field' => '...'])`, `assertJsonValidationErrors(['field' => '...'])`,
  `assertJsonPath('errors.field.0', '...')`, `assertSessionHas('error', '...')` and `assertSee()`
  on an error text.

## Writing tests

- Test method names are `snake_case` and describe the behaviour (`test_open_orders_can_be_cancelled`);
  Pint enforces it (`php_unit_method_casing`).
- Use PHPUnit attributes (`#[DataProvider]`, `#[Group]`), not doc-comment annotations.
- Use `assertSame()` for strict values and object identity; compare object state with an
  appropriate equality assertion or explicit typed values.
- Data providers are `public static` camelCase methods in the same test class, return a generator
  with descriptive keys, and document the shape of what they yield:

```php
/** @return iterable<string, array{0: string, 1: int}> */
public static function provideScenarios(): iterable
{
    yield 'empty input' => ['', 0];
}
```

- Instance data a provider needs is passed as a closure; the closure's shape is documented on both
  the provider and the consuming test.

## Test layout

The suite has two directories: `tests/Unit/` and `tests/Feature/`. Feature tests of HTTP
endpoints go in `tests/Feature/Http/`, those of command and query handlers in
`tests/Feature/CQRS/`. Any finer structure (per module, per area, support helpers) is the
project's choice.

## Unit tests

- A unit test lives in `tests/Unit/` and SHOULD extend `PHPUnit\Framework\TestCase` and SHOULD NOT
  touch the database or the network; it then runs in milliseconds and needs no application.
- Code that needs the container, the configuration or the database is covered by a feature test.
- A unit test checks one behaviour; variants of a rule are data-provider cases here, not more
  feature tests ([Keeping the integration run short](#keeping-the-integration-run-short)).

## Feature tests

- A feature test lives in `tests/Feature/`, extends `Tests\TestCase` and goes through the real
  entry point: an HTTP route, a bus (`CommandBusInterface`, `QueryBusInterface`) or an Artisan
  command.
- `Tests\TestCase` is the shared base: it boots Laravel, refuses HTTP requests nobody faked
  (`Http::preventStrayRequests()`) and uses `LazilyRefreshDatabase`
  ([Database in tests](#database-in-tests)). A feature test does not add a database trait of its
  own.
- A feature test is a scenario: one realistic flow, all its observable outcomes asserted, only the
  data it asserts on created.

## Test doubles

- Fake the Laravel facades the test touches (`Http::fake()`, `Queue::fake()`, `Mail::fake()`,
  `Event::fake()`) in the test itself; the base class keeps only `Http::preventStrayRequests()`.
- A test that writes files uses `Storage::fake()`, never a real disk.

## Database in tests

- Tests run on SQLite `:memory:` by default (`phpunit.xml` forces it, so values from the shell or
  the container's `.env` never leak into a run). `composer test` and Jenkins stay on it, and no
  database service is needed. MariaDB, MySQL or PostgreSQL for the whole suite is a project
  opt-in that the project documents in its own guidelines; when the suite runs on the production
  engine, the identifier length test below is unnecessary.
- Feature tests use `LazilyRefreshDatabase`, set once in the shared `Tests\TestCase`. It migrates
  once per worker process (for `:memory:` the migrated connection is cached) and wraps each test
  in a transaction that is rolled back, but only for a test that touches the database, so tests
  that never do pay nothing. `RefreshDatabase` does the same eagerly for every test.
  `DatabaseTransactions` is not used: it needs a database that is already migrated and cannot
  work with `:memory:`. Unit tests never touch the database.
- The reset is cheap. What makes a feature test cost is booting the application and building its
  data: keep the data minimal.
- Every Eloquent model has a factory that produces a valid minimal record, with states for
  meaningful variants. Tests build their data through factories, never raw inserts, and set what
  they care about explicitly. Factories, seeders, migrations and schema are not tested in
  isolation; their behaviour is proven through the commands, queries and endpoints that use them.
  Test data is invented.
- **Schema identifier length.** MariaDB and MySQL reject table, column, index and foreign key
  names longer than 64 characters; SQLite accepts them, so a too-long generated name passes the
  suite and fails the production migration. One named test,
  `tests/Feature/Architecture/DatabaseIdentifierLengthTest.php`, runs the migrations and asserts
  that every identifier they create stays within 64 characters. It runs the migrations itself, so it
  extends Laravel's own `TestCase`, not `Tests\TestCase`, whose transaction would block it. A
  migration gives a long index or foreign key an explicit short name.
- **Production engine.** A project that keeps SQLite tests while production runs another engine
  MAY mark engine-dependent tests with `#[Group('db-engine')]` ([Test groups](#test-groups)). The
  project provides the switch that points that run at a throwaway database whose name ends in
  `_testing`, never the development database.

## Running tests

- `composer test` runs `php artisan test --parallel`, which gives each worker its own database
  token and is much faster than a serial run.
- `phpunit.xml` sets `memory_limit` for every test process, workers included, through `<ini>`.
  PHPUnit applies `<ini>` after `php -d`, so `-d memory_limit` has no effect on a test run;
  change the value in `phpunit.xml`.
- Narrow runs: `php artisan test --parallel tests/Feature/<Area>` for an area,
  `php artisan test --filter=<TestName>` for one behaviour. Prefix a run with `XDEBUG_MODE=off`
  unless you need the debugger or coverage.
- `composer coverage` needs a coverage driver (PCOV or Xdebug) and writes its HTML report to
  `test-coverage/`, which is not committed.
- The commands CI runs are listed in [tools.md](tools.md#read-only-verification).

## Test groups

- A test joins a group with the PHPUnit attribute (`#[Group('sandbox')]`). `phpunit.xml` excludes
  `sandbox` and `quarantine` from the default run.
- Run a group on demand:

```bash
php artisan test --group=sandbox      # provider test APIs; skips without credentials
php artisan test --group=quarantine   # flaky tests waiting for their fix
php artisan test --group=db-engine    # only in a project that opted in
```

## CQRS testing

- Handler tests live in `tests/Feature/CQRS`. Every command handler and query is covered: the
  expected behaviour, the changes in the database, the returned data, and both successful and
  unsuccessful paths (validation errors, business rule violations, unauthorized access where it
  applies).
- A handler is called in one of two ways:
  - instantiated directly, for a handler without complex dependencies;
  - through the buses taken from the container, so that dependencies resolve the way production
    code resolves them. This is the preferred form:

```php
$commandBus = $this->app->get(CommandBusInterface::class);
$queryBus = $this->app->get(QueryBusInterface::class);
```

- A fake handler can replace a real one for the test by registering it on the bus:
  `$commandBus->registerHandler(SomeCommand::class, FakeSomeCommandHandler::class);`
- How handlers are written is in [cqrs.md](cqrs.md#testing).

## Controller testing

- Controller tests live in `tests/Feature/Http`. A complex controller MAY be split into several
  test classes by functionality.
- Every endpoint is covered, successful and unsuccessful scenarios alike, including validation
  errors, unauthorized access and the middleware applied to it (authentication, authorization,
  rate limits).
- Every test asserts the status explicitly (`assertOk()`, `assertStatus()`, `assertForbidden()`),
  before anything else: with debug off an error page is an Inertia response too.
- Authenticate with `actingAs()` when the route requires it.
- GET endpoints: the status and, for Inertia responses, the component and the props that carry
  behaviour (`assertInertia()`).
- POST, PUT, PATCH and DELETE endpoints: the status and the changes in the database or in the
  response data.
- Validation: the field keys (`assertSessionHasErrors(['field'])`,
  `assertJsonValidationErrors(['field'])`), including every custom rule, never the message.
- JSON responses: the structure (`assertJsonStructure()`), so the payload is guaranteed to hold
  the expected data.
- An endpoint that only wraps a command or query repeats the essential scenarios of the
  underlying handler.

## Architecture tests

The stack requires one pair of architecture tests, and they are the named exceptions to the rule
against architecture tests in [Named exceptions](#named-exceptions):

- `tests/Unit/Architecture/LayerDependencyTest.php` reads every PHP file under `app/` (with the
  tokenizer, not reflection) and asserts that the list of violations is empty:

| Rule | Checked in | Forbidden |
|---|---|---|
| the domain core is framework-free | `App\Domain`, except `App\Domain\CQRS` and `App\Domain\Providers` | `Illuminate\Database`, `Illuminate\Support\Facades`, `Illuminate\Http`, `App\Application`, `App\Infrastructure`, the container and the session (including the `app()`, `resolve()`, `request()` and `session()` helpers) |
| handlers read only models from infrastructure | `App\Domain\CQRS` | anything in `App\Infrastructure` outside `App\Infrastructure\Models` |
| the application layer is independent of infrastructure | `App\Application` | anything in `App\Infrastructure` |

`App\Domain\Providers` is exempt from every rule: it is the one place in `App\Domain` that may
use the container, facades and `App\Infrastructure` ([architecture.md](architecture.md#appdomainproviders---service-providers)).

- `tests/Unit/Architecture/LayerRuleDetectionTest.php` feeds each rule in-memory samples (one per
  kind of breach and one compliant sample) and asserts the exact violations. It is what makes a
  green `LayerDependencyTest` mean something. A new rule or kind of breach gets a sample there.
- A failure lists every violation as `path:line uses <dependency> - <rule>`. Fix the code, not the
  rule. A rule changes only together with [architecture.md](architecture.md#dependency-direction)
  and, where it is a decision, its ADR.
- A project MAY add rules for its own module boundaries to the same test; each such rule is
  recorded in the project's architecture ADR.
