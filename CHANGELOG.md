# Changelog

All notable changes to `esoul-cz/guidelines` are recorded here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses [Semantic Versioning](https://semver.org/). A release is a pull request that changes only this file; the tag follows the merge.

## [Unreleased]

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

[Unreleased]: https://github.com/eSoul-cz/guidelines/compare/v0.2.1...HEAD
[0.2.1]: https://github.com/eSoul-cz/guidelines/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/eSoul-cz/guidelines/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/eSoul-cz/guidelines/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/eSoul-cz/guidelines/releases/tag/v0.1.0
