---
title: CQRS
order: 110
summary: How to write, dispatch and test commands and queries with lsr/cqrs.
---
# Project guidelines - CQRS

CQRS (Command Query Responsibility Segregation) separates read operations from write operations. The project uses `lsr/cqrs` (`Lsr\CQRS\CommandInterface`, `CommandHandlerInterface`, `CommandBus`, `QueryInterface`). Commands, handlers, queries and their DTOs live under `src/CQRS`; command handlers are auto-discovered from `src/CQRS/CommandHandlers` and registered through `config/di/extensions/cqrs.neon`. This guide only adds the project's rules for writing them.

## Layout

| Path                         | Holds                                                         |
| ---------------------------- | ------------------------------------------------------------- |
| `src/CQRS/Commands`          | command DTOs                                                  |
| `src/CQRS/CommandHandlers`   | command handlers (mirror the `Commands` sub-namespaces)       |
| `src/CQRS/CommandResponses`  | DTOs returned by command handlers                             |
| `src/CQRS/Queries`           | query services                                                |
| `src/CQRS/DTOs`              | DTOs returned by queries                                      |

Use the version of `lsr/cqrs` locked in `composer.lock` and read the installed `vendor/lsr/cqrs` source before concluding that a feature is unavailable.

## Writing commands

- A command MUST represent an action or business logic of the application.
- A command SHOULD NOT wrap simple CRUD that an ORM model or a simpler means handles.
- A command class MUST be a data transfer object that carries everything the command needs. It SHOULD NOT contain logic beyond naming its handler.
- A command SHOULD be `readonly` and use constructor property promotion.
- The `handle()` method of a command handler MUST NEVER be declared `void`. Return a meaningful value (a DTO from `src/CQRS/CommandResponses`), or `null`/`bool` when there is none. The return type of the command is declared by the generic `CommandInterface<T>` PHPDoc.
- The handler class MUST contain the logic for executing the command.
- A handler MAY call other commands. It acquires `Lsr\CQRS\CommandBus` through constructor injection, never from the application container:

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
        // handle the command logic

        $this->commandBus->dispatch(new AnotherCommand(/* ... */));

        return true;
    }
}
```

## Writing queries

- A query MUST represent a read operation that retrieves data.
- A query MUST NOT change the state of the application and SHOULD NOT contain business logic.
- A query MUST NOT return an associative array or a `Dibi\Row`; return a DTO or an ORM model. A query that returns a single simple value MAY return it directly.
- Queries are plain query services resolved by the container; there is no query bus.

## Calling commands

- Controllers and other application code dispatch commands through `Lsr\CQRS\CommandBus`, injected through the constructor. This resolves all handler dependencies and keeps handlers replaceable in tests.
- Asynchronous dispatch uses the project's async command bus, which hands the command to the RoadRunner jobs pool; see [architecture.md](architecture.md#background-work). An async command MUST be serializable and safe to retry.
- Writes performed by a command MUST clear the cache tags of the data they change (or go through ORM `save()`, which does).

## Testing

How to test handlers and queries is described in [testing.md](testing.md#cqrs-testing).
