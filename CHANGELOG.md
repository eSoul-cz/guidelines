# Changelog

All notable changes to `esoul-cz/guidelines` are recorded here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses [Semantic Versioning](https://semver.org/). A release is a pull request that changes only this file; the tag follows the merge.

## [Unreleased]

### Changed

- Deployment (`framework/laravel`): the pull-request test build always uses the layer cache and pulls its base images first (`testDockerBuild: [pull: false]` switches the pull off). `testDockerBuild: [noCache: true]` is no longer offered: `jenkins-libraries` deprecated the key on 2026-11-23 and ignores it.
- Workflow (general and `profile/internal`): the title shape is `Context: Result` (for example `Cart: Recalculate shipping when the address changes`), and the pull request title states the same result in English. How the title is mapped into a commit standard stays skill procedure (`esoul-task-create`, `gitmoji-commit`).
- Tools (`framework/laravel`, `framework/lsr`): `esoul-guidelines check` is listed among the read-only checks (a "Guidelines" subsection), as the stage `Guidelines check` that the shared `laravelPipeline` and `lsrPipeline` now run by default for a project with `esoul-guidelines.lock`.
- Deployment (`framework/laravel`): the pipeline's static checks include `Guidelines check`, which needs no ADR and is not an `extraChecks` entry. Deployment (`framework/lsr`): the delivery path describes the `lsrPipeline` that exists (PHP checks `composer phpstan:ci`, `composer cs`, `composer test:parallel`, `composer audit --locked`; frontend checks `eslint`, `format:check`, `tsc`, `test`, `pnpm audit`, `build`; the guidelines check) instead of a planned one, and drops the Target and "planned" paragraphs that no longer held.
- Dependencies (`general`): the Target paragraph of "Install hardening" no longer says the Laravel and LSR templates lack the `pnpm-workspace.yaml` settings and an unfiltered `pnpm audit`: they have both; what remains is the critical-only default of `laravelPipeline`, which projects replace with `nodeStaticChecks`.
- README (install and "Releases and updates"): below 1.0 every minor release needs the constraint raised, because `^0.4` stops before 0.5.0 and `composer update esoul-cz/guidelines` never leaves the range. The command is `composer require --dev esoul-cz/guidelines:^0.5 -W` (`composer global require esoul-cz/guidelines:^0.5` for a global install); a patch release needs only `composer update esoul-cz/guidelines` and `sync`.

### Added

- Domain (`general`): numbers, formulas and calculations come only from the client's materials or a recorded decision, never from memory or a guess; spreadsheet calculations are pinned with parity tests (moved from the `esoul-project-lifecycle` skill).
- Tools: a "Known pitfalls" section in `framework/laravel` (strict-mode `timestamp()` and `ONLY_FULL_GROUP_BY` on MariaDB/MySQL, PHPStan on an empty `app/`), a lock-file bullet in `framework/lsr`, a dev-toolbar bullet in the `framework/astro` Playwright section and a Wayfinder generation bullet in `package/laravel-inertia` (moved from the lifecycle skill's stack profiles; the other pitfalls were already guideline rules).

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

[Unreleased]: https://github.com/eSoul-cz/guidelines/compare/v0.3.5...HEAD
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
