---
title: Testing
---
# Project guidelines - Testing

Tests are PHPUnit 13 classes in `tests/` under the `Tests` namespace; Pest is not used.

## What belongs in the suite

- Two architecture tests are named exceptions on this stack: the layer-dependency test and the
  schema identifier length test ([Architecture tests](#architecture-tests),
  [Database in tests](#database-in-tests)). Any further one needs its own ADR.

## Test layout

```text
tests/
├── TestCase.php          # base for feature tests: boots Laravel, refuses stray HTTP requests
├── Feature/              # the application as a whole; may use the database
│   ├── Http/             #   controllers, Inertia responses, form request validation
│   ├── CQRS/<Module>/    #   command and query handlers through the buses
│   ├── Authorization/    #   one case per protected surface, per role
│   ├── Console/          #   Artisan commands
│   └── Architecture/     #   the named schema identifier length test
├── Unit/                 # one class or method in isolation; no database, no external service
│   ├── Domain/<Module>/  #   value objects, enums, calculators
│   └── Architecture/     #   the layer rules over app/
└── Support/              # helpers shared by tests
```

- **Unit tests** extend `PHPUnit\Framework\TestCase` when Laravel need not boot and
  `Tests\TestCase` when the test needs the application. `Unit/Domain/<Module>` mirrors the modules
  of [architecture.md](architecture.md#modules).
- **Feature tests** go through the real entry point: an HTTP route, a bus (`CommandBus`,
  `QueryBus`) or an Artisan command.
- Test doubles isolate the code under test from external services. Behaviour-specific fakes stay
  in the individual test; only suite-wide safety guards such as `Http::preventStrayRequests()`
  live in the base test case. Do not install blanket exception or sleep fakes to make tests
  faster.

## Database in tests

- Tests run on SQLite `:memory:` by default (`phpunit.xml`). `composer test` and Jenkins stay on
  it, and no database service is needed. Each parallel worker prepares its own in-memory schema.
- A test that touches the database uses `RefreshDatabase`, so every test starts from a clean
  schema. `DatabaseTransactions` is only for a suite whose schema and shared reference data are
  prepared once per process and whose tests must still roll back. A test that must commit because
  other processes have to see its data (a concurrency test) cleans up after itself and says why
  in a comment. Unit tests never touch the database.
- Every model that tests need has a factory producing a valid minimal record. The test sets what
  it cares about explicitly. Factories, seeders, migrations and schema are not tested in
  isolation; their behaviour is proven through the commands, queries and endpoints that use them.
  Test data is invented.
- **Schema identifier length.** MariaDB and MySQL reject table, column, index and foreign key
  names longer than 64 characters; SQLite accepts them, so a too-long generated name passes the
  suite and fails the production migration. One named architecture test,
  `tests/Feature/Architecture/DatabaseIdentifierLengthTest.php`, migrates the schema and asserts
  that every identifier the migrations create stays within the production engine's limit (64
  characters for MariaDB and MySQL). A migration gives a long index or foreign key an explicit
  short name.
- **Production engine.** When production runs MariaDB, MySQL or PostgreSQL, behaviour that
  depends on the engine (locks, concurrency, ordering and `NULL` order, collation, JSON
  functions, raw SQL, identifier and column lengths) is covered by tests in the `db-engine` group.
  They run in the default SQLite run and again on the production engine against a throwaway
  database whose name ends in `_testing`, never the development database ([tools.md](tools.md#test-groups)):

```bash
docker compose exec -e DB_CONNECTION=<mariadb|mysql|pgsql> -e DB_DATABASE=<name>_testing laravel \
  php artisan test --group=db-engine
```

## Running tests

- `composer test` runs the whole suite in parallel with Paratest and with Xdebug off, which is
  much faster than a serial run with Xdebug loaded. It clears the configuration cache first.
- `phpunit.xml` disables the CQRS discovery cache, so stale files in
  `storage/framework/cache/discovery` cannot leak into the suite, and sets a `memory_limit` for
  every test process (workers included).
- Narrow runs: `php artisan test --parallel tests/Feature/<Area>` for an area,
  `php artisan test --filter=<TestName>` for one behaviour. Prefix a run with `XDEBUG_MODE=off`
  unless you need the debugger or coverage.
- Parallel runs go through `php artisan test --parallel` or `composer test`, which give each
  worker its own database token. A test that writes files uses `Storage::fake()`, never a real
  disk.
- Coverage (`composer coverage`, when the project defines it) needs a coverage driver and writes
  to a gitignored directory. Coverage shows which lines run, not whether the tests check
  anything.
- The commands CI runs are listed in [tools.md](tools.md#read-only-verification).

## Architecture tests

The stack requires one pair of architecture tests, and they are the named exceptions to the rule
against architecture tests in [What belongs in the suite](#what-belongs-in-the-suite):

- `tests/Unit/Architecture/LayerDependencyTest.php` reads every PHP file under `app/` (with the
  tokenizer, not reflection) and asserts that the list of violations is empty:

| Rule | Checked in | Forbidden |
|---|---|---|
| the domain outside CQRS is framework-free | `App\Domain`, except `App\Domain\CQRS` | `Illuminate\Database`, `Illuminate\Support\Facades`, `Illuminate\Http`, `App\Infrastructure`, the container and the session (including the `app()`, `resolve()`, `request()` and `session()` helpers) |
| handlers read only models from infrastructure | `App\Domain\CQRS` | anything in `App\Infrastructure` outside `App\Infrastructure\Models` |
| the application layer is independent of infrastructure | `App\Application` | anything in `App\Infrastructure` |

- `tests/Unit/Architecture/LayerRuleDetectionTest.php` feeds each rule in-memory samples (one per
  kind of breach and one compliant sample) and asserts the exact violations. It is what makes a
  green `LayerDependencyTest` mean something. A new rule or kind of breach gets a sample there.
- A failure lists every violation as `path:line uses <dependency> - <rule>`. Fix the code, not the
  rule. A rule changes only together with [architecture.md](architecture.md#dependency-direction)
  and, where it is a decision, its ADR.
- A project MAY add rules for its own module boundaries to the same test; each such rule is
  recorded in the project's architecture ADR.

## Writing tests

- Use PHPUnit attributes (`#[DataProvider]`, `#[Group]`), not doc-comment annotations.
- Data providers are `public static` methods in the same test class, return a generator with
  descriptive keys, and document the shape of what they yield:

```php
/** @return iterable<string, array{0: string, 1: int}> */
public static function provideScenarios(): iterable
{
    yield 'empty input' => ['', 0];
}
```

- Instance data a provider needs is passed as a closure; the closure's shape is documented on both
  the provider and the consuming test.
- When testing HTTP endpoints:
  - authenticate with `actingAs()` when the route requires it;
  - assert the status explicitly (`assertOk()`, `assertStatus()`, `assertForbidden()`);
  - for Inertia responses assert the component and the props with `assertInertia()`;
  - for validation assert the field keys (`assertSessionHasErrors(['field'])`,
    `assertJsonValidationErrors(['field'])`), never the message;
  - for JSON assert the structure (`assertJsonStructure()`).
- Handlers are tested through the buses from the container ([cqrs.md](cqrs.md#testing)).

## Test groups

- A test joins a group with the PHPUnit attribute (`#[Group('db-engine')]`). `phpunit.xml`
  excludes `sandbox` and `quarantine` from the default run and keeps `db-engine` in it.
- A `quarantine` test carries the URL of its issue in a comment on the attribute.
- `db-engine` is used when production runs a different engine than the tests
  ([Database in tests](#database-in-tests)); when the suite already runs on the production engine
  it is unnecessary.
