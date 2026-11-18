---
title: Testing
---
# Project guidelines - Testing

## Mutation testing

Mutation testing checks that tests are useful, not only that they pass. The stack's tool
(Infection for PHP, Stryker for TypeScript; see [tools.md](tools.md)) changes production code one
small edit at a time and reruns the covering tests. A mutant no test notices has *escaped*.

It is a **local soft gate on changed lines**: it runs before a pull request is opened and before a
slice is reported finished, not in CI, and it never fails on a score. The project sets no score
threshold; the review is the gate. Delete temporary tests first, so the run judges only the tests
that stay.

Every escaped mutant on a line the pull request changed is either:

- **killed** by a test that asserts the observable behaviour the mutant changes (an outcome, a
  boundary, an error type, a state transition). Never add a test only to reach a line or raise a
  score; or
- **justified** in the pull request: equivalent code (the mutated code behaves identically), or a
  change of error wording only. Never kill a wording mutant by asserting the message
  ([testing.md](testing.md#error-and-validation-messages)). A mutant that changes an error type, a
  field key, a code or the condition that raises the error is not about wording; kill it. Do not
  contort code so that a test can kill a mutant.

A mutant killed only by a test that asserts wiring, copies or incidental defaults means that test
is not useful; rework it into a behavioural test.

List the accepted mutants, each with its reason, in the pull request or the slice summary.
