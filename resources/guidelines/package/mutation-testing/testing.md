---
title: Testing
---
# Project guidelines - Testing

## Mutation testing

Mutation testing checks that tests are useful, not only that they pass. Infection makes small deliberate changes ("mutants") to application code (flips a condition, shifts a boundary, removes a call or an array item) and reruns the tests that cover the changed line. A mutant a test fails on is *killed*; one every covering test survives *escaped*, which means no test pins that behaviour down. Configuration lives in `infection.json5`; the report directory is set there.

It is **local, optional and encouraged**: run it on the changed lines before pushing. It is never a CI gate and never fails on a score; the project sets no threshold. Delete temporary tests first, so the run judges only the tests that stay.

`composer mutation` mutates the application source lines changed against the base branch ([tools.md](tools.md#mutation-testing-local-optional)); keep the local base branch up to date. Every run first collects coverage, then runs the mutants without a coverage driver, executing only the tests that cover each mutated line. Arguments after `--` go to Infection (for example `--show-mutations`). Infection never edits the application source. A change that touches tests only is checked by mutating the file under test in full (`--filter=<source file>`). The commands each stack defines are in [Mutation testing per stack](#mutation-testing-per-stack).

### Reading the output

The console prints one character per mutant:

| Mark | Meaning | Action |
|---|---|---|
| `.` | killed: a test failed | none |
| `M` | escaped: all covering tests passed | review ([Soft gate](#soft-gate)) |
| `U` | not covered: no test runs the line | reported only with `--with-uncovered`; add a behavioural test if the code matters |
| `T` | timed out | usually an infinite loop caused by the mutation; counts as killed |
| `S` | skipped: the covering tests together take longer than `timeout` | treat as untested; the usual cause is a widely used class covered by many slow feature tests, so add a focused unit test |
| `E` / `X` | the mutated code crashed / was not valid PHP | both count as killed; no action |
| `I` | ignored by a skip rule ([Message-only mutants](#message-only-mutants)) | none; not run and not scored |

The summary reports Mutation Code Coverage (mutants on lines any test executes), the Mutation Score Indicator (killed out of all) and the **Covered Code MSI** (killed out of covered), the one that measures test usefulness. It is a trend indicator, not a target. `infection.log` lists every mutant by status with its diff and covering tests; `infection.html` shows the same per file.

### Investigating an escaped mutant

1. Read the diff and ask which behaviour a caller or user would notice if the mutated code shipped.
2. Find the tests that cover the line (listed under the mutant in `infection.log`) and decide whether one should assert that behaviour or a new test is needed.
3. Rerun the coverage step, then only that mutant with Infection's `--coverage=<coverage directory> --skip-initial-tests --only-covering-test-cases --filter=<file> --id=<hash>`, with the debugger off (`XDEBUG_MODE=off`) and enough memory (`php -d memory_limit=4G`). The id stays valid while the source file is unchanged.

| Mutator | Typical escape | Usually |
|---|---|---|
| `ArrayItem` / `ArrayItemRemoval` in DTOs and controllers | a key removed from `toArray()` or from page props | response contract not asserted: assert the prop or payload value |
| `MethodCallRemoval` | a query constraint, `save()`, dispatch or `merge()` removed | side effect not asserted: assert the persisted state, event or normalised input |
| `IncrementInteger` / `DecrementInteger`, `LessThan` family | an off-by-one on a limit or clamp | missing boundary test |
| `NullSafePropertyCall`, `Coalesce` | null handling changed | missing null or absent input scenario |
| `Concat`, `ConcatOperandRemoval`, `Ternary`, `Coalesce` on message text | wording changed | acceptable ([Message-only mutants](#message-only-mutants)) |

### Message-only mutants

Tests do not assert user-facing wording ([testing rules](#error-and-validation-messages)), so a mutant that changes only wording can never be killed and is not worth killing. The skip configuration lives in the project's `infection.json5`, not in a test: it skips only the cases recognisable without ambiguity, each by the narrowest rule that identifies it, with a comment explaining it. Every mutator stays enabled everywhere else.

- A ternary between two multi-word sentence literals and `parent::__construct($message);` in exceptions are skipped by `ignoreSourceCodeByRegex` and reported as `I`.
- Methods that only supply wording (a validation request's message methods) get no mutants: `global-ignore` on the method name. Custom messages never decide whether a rule fires. The names are in [Mutation testing per stack](#mutation-testing-per-stack).

Still generated and judged by the [Soft gate](#soft-gate): request validation rules (removed rules, rule strings built with `.`, boundaries); the conditions that decide whether an error is raised, the field keys, the exception class and `throw`; enum labels and other UI labels (product content); and message text built inside logic (a concatenation or interpolation passed to an error collector or an exception). Accept such a mutant when only wording changes, kill it when a key, a class or a condition changes. One that empties a message list looks like wording but is not: a field with no message is no error at all, so the user is sent back without an explanation. A test kills it without asserting any text (a session-error assertion on the field key, or a non-empty list on the exception).

What the rules cannot do, so that nobody extends them blindly:

- `--mutators=<list>` on the command line switches the per-mutator skip rules off; the wording mutants reappear, so judge them as above.
- `ignoreSourceCodeByRegex` matches one removed line of the mutant diff at a time, in every file. Write it for one whole statement, check that no logic line has the same shape, and add a rule only when the shape is unambiguous; otherwise accept the mutant in review. A new shape is not covered until it is added. `@infection-ignore-all` puts a test-tool annotation into production code; prefer accepting the mutant.
- After changing a rule, run Infection with `--show-mutations` on a few files: the logic mutants of validation rules, conditions and boundaries must still appear.

### Soft gate

Review every escaped mutant on the lines you changed. The review is the gate:

- **Kill it** with a test that asserts the observable behaviour the mutant changes (an outcome, a boundary, an error type, a state transition). Never add a test only to reach a line or raise a score.
- **Accept it** when it is equivalent (the mutated code behaves identically, for example swapped arguments of `max()`) or changes only user-facing wording; list each accepted mutant with its reason in the pull request or slice summary. Never kill a wording mutant by asserting the message. A mutant that changes an error type, a field key, a code or the condition that raises the error is not about wording: kill it. Do not contort code or tests to kill a mutant.
- A mutant killed only by a test that asserts wiring, copies or incidental defaults means that test is not useful; rework it into a behavioural test.

Operating notes:

- A run that reports 100 % within seconds is broken. The usual cause is a PHPUnit configuration passed through `--test-framework-options`, which PHPUnit rejects, so every mutant counts as killed.
- A timed-out mutant counts as killed by default, so a busy machine can hide an escape behind a timeout; `--with-timeouts` counts it as escaped.
- A test suite that uses the database must not run its workers against one shared database during the coverage and the Infection steps, otherwise their collisions count as killed mutants ([Mutation testing per stack](#mutation-testing-per-stack)).

### Mutation testing per stack

#### On Laravel

- Mutated code is `app/`. The Composer scripts are `composer mutation` (changed lines), `composer mutation -- --git-diff-base=<parent-branch>` (a stacked branch) and `composer mutation:full -- --filter=app/Path/File.php` (every mutant in one file). Reports go to `build/infection/`.
- To rerun one escaped mutant: `XDEBUG_MODE=off php -d memory_limit=4G vendor/bin/infection --coverage=build/infection/coverage --skip-initial-tests --only-covering-test-cases --filter=<file> --id=<hash>`.
- `ArrayItemRemoval` in form requests (a rule such as `nullable`, `max:255` or `in:` removed) usually means a missing validation edge test: send the blank, too long or disallowed value and assert the error key.
- Message methods of form requests (`messages()` and the `*Messages()` helpers) get no mutants: `global-ignore` on the method name.
- Still judged by the Soft gate: everything in `rules()` (removed rules, rule strings built with `.`, boundaries).
- Empty-message mutants are killed with `assertSessionHasErrors(['field'])`.
- Run by hand, a Laravel suite that uses the database needs `LARAVEL_PARALLEL_TESTING=1` on both the coverage and the Infection step, otherwise the workers share one test database.
- After changing a skip rule, check it with `composer mutation:full -- --filter=<file> --show-mutations`.

#### On LSR

- Mutated code is `src/`. The tests run with `vendor/bin/phpunit` (prefix `XDEBUG_MODE=off`); `composer mutation` runs Infection ([tools.md](tools.md#mutation-testing-local-optional)).
