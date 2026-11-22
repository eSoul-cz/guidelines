---
title: CQRS
order: 110
summary: How to wire, write, dispatch and test commands and queries with lsr/cqrs.
---
# Project guidelines - CQRS

CQRS (Command Query Responsibility Segregation) separates read operations from write operations. The project uses `lsr/cqrs` (`Lsr\CQRS\CommandInterface`, `CommandHandlerInterface`, `CommandBus`, `AsyncCommandBusInterface`, `QueryInterface`). The package supplies contracts and a synchronous command bus; the application owns folder layout, handler registration, query execution and any asynchronous transport. This guide only adds the project's rules.

Use the version of `lsr/cqrs` locked in `composer.lock` and read the installed `vendor/lsr/cqrs` source (`CommandBus.php`, `CommandInterface.php`, `DI/CqrsExtension.php`) before concluding that a feature is unavailable.

## Wiring

- Register the extension once in a focused NEON file: `extensions: { cqrs: Lsr\CQRS\DI\CqrsExtension }`. It registers an autowired `Lsr\CQRS\CommandBus` and expects the core application service to be named `app`.
- Every command handler is a DI service. The package does not discover or scan handlers: register them with the project's existing Nette `search:` convention or with explicit definitions.
- `cqrs.asyncBus` is `null` unless the project provides an async transport. The async adapter (command bus to RoadRunner jobs) is **project-owned**, implements `Lsr\CQRS\AsyncCommandBusInterface` and is referenced by its service name; nothing connects the bus to the jobs pool automatically. Without it, `dispatchAsync()` throws a `RuntimeException`.

## Layout

The package imposes no folder layout. When the project uses CQRS it SHOULD keep one:

| Path                        | Holds                                                    |
| --------------------------- | -------------------------------------------------------- |
| `src/CQRS/Commands`         | command DTOs                                             |
| `src/CQRS/CommandHandlers`  | command handlers (mirror the `Commands` sub-namespaces)  |
| `src/CQRS/CommandResponses` | DTOs returned by command handlers                        |
| `src/CQRS/Queries`          | query services                                           |
| `src/CQRS/DTOs`             | DTOs returned by queries                                 |

## Writing commands

- A command MUST represent an action or business logic of the application and SHOULD NOT wrap simple CRUD that an ORM model handles.
- A command class MUST be a data transfer object that carries everything the command needs. It SHOULD NOT contain logic beyond naming its handler.
- A command SHOULD be `readonly` and use constructor property promotion. Keep it serializable when async dispatch is possible: no open resources, closures, DB connections or service instances.
- `getHandler()` returns a non-empty handler class name or DI service name. A class name is resolved by DI type (the first matching service wins, so do not register two handlers of one type); a service name is resolved by name.
- The generic `CommandInterface<T>` PHPDoc declares the result type; it is not enforced at runtime, so the handler returns what the PHPDoc promises.
- The handler class MUST contain the logic for executing the command and MUST guard the concrete command type at the start of `handle()` (an `instanceof` check or an assertion), so a mismatch never becomes partial work.
- `handle()` MUST NOT be declared `void`. Return a meaningful value (a DTO from `src/CQRS/CommandResponses`), or `null`/`bool` when there is none.
- Handlers receive their dependencies through the constructor. A handler MAY call other commands; injecting `Lsr\CQRS\CommandBus` through the constructor is recommended over resolving it from the application container:

```php
namespace App\CQRS\CommandHandlers\Some;

use App\CQRS\Commands\Some\SomeCommand;
use Lsr\CQRS\CommandBus;
use Lsr\CQRS\CommandHandlerInterface;
use Lsr\CQRS\CommandInterface;

class SomeCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly CommandBus $commandBus,
    ) {
    }

    public function handle(CommandInterface $command): bool
    {
        assert($command instanceof SomeCommand);

        // handle the command logic

        $this->commandBus->dispatch(new AnotherCommand(/* ... */));

        return true;
    }
}
```

## Writing queries

- A query MUST represent a read operation that retrieves data, MUST NOT change the state of the application and SHOULD NOT contain business logic.
- A query returns a DTO or an ORM model, never an associative array. A query that returns one simple value MAY return it directly.
- `QueryInterface` is a marker. **There is no query bus**: queries are plain query services resolved by the container, and none is invented unless a real dispatch seam earns it.
- Queries read through DB/ORM cache tags (or `cache: false` where freshness requires it); see the caching rules in the architecture guideline.

## Calling commands

- Controllers and other application code dispatch commands through `Lsr\CQRS\CommandBus`, injected through the constructor. This resolves all handler dependencies and keeps handlers replaceable in tests.
- `dispatch()` runs the handler synchronously and returns its result; exceptions propagate to the caller.
- `dispatchAsync()` hands the command to the configured async adapter and returns **nothing**. A successful enqueue proves the command was queued, never that it was processed; code MUST NOT report the work as done after `dispatchAsync()`.
- A command queued as a job follows the job payload rules of the architecture guideline (identifiers and enums, no services or connections); an ID-based payload is a safer deployment seam than a serialized command. Acknowledgement and retry behaviour belong to the async adapter, not to a reusable handler.
- Writes performed by a command MUST clear the cache tags of the data they change (or go through ORM `save()`, which does).

## Testing

- Tests for command handlers and queries live in `tests/CQRS`. How many scenarios a handler gets follows [How much to test](testing.md#how-much-to-test); a handler test asserts the expected behaviour, the changes in the database and the returned data.
- A handler can be exercised in two ways:
  - instantiated directly, for handlers without complex dependencies: `new SomeCommandHandler(new SomeDependency())->handle(new SomeCommand(...))`;
  - through `Lsr\CQRS\CommandBus`, so that the handler is resolved from the container the way production code resolves it.
- Handler registration is tested through the real compiled container: a command resolves to its handler, and a missing or invalid handler fails.
- A test-local `Lsr\CQRS\CommandBus` subclass MAY route a command to a fake or real handler (kept in `tests/Support`), which isolates the unit under test.
- An async adapter is tested by running the real jobs worker against a disposable queue and asserting the state transition, the failure and the retry or acknowledgement behaviour.
