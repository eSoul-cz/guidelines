# Changelog

All notable changes to `esoul-cz/guidelines` are recorded here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses [Semantic Versioning](https://semver.org/). A release is a pull request that changes only this file; the tag follows the merge.

## [Unreleased]

### Changed

- Seeders in staging and production (`general`, `framework/laravel`): clarifies the 0.5.3 rule on the owner's correction. What may run there, by hand only, is any **deliberate** seeder, not general random data to fill the database: usually the production seeder (what every environment needs: idempotent, create-only, no account) and **demo seeders** (deliberate demo content). The list is not exhaustive; a project MAY define other environments and seeder sets. A deliberate seeder SHOULD NOT depend on Faker, because deployed images install no development dependencies, and it creates no account with credentials outside local development. What stays local-only, MUST NOT run in staging or production and refuses to run there is the **general** seeder, the `DatabaseSeeder`-style seeding of synthetic development data. 0.5.3 said a seeder that uses Faker or synthetic data is local-only, which would also have barred a deliberate demo seeder. The Laravel file now describes the production seeder, the demo seeder and `DatabaseSeeder` separately.
- Deployment (`framework/lsr`): `app:install` also drops indexes that `migrations.neon` does not declare, so every index is declared there; an index added by hand disappears on the next deployment.

## [0.5.4] - 2026-11-25

The command-construction rule of 0.5.3 is relaxed on the owner's correction: the constructor holds the promoted properties, a `to<Something>()` method on the source object is the recommended way to create a command from another object, and a public static factory method on the command is valid but not recommended. Layers changed: `framework/laravel`, `package/lsr-cqrs`. A patch release: `composer update esoul-cz/guidelines` and `sync`.

Skills affected: none.

### Changed

- Command construction (`framework/laravel`, `package/lsr-cqrs`): relaxes the v0.5.3 rule on the owner's correction. The constructor holds the promoted properties (SHOULD hold only them) and does not take another object; creating the command by passing the values to it directly is fine. Creating it from another object: the recommended way is a `to<Something>()` method on that other object (a form request's `toCommand()`, `toQuery()` or `to<Dto>()`); a public static factory method on the command (`fromOrder($order)`, also `fromRequest()`) is also valid, but not recommended. 0.5.3 said MUST and required a named static constructor for any other source; 0.5.2 said a `fromRequest()` factory SHOULD NOT be used. The example in the CQRS file is removed.

## [0.5.3] - 2026-11-25

The production start entrypoint and the monitored production deployment become rules, and the command-construction wording of 0.5.2 is corrected. Layers changed: `general`, `framework/laravel`, `framework/lsr`, `framework/astro`, `package/lsr-cqrs`. The owner decided one patch release for the corrections and the new rules: `composer update esoul-cz/guidelines` and `sync`; the constraint `^0.5` already allows it.

Skills affected: `esoul-workflow` (one Guardrails bullet pointing at the new rules; no other skill restated them).

### Fixed

