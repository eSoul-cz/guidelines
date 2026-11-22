---
title: Testing
---
# Project guidelines - Testing

## Test groups

Evidence tests carry the `evidence` group in addition to their task group. `phpunit.xml` excludes `evidence` from the default run, local and CI, because these tests run once, to produce the evidence for completing a task ([Test evidence](#test-evidence)). A test that also guards permanent behaviour is a regular test of the suite: it carries only the task group and stays in the default run.

## Test evidence

Acceptance criteria are tested and reported with `esoul-cz/phpunit-test-evidence`. The tool runs the tests of one group and writes a Markdown report that someone outside the code base can verify: every test with its TestDox sentence, result and duration, a command to re-run it alone, and optionally the same tests run against the code before the change (the baseline).

### Tying tests to a task

- Each evidence test carries two groups: `#[Group('evidence')]` and a group naming the task or acceptance criterion it proves, `<ticket-prefix><task id>`. The prefix, the ticket URL and the report path are the project's defaults under `extra.test-evidence` in `composer.json` (`ticket-prefix`, `ticket-url`, `output`, `rerun`, `copy`, `php-ini`). If the task id is not known, ask for it before tagging. One test MAY carry several task groups.
- Use the tracker the client or the project reports against. Never put an internal tracker id into a group, a TestDox sentence or a report when the reader cannot open it.
- One test per scenario or criterion, in an acceptance test file of the project's feature tests, named `<Feature>AcceptanceTest` ([Stack specifics](#stack-specifics)). Name the method `test_<criterion id>_<observable behaviour>`; variants use a data provider with a descriptive key per row, passed into the TestDox sentence so that every row is its own line in the report. Scenarios that depend on each other stay separate tests: rebuild the earlier state instead of sharing it.
- Every test has a `#[TestDox('<criterion id> - <condition>: <expected result>')]` sentence in the specification's own terms, describing observable behaviour, not classes or methods. `$parameter` placeholders are filled from the data set.
- A bug gets its regression test first, and you watch it fail against the current code.

### Writing the tests

- Drive the system through its real entry points (an HTTP route, a bus, a console command, a queued job run in the order a worker would run it) and fake only the outside world: external HTTP through the framework's fake with every request recorded and stray requests refused, the clock fixed, configuration the scenario assumes set explicitly.
- Assert what the reader of the report can observe: responses, requests sent to external systems as readable lines, resulting state, job outcomes. Give assertions a message saying what is compared; it becomes the failure reason in the report. Assert that nothing happened where the scenario says so.
- Group names, TestDox sentences and data-set names appear in the report: invented data only, no secrets or personal data.
- A scenario that cannot be automated is listed in the pull request with the reason (needs a real third-party workflow, depends on environment configuration).

### Generating the report

1. When the work is done and the tagged tests pass, ask whether a report should be generated. Never generate one unasked.
2. Generate it from a committed state, with the baseline at the commit the work started from:

```bash
composer test:evidence -- <group> --baseline="$(git merge-base HEAD origin/<base-branch>)"
```

3. An explicit `--exclude-group` replaces the exclusions of `phpunit.xml`, which is how the `evidence` tests run while the other default exclusions still apply. The project's script and `rerun` template (the command printed in the report for each test) both pass it, otherwise the run, or the re-run, finds no test:

```json
"scripts": {
    "test:evidence": ["Composer\\Config::disableProcessTimeout", "test-evidence @additional_args -- --exclude-group sandbox,quarantine"]
},
"extra": {
    "test-evidence": {
        "rerun": "<test command> --exclude-group=sandbox,quarantine --filter {filter}",
        "output": "docs/reports/{group}.md"
    }
}
```

   `<test command>` is the project's way of running one test; [Stack specifics](#stack-specifics) gives it per stack.
4. Read the report before handing it over. Every test passes on the evaluated commit. The tests that prove the change fail at the baseline, and the reason column shows the reported symptom. A test that fails there for an unrelated reason (a helper class added with the change) is not evidence: keep the doubles in the test file or pass `--copy`.
5. The report is written to the configured `output` path and committed in the task branch; the pull request mentions it. Exit code 0 means every test passed, 1 that a test did not (the report is still written), 2 that no report was produced (no tests in the group).
6. Add a short human-written summary above a report that goes to a ticket: what the tests simulate (faked HTTP, in-memory services) and what they do not prove (environment configuration, deployment). The report proves behaviour under the tested conditions; it does not replace checking the target environment.

### Stack specifics

#### On Laravel

- Acceptance tests live in `tests/Feature/<Feature>AcceptanceTest.php`.
- The `rerun` template is `php artisan test --exclude-group=sandbox,quarantine --filter {filter}`.

#### On LSR

- The `rerun` template is `XDEBUG_MODE=off vendor/bin/phpunit --exclude-group=sandbox,quarantine --filter {filter}`; the project's feature-test directory is named in its `phpunit.xml`.