- Command construction (`framework/laravel`, `package/lsr-cqrs`): the 0.5.2 mapping rule called building a DTO in the command's constructor "possible" and said a `fromRequest()` factory SHOULD NOT be used, which left the constructor's job open. A command's constructor now holds only its promoted properties and MUST NOT take another object to build itself from (the same for a query that has a constructor and for every DTO a request or handler hands over); building one from another object is a static named constructor on the command (`PlaceOrderCommand::fromCart($cart)`). A form request still maps itself with its public `toCommand()`, `toQuery()` or `to<Dto>()`, and the rule gives the reason: a command lives in `App\Domain\CQRS` and must not depend on a request of `App\Infrastructure`. A new "Constructing commands and queries" section in the CQRS file holds the rule with an example; Requests and validation links to it. The app templates' commands already followed it (no command constructor takes another object).
- Rollback (`framework/lsr`): "rolling back means redeploying an earlier image by its digest or Git tag" was stale since 0.5.1 and contradicted the scheme. The LSR file now points to Rollback in the deployment scheme (the pipeline's `previous-<stack>` image) and to Monitoring a deployment.

### Changed

- Production start (`general`, `framework/laravel`, `framework/lsr`, `framework/astro`): a new section "The production start entrypoint" in the deployment scheme holds the rules, and the framework files carry the stack detail and link to it. (1) The entrypoint SHOULD be quick: it starts the server promptly and runs heavy data tasks (recalculations, syncs, data cache warm-ups, imports) in the background (a one-shot Supervisor program or `&`), never before the server; migrations and the steps the application needs before serving (config and route caching, the storage link, the LSR `/prepare-runtime`) MAY stay in front. (2) Migrations run forward-only and non-destructively, and **a failing migration MUST NOT block the deployment**: the container starts and the failure is logged loudly. This reverses the LSR and Astro SSR rule "a failure exits the container instead of serving errors"; Laravel already behaved this way. (3) The entrypoint MUST NOT run any seeder; seeding is a deliberate act of a developer by hand in the container's terminal. (4) Only a specialised set of seeders (idempotent, create-only, what every environment needs: the production seeder, run by hand) MAY run in staging or production; a seeder that uses Faker or synthetic data is local-only, MUST NOT run there and refuses to. Laravel: the production seeder is its own class (`ProductionSeeder`), `DatabaseSeeder` is the local seeder. LSR: `app:install` seeds nothing and is never run with `--fresh`. Astro SSR: the run-once seeds are started with `pnpm seed`, not by the start.
- Monitoring a deployment (`general`): a new section. Every deployment to a real production environment MUST be monitored by the person who pushed or started it (health, the changed feature, logs and errors, the migration output), who is ready to roll back or ship a hotfix; for staging it is a SHOULD. Workflow › From issue to merge points to it.

## [0.5.2] - 2026-11-25

Three contradictions removed: the rollback is the named exception to "never by hand in Komodo", an enum field has one representation (the backing value), and a form request maps itself to a DTO. Layers changed: `general`, `framework/laravel`, `framework/astro`, `package/laravel-inertia`. A patch release: `composer update esoul-cz/guidelines` and `sync`.

Skills affected: none (no skill of eSoul-cz/skills restates the rollback, the enum representation or the request mapping; `esoul-review` only points at the unchanged Partial updates rule).

### Fixed

- Deployment (`general`): "never by hand in Komodo" and the Rollback bullet contradicted each other. The rollback is now the one named exception, written the same way in "How an application reaches production", in Rollback and in the list of what not to introduce: in an incident, devops (the people with Komodo access) set the stack's `image_tag` to `previous-<stack>` and deploy the stack in Komodo. Afterwards the hotfix issue records the rollback (when, which image, who), the stack goes back to the manifest's `image_tag` with the next deploy of the fix through Jenkins (an `apply` resets `image_tag` but is not a deploy, so the rolled-back containers keep running until that deploy), and until then nobody redeploys the stack by hand at `latest`, which still points at the broken image. Workflow › Hotfix and the Astro delivery path (`previous-<stack>` instead of "digest or Git tag") say the same and link to it. ([#16](https://github.com/eSoul-cz/guidelines/issues/16))
- Requests and validation (`framework/laravel`): an enum field had three representations (`enum()` in `passedValidation()`, "only scalars", and `type-of<EnumClass>`). It holds the backing value, validated with `Rule::enum()`, annotated `value-of<EnumClass>` (PHPStan has no `type-of<>`; `value-of<>` resolves to the backing type), and the mapping method turns it into the case with `EnumClass::from()`; `enum()` is no longer in the accessor list and one example shows the property, the rule and the mapping. ([#17](https://github.com/eSoul-cz/guidelines/issues/17))

### Changed

- Requests and validation (`framework/laravel`): one SHOULD for the mapping: a form request maps itself to a DTO through a public `to<Something>()` method (`toCommand()` generally, `toQuery()`, `to<Dto>()`) that builds it from the validated scalars, converting enums with `::from()`, dates per the time-zone rule and passing the "not sent" state in a partial update. Architecture and CQRS (`framework/laravel`) and Inertia frontend (`package/laravel-inertia`) link to it instead of describing the mapping. ([#17](https://github.com/eSoul-cz/guidelines/issues/17))

## [0.5.1] - 2026-11-25

Two rules corrected: a rollback deploys the image the pipeline recorded as `previous-<stack>`, and a form request transforms and merges only the fields the client sent. Layers changed: `general`, `framework/laravel`. A patch release: `composer update esoul-cz/guidelines` and `sync`.

Skills affected: none (no skill of eSoul-cz/skills restates the changed rules).

### Fixed

- Deployment (`general`): Rollback no longer takes "the digest the registry lists for the push before the current one" as the previous release: a build whose `Deploy` failed has pushed images that never ran, so that push could be the wrong one. The pipeline records what runs (`jenkins-libraries` 2026-11-25: after a successful `Deploy`, `deployed-<stack>` and `previous-<stack>` tags in the registry) and a rollback deploys `previous-<stack>`; images of a build whose `Deploy` failed are never recorded, and the old lookup remains only for deployments before the recording began. Workflow › Hotfix points to the same tag. ([#14](https://github.com/eSoul-cz/guidelines/issues/14))
- Requests and validation (`framework/laravel`): "Every property is transformed in `passedValidation()` and set with `merge()`" contradicted "Partial updates: not sent or cleared". Only the keys the client sent (`has()` / `array_key_exists`) are transformed and merged, and a sent `null` stays `null`, because every typed accessor fills a default for a missing or null key (`boolean()` gives `false`, `integer()` gives `0`, `string()` gives `''`); the rule has a short example. ([#15](https://github.com/eSoul-cz/guidelines/issues/15))

## [0.5.0] - 2026-11-24

Project exceptions (an accepted ADR lets a project deviate from a rule, validated by `check`), the `Context: Result` title shape, a hotfix path, a rollback rule and the pipelines' guidelines check are now guideline rules; below 1.0 every minor needs the constraint raised (`^0.5`).

Skills affected: `esoul-guidelines` (the update procedure for a 0.x minor, the CI check, project exceptions), `esoul-workflow` and `esoul-task-work` (title shape `Context: Result`, hotfix), `esoul-task-create` and `gitmoji-commit` (title shape `Context: Result`), `esoul-project-lifecycle` (numbers and pitfalls moved into the guidelines; retrofit conflicts become project exceptions).

### Changed

- Deployment (`framework/laravel`): the pull-request test build always uses the layer cache and pulls its base images first (`testDockerBuild: [pull: false]` switches the pull off). `testDockerBuild: [noCache: true]` is no longer offered: `jenkins-libraries` deprecated the key on 2026-11-23 and ignores it.
- Workflow (general and `profile/internal`): the title shape is `Context: Result` (for example `Cart: Recalculate shipping when the address changes`), and the pull request title states the same result in English. How the title is mapped into a commit standard stays skill procedure (`esoul-task-create`, `gitmoji-commit`).
- Tools (`framework/laravel`, `framework/lsr`): `esoul-guidelines check` is listed among the read-only checks (a "Guidelines" subsection), as the stage `Guidelines check` that the shared `laravelPipeline` and `lsrPipeline` now run by default for a project with `esoul-guidelines.lock`.
- Deployment (`framework/astro`): "Planned steps" became "Pipeline steps" and describes what exists: `astroPipeline` (static and image modes, the CMS callback, the `Guidelines check` stage), `deployStaticSite` with its live-site check and switch back, `rollbackStaticSite`, and the one release script of both paths. The intro no longer says there is no one-call Astro pipeline, `astroCmsPipeline` is not a step (the image path is `astroPipeline`), and the hashed `_astro` Target now concerns only a hook that copies by hand.
- Deployment (`framework/laravel`): the pipeline's static checks include `Guidelines check`, which needs no ADR and is not an `extraChecks` entry. Deployment (`framework/lsr`): the delivery path describes the `lsrPipeline` that exists (PHP checks `composer phpstan:ci`, `composer cs`, `composer test:parallel`, `composer audit --locked`; frontend checks `eslint`, `format:check`, `tsc`, `test`, `pnpm audit`, `build`; the guidelines check) instead of a planned one, and drops the Target and "planned" paragraphs that no longer held.
- Workflow (general and `profile/internal`): the commit standard is the developer's own; a convention recorded in `CONTEXT.md` is a recommendation, and the pull request title follows the author's standard. Adding a stage or check to the push build is not small administrative work (it needs an ADR, as the deployment rule says). Hotfix: the normal review with priority (any available reviewer; a bypass is the owner's decision only) and an incident note in the hotfix issue (what happened, impact, fix, follow-up; telling the client is the project lead's call).
- Dependencies (`general`): the Target paragraph of "Install hardening" no longer says the Laravel and LSR templates lack the `pnpm-workspace.yaml` settings and an unfiltered `pnpm audit`: they have both; what remains is the critical-only default of `laravelPipeline`, which projects replace with `nodeStaticChecks`.
- README (install and "Releases and updates"): below 1.0 every minor release needs the constraint raised, because `^0.4` stops before 0.5.0 and `composer update esoul-cz/guidelines` never leaves the range. The command is `composer require --dev esoul-cz/guidelines:^0.5 -W` (`composer global require esoul-cz/guidelines:^0.5` for a global install); a patch release needs only `composer update esoul-cz/guidelines` and `sync`.
- Deployment (`general`): "Environments and releases" gets a Rollback bullet: where the previous release is read (the Git tag of the previous `main` build, or the digest the registry lists before the current push; the build log does not print it), that devops or whoever has Komodo access redeploys it by setting the stack's image tag or digest, that the old Jenkins build is not rerun, and that a static site uses `rollbackStaticSite`. Workflow › Hotfix points to it.

### Added

- Domain (`general`): numbers, formulas and calculations come only from the client's materials or a recorded decision, never from memory or a guess; spreadsheet calculations are pinned with parity tests (moved from the `esoul-project-lifecycle` skill).
- Tools: a "Known pitfalls" section in `framework/laravel` (strict-mode `timestamp()` and `ONLY_FULL_GROUP_BY` on MariaDB/MySQL, PHPStan on an empty `app/`), a lock-file bullet in `framework/lsr`, a dev-toolbar bullet in the `framework/astro` Playwright section and a Wayfinder generation bullet in `package/laravel-inertia` (moved from the lifecycle skill's stack profiles; the other pitfalls were already guideline rules).
- Installer: project exceptions. `esoul-guidelines.json` takes `exceptions` (`rule`, `instead`, `adr`); a rule is `<layer>/<file>.md#<heading-anchor>` (the GitHub slug of an `##`/`###`/`####` heading of the source file). `sync` renders a "Project exception" marker with the ADR link under the overridden heading and a sentence in the index; `check` fails when the layer is not selected, the file or heading no longer exists, or the ADR file is missing, and refuses every rule of a `security-and-data.md`. `init` keeps the entries. README: a "Project exceptions" section with the principle (owner approval and an accepted ADR; migrations lenient, new projects strict), an Indcom-style example and why anchors were chosen over explicit ids. Renaming or removing a heading is now a breaking change for projects that except it, so the changelog names it.
- Documentation (`general`): a section "Project exceptions": a project deviates from a rule only with the owner's approval and an accepted ADR, lists it in `esoul-guidelines.json` (README: Project exceptions), the security and production-data rules cannot be excepted, a new project follows the rules closely and a migration is lenient.
- Workflow (`general`): a section "Hotfix": the issue is still created but MAY be written after the fix, the task MAY skip `Backlog` and the estimate, review and the green `Jenkins` check still apply (a bypass is the owner's decision), the dependency audit MAY be warn-only for the hotfix build (the pipeline key `audit: [warnOnly: true]`, set for that build only and removed afterwards; `laravelPipeline` and `lsrPipeline` have it next to `astroPipeline`), rollback redeploys the previous image, and a workaround gets a follow-up issue for the root cause.
- Workflow (`general`): a project MAY record the small-admin decision once in `CONTEXT.md` › Tasking convention (which kinds of admin work go without an issue); an agent then follows the record instead of asking each time.

## [0.4.0] - 2026-11-24

Gitmoji is a recommended commit format, not a required one, and the engineering rules of the skills' quality bar are now guideline rules.

Skills affected: `gitmoji-commit`, `esoul-workflow`, `esoul-tasking` and `esoul-freelo-task-authoring` (commit format and pull request title), `esoul-workflow` and `esoul-review` (quality-bar rules: server boundary, root cause, one source of truth, queries, schema, partial updates, comments).

### Changed

- Workflow (general and `profile/internal`): commit messages and the pull request title are required to be English and descriptive of what they do. A developer MAY use their own commit standard; an agent of a developer who has none uses the recommended Gitmoji format (`:emoji: [optional-context] Message`). The pull request title is the squash commit message; an English title of the same result for projects with Czech tracker titles stays. The `gitmoji-commit` skill writes the recommended format.

### Added

- Workflow: a section "Fixing a bug": name the root cause before changing code, a workaround only with a follow-up issue, a regression test that fails on the cause.
- Code review: a default Critical finding for a rule that only the interface enforces (every rule the interface shows is enforced and tested on the server), and a section "Default Major findings" for a visibility, scoping, eligibility or status rule that lives in more than one place.
- Architecture (`framework/laravel`): sections "Schema changes only in migrations" (no schema change in handlers, jobs or controllers) and "Queries that stay correct on real data" (unique tiebreaker and a decided `NULL` order on sorted and paginated lists, joins on mapping tables match at most one row, counts from affected rows).
- Architecture (`framework/lsr`): the section "Queries that stay correct on real data".
- Requests and validation (`framework/laravel`): a section "Partial updates: not sent or cleared".
- Frontend (`package/inertia-vue`): a composable with module-level state says so and its tests reset it.
- Documentation: a section "Code comments": a comment explains a non-obvious why briefly, a long rationale goes into the pull request or an ADR.

## [0.3.5] - 2026-11-24

Repairs for layer-dependency violations in the Laravel layer, and the classes Lunar stores by name in the Lunar layer.

Skills affected: `esoul-project-lifecycle` (Laravel and Lunar stack references: layer test and recorded exceptions).

### Added

- Architecture (`framework/laravel`): a section "Fixing a layer-dependency violation" with the three repairs for what the layer test reports: persistence or a framework call in the domain core moves into the CQRS layer as a collaborator of its handler (A), a DTO the domain logic works with moves to `App\Domain\ValueObjects\<Module>` (B), and a handler's other infrastructure is framework glue, a persistence helper in the CQRS layer or a side effect behind a contract in `App\Application\Contract` (C). An exception to the layer rule is recorded in the project part of the file and in the layer test.
- Architecture (`package/lunar`): a section "Classes Lunar stores by name": a class whose fully qualified name Lunar keeps in the database (attribute field types, morph-mapped models without an alias, anything registered by class name) is not moved or renamed without a data migration, and is a recorded exception to the layer rules where its location breaks them.

## [0.3.4] - 2026-11-23

The skills `final-pr-review` and `pre-pr-review` are one skill, `esoul-review`, and the title rule covers Czech projects.

Skills affected: `esoul-review` (new name), `esoul-workflow`, `esoul-tasking` and `esoul-freelo-task-authoring` (title language).

### Changed

- Code review and workflow (general and `profile/internal`): the skill that finishes a pull request and posts the CodeRabbit fallback review is `esoul-review`; `final-pr-review` and `pre-pr-review` are merged into it.
- Workflow (general and `profile/internal`): the shared title of an issue, its tracker task and its pull request is English in a new project; a project with Czech tracker titles keeps them, and its pull request title is an English commit-style title of the same result that links the issue.

## [0.3.3] - 2026-11-23

Issue-first with a named exception, a local folder for non-anonymised client material, a decision register under another name and a release checklist.

Skills affected: `esoul-workflow` and `esoul-tasking` (issue-first), `esoul-project-lifecycle` and `esoul-project-data` (`_reference/`, decision register of light projects).

### Changed

- Workflow: the exception to "every change starts from an issue or task" is small administrative work (dependency updates, a `Jenkinsfile` change and similar), only when the user decides so; an agent never assumes it and asks unless an issue or task exists. `profile/internal` keeps its relaxed tasking rule.
- Security and data: a gitignored local folder (by convention `_reference/`) MAY hold non-anonymised client material for the work on the project, never committed and never shared; the sentence about anonymised dumps kept in gitignored folders is worded so that both rules agree.
- Domain and documentation: a project MAY name another file (for example `docs/rozhodnuti.md`) as its decision register in `CONTEXT.md`, sources of truth, and it ranks like the plan's register.
- README: the release checklist (changelog entry with a `Skills affected:` line, annotated tag, GitHub release with the PHAR and its checksum).

## [0.3.2] - 2026-11-23

Rules the owner settled while reviewing the skills: anonymised samples of faulty data, exports of public data, what an agent may write by environment, the legacy severity names and the removal of committed PDFs.

### Changed

- Security and data: an anonymised sample of faulty records MAY go into an issue or task when needed to describe a problem, the original data never; the public data exception also covers exports of that public data, which need no anonymisation.
- Security and data: an agent MAY write to data in the local environment with the owner's approval (for example an approved data fix), MUST NOT write to production data and SHOULD NOT write to staging data. The two lines that said "MUST NOT write to real data" and named the "approved writes" are now one rule; the approved writes to production are the reviewed migrations and commands, deployed by Jenkins.
- Code review: projects and templates that still use the old severity names map Blocker to Critical, High to Major, Medium to Minor and Low to Nitpick.
- Documentation: a generated PDF that is already committed is removed from Git with `git rm --cached`; workflow.md links to the rule.
- README: the Docker way of installing the command in a repository without PHP names the `esoul-guidelines` image that eSoul-cz/ci-images#12 publishes.

## [0.3.1] - 2026-11-23

The installer for repositories without PHP: a PHAR on every release, and the fixes a repository without Composer needed.

### Added

- PHAR build: `composer build:phar` (`scripts/build-phar`, `box.json`) writes `build/esoul-guidelines.phar` and its `.sha256` with a pinned, checksum-verified humbug/box from a clean `--no-dev` install of the committed files. The PHAR bundles the installer and the guideline layers and reports the `git describe` of the checkout as its version (the release tag), which `sync` records in the lock as `package_version`. Each release attaches `esoul-guidelines.phar` and `esoul-guidelines.phar.sha256`.
- README: the three ways to install the command in a repository without PHP (`composer global`, the PHAR from the release, Docker).

### Fixed

- The bundled layers are found inside a PHAR: the layer scan used `glob()`, which does not support the `phar://` stream wrapper.
- The `AGENTS.md` block told every project to run `vendor/bin/esoul-guidelines sync`, which does not exist in a repository without Composer. It names `esoul-guidelines sync` and keeps the vendor path for Composer projects. Run `sync` after updating: until then `check` reports the block as outdated.
- `init`, `sync`, `check` and `list` are covered by a test in a project that has only a `package.json`.

## [0.3.0] - 2026-11-23

The Lunar layer, written from the first Lunar project, and the installer's Lunar detection.

### Added

- Layer `package/lunar` (detected from `lunarphp/lunar`): `architecture.md` (the Lunar exception to the framework-free core, versions and upgrades, extension points, process-wide state, calculation cost, money, tax, the admin panel, storefront and search), `testing.md` and `integrations.md`. `integrations.md` holds the rules for a shop's integrations: ports and fakes, idempotency keys, scheduled reads and reconciliation, jobs, payment gateways and webhooks. The outbox, carrier and ERP sections apply only where the project has such a system or ADR.

### Changed

- Installer: `init` adds `package/filament` whenever it detects `lunarphp/lunar`, because Lunar's admin is a Filament panel although a Lunar project never requires Filament directly.
- Laravel architecture: a package layer MAY allow the types of the domain model its package provides in the framework-free core.
- LSR: the `boltdb` queue and network guard `Target` notes are removed, because the template ships both.
- README: `package/lunar` exists and `init` detects it.

## [0.2.1] - 2026-11-23

Follow-ups from the V2 review of the app templates.

### Changed

- Derived data: the rule that a shared base test class fakes the recomputation of read models, search indexes, projections, feeds and sitemaps applies only when the project has derived data; the Laravel template ships neither the fake nor the `WithDerivedData` trait.
- LSR testing: the network guard is a DI HTTP client bound to a fake in tests plus a PHPUnit extension that fails http(s) stream requests to anything but localhost; raw sockets are covered by review.
- LSR: the `boltdb` jobs queue file lives in `/app/temp/queue/` (replacing the open path), and cache cleaning (`cache:clean`, the generated-cache cleanup of `/prepare-runtime`) leaves it alone.
- Astro: `B-admin` has the `astro-ssr` template (an ADR is still required; `D-static-api` has no template yet). The three templates are named (`laravel-cms`, `astro-cms-frontend` and the root `astro-cms`), a no-CMS site that needs forms starts its backend from `laravel-cms`, and the subdomain is the default of the CMS backend with a path as the documented exception.
- README: `package/lunar` will be written from the first Lunar project.

## [0.2.0] - 2026-11-22

The V1 review of every guideline layer: new layers and guidelines, tightened and corrected rules, and the installer decisions.

### Added

- Layer `package/laravel-inertia` (detected from `laravel/framework` and `inertiajs/inertia-laravel`): the Inertia, Wayfinder, design-system and tooling rules that used to sit in `framework/laravel`.
- Layer `package/filament` (detected from `filament/filament`): admin panels and theming.
- Layer `profile/internal` for internal repositories (guidelines, templates, libraries, infrastructure); `init` asks whether a repository is a client or an internal one and never detects it.
- `general/deployment.md`: the deployment scheme shared by every stack (how an application reaches production, stacks and manifests, environments and the branch lifecycle, local development, shared database, cache, search and object storage, reverse proxy, health endpoint, configuration and secrets, observability, backups, networks).
- `general/public-website.md`: content in the HTML, crawling and AI-readiness, the preview gate, redirects from an old site.
- `general/dependencies.md`: pnpm install hardening (release age, `allowBuilds`, audit with dated ignores) and how dependencies are added.
- `framework/astro/testing.md`, and Vitest in the Astro checks.

### Changed

- Finding severities are `Critical`, `Major`, `Minor` and `Nitpick`; findings close as `Resolved`, `Accepted` or `Deferred`, and an invalid finding closes as `Resolved` with its evidence.
- Tests may assert the text of internal (developer-facing) error messages; user-facing wording is still not asserted.
- Testing guidelines say how much to test: risk tiers with a per-surface floor, a unit-first decision order (a feature test only for what the real framework or database shows, folded into the surface's existing test), a matrix of variants as one test, derived data switched off in tests, concurrency covered by design instead of a test per locked handler, and a default test-stage budget of 10 minutes (a pull request adding more than 30 s of summed test time explains why).
- Mutation testing is a diagnostic, not a gate: no accepted-mutant bookkeeping in pull requests or reviews. A new push-build stage or check needs an accepted ADR and human approval, and production-engine checks run as `db-engine` tests in the scheduled job.
- The branch lifecycle: a project starts with `main` only; an optional `staging` branch follows the release when the client wants it. Only `main` pushes `latest`.
- Versions are named only in each stack's `tools.md`; script names are aligned across stacks (`eslint`, `eslint:fix`, `format:check`, `format`, `tsc`, `test`).
- PHPStan level 10 for LSR applications; LSR jobs are durable by default (`boltdb`), with the template change tracked as a target.
- Astro: one static delivery model (a `FROM scratch` image through Komodo, or `deployStaticSite`), a Laravel/Filament CMS by default, Vue islands, a monorepo with `frontend` and `backend` submodules, and a reCAPTCHA form that needs JavaScript and rejects a submission without a token.
- `package/mutation-testing` and `package/test-evidence` have a stack-neutral core with marked Laravel sections.
- Hard-wrapped lines are unwrapped in the general, framework and package guidelines; private standards-site pointers are removed.
- Installer README: sync after `composer update` through a consumer `post-update-cmd`, global install options (Composer now, Docker image and PHAR planned), SemVer releases through a changelog-only pull request, and the open questions answered.

## [0.1.1] - 2026-11-22

### Changed

- pnpm is described without corepack, and the `.npmrc` settings that pnpm 12 still reads are named.

## [0.1.0] - 2026-11-22

### Added

- First release: the layered guidelines for general, Laravel, LSR and Astro projects with the `esoul-guidelines` installer (`init`, `sync`, `check`) and drift detection.

[Unreleased]: https://github.com/eSoul-cz/guidelines/compare/v0.5.4...HEAD
[0.5.4]: https://github.com/eSoul-cz/guidelines/compare/v0.5.3...v0.5.4
[0.5.3]: https://github.com/eSoul-cz/guidelines/compare/v0.5.2...v0.5.3
[0.5.2]: https://github.com/eSoul-cz/guidelines/compare/v0.5.1...v0.5.2
[0.5.1]: https://github.com/eSoul-cz/guidelines/compare/v0.5.0...v0.5.1
[0.5.0]: https://github.com/eSoul-cz/guidelines/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/eSoul-cz/guidelines/compare/v0.3.5...v0.4.0
[0.3.5]: https://github.com/eSoul-cz/guidelines/compare/v0.3.4...v0.3.5
[0.3.4]: https://github.com/eSoul-cz/guidelines/compare/v0.3.3...v0.3.4
[0.3.3]: https://github.com/eSoul-cz/guidelines/compare/v0.3.2...v0.3.3
[0.3.2]: https://github.com/eSoul-cz/guidelines/compare/v0.3.1...v0.3.2
[0.3.1]: https://github.com/eSoul-cz/guidelines/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/eSoul-cz/guidelines/compare/v0.2.1...v0.3.0
[0.2.1]: https://github.com/eSoul-cz/guidelines/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/eSoul-cz/guidelines/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/eSoul-cz/guidelines/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/eSoul-cz/guidelines/releases/tag/v0.1.0
