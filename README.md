# esoul-cz/guidelines

Layered, mergeable engineering guidelines for eSoul projects (Laravel, LSR, Astro), installed as plain Markdown into the project and protected against silent overwrites.

A standalone Symfony Console app (`bin/esoul-guidelines`). It has **no Laravel/Artisan dependency** and is **not a Composer plugin**, so it works in Laravel and LSR repositories and, through `composer global`, a PHAR or a Docker image, in Astro and other repositories without PHP ([Repositories without PHP](#repositories-without-php)).

What it does:

- merges guideline files from the selected **layers** (`general`, `profile/*`, `framework/*`, `package/*`) into `docs/project_guidelines/<name>.md`;
- generates `docs/project_guidelines/index.md` and a managed block in `AGENTS.md` that points agents to the index and lists the rules every task must follow;
- records what it wrote in `esoul-guidelines.lock` so local edits inside generated blocks are detected (drift) and a CI `check` can fail on drift or outdated content.

## Install

```bash
composer require --dev esoul-cz/guidelines  # from Packagist
vendor/bin/esoul-guidelines init            # asks the repository kind, detects the stack, writes esoul-guidelines.json
vendor/bin/esoul-guidelines sync            # writes docs/project_guidelines/*, AGENTS.md block, lock
```

Commit `esoul-guidelines.json`, `esoul-guidelines.lock`, `docs/project_guidelines/` and `AGENTS.md`. A PHP project runs `sync` automatically after `composer update` ([below](#sync-on-composer-update)); how projects pick up new guideline versions is in [Releases and updates](#releases-and-updates). While the package is below 1.0, Composer's `^0.4` stops before 0.5.0: every new minor needs the constraint raised (same section).

The package is published on [Packagist](https://packagist.org/packages/esoul-cz/guidelines); the eSoul Satis repository (`https://packages.esoul.cz`) mirrors it as a fallback.

### Sync on `composer update`

A project that requires the package syncs on every `composer update`, so the generated files never lag behind the installed guidelines. The project's own `composer.json` carries the hook (the package ships none, and is not a plugin):

```json
{
    "scripts": {
        "post-update-cmd": [
            "esoul-guidelines sync"
        ]
    }
}
```

- Composer puts `vendor/bin` on the `PATH` of its scripts, so the command needs no prefix.
- When Laravel Boost is installed, its `boost:update` is already a `post-update-cmd` entry. Put `esoul-guidelines sync` **after** it, so the project guidelines are written last ([Laravel Boost interop](#laravel-boost-interop)):

  ```json
  "post-update-cmd": [
      "@php artisan boost:update --ansi",
      "esoul-guidelines sync"
  ]
  ```

- `sync` refuses to overwrite a managed block that was edited locally, and a non-zero exit stops `composer update`: move the edit below the block or use `sync --force`, as in [Output, managed blocks, lock and drift](#output-managed-blocks-lock-and-drift).
- CI still runs `esoul-guidelines check` ([CI usage](#ci-usage)); it never writes.

### Repositories without PHP

A repository without Composer (a fully static Astro site, for example) needs no monorepo and no PHP project around it, and `init`, `sync`, `check` and `list` never read or require a `composer.json` or `vendor/` (only `package.json` and `composer.json` are looked at to detect the stack, and either may be missing). Pick one of three ways to get the `esoul-guidelines` command; they run the same code and write the same files.

**1. Composer global** (a machine or CI image that has PHP 8.4+ and Composer):

```bash
composer global require esoul-cz/guidelines   # installs the esoul-guidelines binary
export PATH="$(composer global config bin-dir --absolute):$PATH"   # once, e.g. in ~/.zshrc or ~/.bashrc
esoul-guidelines init                         # in the repository root, as above
esoul-guidelines sync
```

- Update to a patch release with `composer global update esoul-cz/guidelines`, to a new minor with `composer global require esoul-cz/guidelines:^0.5` (a constraint such as `^0.4` never reaches 0.5.0, see [Releases and updates](#releases-and-updates)), then `sync` in each repository. CI runs `esoul-guidelines check` the same way.

**2. PHAR** (a machine that has PHP 8.4+ but no Composer): one self-contained file, attached to every [GitHub release](https://github.com/eSoul-cz/guidelines/releases) together with its checksum:

```bash
VERSION=0.3.1   # the release without the leading "v"
BASE="https://github.com/eSoul-cz/guidelines/releases/download/v${VERSION}"
curl -fsSL -O "$BASE/esoul-guidelines.phar" -O "$BASE/esoul-guidelines.phar.sha256"
sha256sum -c esoul-guidelines.phar.sha256     # macOS: shasum -a 256 -c esoul-guidelines.phar.sha256
chmod +x esoul-guidelines.phar
sudo mv esoul-guidelines.phar /usr/local/bin/esoul-guidelines   # or any directory on the PATH
esoul-guidelines init                         # in the repository root
esoul-guidelines sync
```

- Without installing it: `php esoul-guidelines.phar sync`.
- The guideline layers are bundled in the PHAR, and the lock records the release as `package_version` (`v0.3.1`), the same as the Composer install.
- Update by downloading the newer release, then `sync` in each repository.
- Build it yourself with `composer build:phar` from a checkout (needs PHP 8.4+, Composer, `git` and `curl`): it downloads a pinned, checksum-verified [Box](https://github.com/box-project/box), installs the committed files with `--no-dev` in a temporary directory and writes `build/esoul-guidelines.phar` and `build/esoul-guidelines.phar.sha256`. A checkout at a release tag reports that tag as the version, any other commit `git describe`.

**3. Docker** (a machine or pipeline with Docker and no PHP): an image of the same release, built by [eSoul-cz/ci-images](https://github.com/eSoul-cz/ci-images) from that release's PHAR (checked against its `.sha256`). **After [eSoul-cz/ci-images#12](https://github.com/eSoul-cz/ci-images/pull/12) merges** and the next nightly build ran, it is published as `rg.fr-par.scw.cloud/testing-images/esoul-guidelines` (log in with `docker login rg.fr-par.scw.cloud`) with the tags `0.3.1` (the release), `0.3` and `latest`; until then use the PHAR or Composer.

```bash
# in the repository root; arguments are those of esoul-guidelines (init, sync, check, list)
docker run --rm -v "$PWD:/app" -w /app rg.fr-par.scw.cloud/testing-images/esoul-guidelines:0.3.1 init
docker run --rm -v "$PWD:/app" -w /app rg.fr-par.scw.cloud/testing-images/esoul-guidelines:0.3.1 sync
```

- `init` asks questions, so add `-it` to the first command (or use `init --no-interaction`).
- The image runs as uid 1000. Add `--user "$(id -u):$(id -g)"` so the written files belong to you (Linux hosts whose uid is not 1000, CI agents).
- Pin the tag: an image carries exactly one release, so a newer tag is the update (then `sync`).

### Releases and updates

- The package is released with **SemVer tags**. A release is a pull request that changes only the changelog, merged and then tagged; there is no tag per guideline change and no rolling `main` for projects.
- **Release checklist.** (1) The changelog entry names the layers it changes and carries a `Skills affected:` line: `none`, or the skills of [eSoul-cz/skills](https://github.com/eSoul-cz/skills) whose text restates or points at a changed rule. (2) The tag is annotated (`v<version>`). (3) The GitHub release attaches the PHAR from `composer build:phar` and its `.sha256`. (4) A listed skill is reviewed in the skills repository after the release.
- **Below 1.0 every minor release needs the constraint raised.** Composer reads `^0.4` as `>=0.4.0 <0.5.0`, so `composer update esoul-cz/guidelines` installs 0.4 patch releases only and silently stays on 0.4.x when 0.5.0 is out (a project on `^0.3` was left on 0.3.5 this way). To update to the new minor, raise the constraint and update in one command: `composer require --dev esoul-cz/guidelines:^0.5 -W` (`-W` also updates the dependencies the new release needs). Composer then runs the project's `post-update-cmd`, so `sync` runs too; review the diff, `vendor/bin/esoul-guidelines check`, and commit `composer.json`, `composer.lock` and the synced files. Which version a project is on is `package_version` in `esoul-guidelines.lock`; what changed is in this changelog from that version on.
- A patch release (`0.4.0` to `0.4.1`) needs no constraint change: `composer update esoul-cz/guidelines` and `sync` (the `post-update-cmd` above runs it), then commit the result.
- The repository has no `CODEOWNERS`; review follows [workflow.md](resources/guidelines/general/workflow.md) like any other change.

## Commands

| Command | Purpose |
|---|---|
| `init [--no-interaction]` | Asks whether the repository is a **client/application** repository (default) or an **internal** repository (guidelines, templates, libraries, packages, infrastructure); internal adds the `profile/internal` layer. The kind is never detected from the repository. `--no-interaction` keeps client, unless the existing config already has `profile/internal`. Then detects the stack from `composer.json` / `package.json` (`laravel/framework`, `laravel/framework` together with `inertiajs/inertia-laravel`, `lsr/core`, `lsr/cqrs`, `astro`, `filament/filament`, `lunarphp/lunar` (which also adds `package/filament`), `@inertiajs/vue3`, `@inertiajs/react` together with the `package/laravel-inertia` detection (adds `package/laravel-inertia-react`), `infection/infection`, `esoul-cz/phpunit-test-evidence`), asks you to confirm the layers (multiselect; entry `none` selects no stack layer, for example in an internal documentation repository) and writes `esoul-guidelines.json`. `--no-interaction` accepts the detection (or an existing config). Detected stacks without a layer yet are reported and skipped. |
| `sync [--dry-run] [--force]` | Renders and writes. Refuses to overwrite a managed block whose current hash differs from the lock (a local edit), listing the files, unless `--force`. `--dry-run` prints a summary (`-v` also lists unchanged files, `-vv` prints the changed lines) and writes nothing. |
| `check` | For CI. Exit 1 on **drift** (a managed block differs from the lock, or is missing), **outdated** content (the lock differs from a fresh render, or the config/layers changed) or an invalid **project exception** (its rule, layer or ADR file is missing, or it is refused). Exit 0 otherwise. |
| `list` | Lists the available layers and their files (`*` = selected in the project). |

All commands accept `-d|--working-dir=<project root>` (default: current directory).

### Config: `esoul-guidelines.json`

```json
{
    "layers": ["general", "profile/internal", "framework/laravel", "package/inertia-vue", "package/mutation-testing"],
    "exclude": ["cqrs"]
}
```

- `layers` is ordered: earlier layers come first when files are merged. `general` is always first (added when missing). Keep the order `general`, `profile/*`, `framework/*`, `package/*` (what `init` writes): a profile replaces sections of `general` before a framework or package layer appends to them. `profile/internal` is optional and only for internal repositories; client and application repositories omit it.
- `exclude` lists output file names (with or without `.md`) the project does not want. Removing a layer from the config removes its contribution on the next `sync`.
- `exceptions` records the rules the project deliberately does not follow, each with what applies instead and the accepted ADR; `sync` marks them in the generated files and `check` validates them ([Project exceptions](#project-exceptions)). `init` keeps them.

## Layer authoring rules

Sources live in `resources/guidelines/<layer>/<name>.md`; a layer id is `general` or `<group>/<name>` (`profile/internal`, `framework/laravel`, `framework/lsr`, `framework/astro`, `package/filament`, `package/inertia-vue`, `package/laravel-inertia`, `package/laravel-inertia-react`, `package/lsr-cqrs`, `package/lunar`, `package/mutation-testing`, `package/test-evidence`, ...). Files are flat inside a layer; `index.md` is reserved for the generated index.

Optional YAML frontmatter:

```yaml
---
title: Testing          # short title for the index (default: H1 without "Project guidelines - ")
order: 20               # index ordering, lower first (default 1000); the first layer defining the file wins
summary: One-line scope # shown in the index and in the AGENTS.md block
agents: true            # also listed in the AGENTS.md block as a rule for every task (true in any layer wins)
---
```

Writing rules (from the esoul project-lifecycle guidelines): H1 `# Project guidelines - <Topic>`, English, RFC 2119 keywords, checkable rules, no project or client names, no secrets. Use one stable H2 text per topic: H2 text is the merge key. When a file name exists in several layers, reuse the exact H2 text to contribute to a section and never repeat what an earlier layer states. Markdown is not hard-wrapped to a fixed width. A heading is also the anchor that a project exception references ([Project exceptions](#project-exceptions)): renaming or removing an `##`/`###` heading is a breaking change for projects that record an exception on it, so the changelog names it.

### Profile layers (`profile/*`)

A profile layer relaxes `general` for a kind of repository. It is chosen explicitly (`init` asks; it is never detected) and sits between `general` and the framework layers. Today there is one: `profile/internal`, for repositories only privileged people change (guidelines, templates, libraries, packages, infrastructure). It relaxes code review (`Who reviews`: the owner's explicit go-ahead to merge counts as the human review) and workflow (`Tasking`: no issue required; `Branches`: direct pushes to the default branch by the owner, or by an agent with the owner's explicit approval, in infrastructure and documentation repositories; `Pull requests`: a reviewer when one is available, otherwise the owner's go-ahead).

- A profile file reuses the `general` file name and the exact H2 text, starts the section with `<!-- replace -->` and carries the **full** relaxed text, because it replaces the `general` section instead of appending to it.
- A profile adds no frontmatter or H1 logic of its own: the first layer defining the file (`general`) wins for title and order.

## Merge model

Files with the same relative name across the selected layers become one output file. Merging is by **H2 section**:

- Sections are keyed by normalised H2 text (case, spacing, backticks, trailing colon ignored). `##` inside fenced code blocks is not a heading.
- The first layer that defines the file decides the H1 and the section order.
- A later layer's content for an existing key is appended inside that section, preceded by `<!-- from: <layer> -->`. New keys are appended at the end (also attributed).
- Content before the first H2 (intro) is concatenated the same way.
- A later-layer section (or intro) whose first line is `<!-- replace -->` replaces the earlier content instead of appending, and is attributed with `<!-- from: <layer> -->`. Use sparingly: it is how `profile/*` layers relax `general`. A layer after the replacing one (a framework layer) still appends to the replaced section.
- A later-layer section (or intro) whose first line is `<!-- replace: <layer> -->` (or several: `<!-- replace: framework/laravel, package/laravel-inertia -->`) replaces only the named earlier layers' content, in the place the first of them had, and keeps every other layer's content. It is how a variant layer (`package/laravel-inertia-react`) swaps the parts of the layers it adapts; when no named layer contributed to the section, the content is appended. A project exception names the layer whose text is rendered: on a section a variant layer replaces, it names the variant layer.

## Output, managed blocks, lock and drift

Each output file holds exactly one managed block:

```markdown
<!-- esoul-guidelines:begin testing -->
...generated content, ending with a note that local additions go below...
<!-- esoul-guidelines:end testing -->

Your own additions here are preserved forever.
```

- Everything outside the block is never touched. The same applies to `AGENTS.md`: the tool owns only `<!-- esoul-guidelines:begin agents -->...end`; other content (for example `<laravel-boost-guidelines>`) is left alone. A missing `AGENTS.md` is created, a missing `CLAUDE.md` is created with `@AGENTS.md`.
- A block that is missing from an existing file is re-inserted (at the top of a guideline file, at the end of `AGENTS.md`).
- `esoul-guidelines.lock` (JSON, committed) stores `schema_version`, the package version (Composer installed metadata, fallback `dev`), the selected layers and exclusions and, per managed block, the sha256 of the block content and the source files with their hashes. Line endings and blank lines around the block are ignored by the hash. It does not pin guideline versions per layer: the package version identifies the guidelines, and the hashes detect what changed.
- **Drift** = current block hash differs from the lock (someone edited inside the block, or the lock has no entry for it). `sync` refuses and lists the files; move the change below the block or use `--force` to discard it.
- **Outdated** = the lock matches the block but a fresh render differs (guidelines package updated, layers or exclusions changed). `sync` updates it.
- A file that is no longer produced (layer or file removed) loses its managed block on `sync`; local content around it is kept, and the file is deleted only when nothing else is left (never `AGENTS.md`). A removed block with local edits is also refused without `--force`.

## Project exceptions

A project MAY deviate from a guideline rule, with the owner's approval and an accepted ADR. The approval can be indirect: a developer gets it from the owner and records the decision. The rule does not change for other projects; the project lists the deviation in `esoul-guidelines.json`, and the generated guidelines say so where the rule is:

```json
{
    "layers": ["general", "framework/laravel", "package/filament", "package/lunar"],
    "exceptions": [
        {
            "rule": "package/lunar/architecture.md#money",
            "instead": "Lunar's CalculateLines (float path, truncation) stays; project code never copies the float pattern.",
            "adr": "docs/adr/0048-keep-lunar-calculate-lines.md"
        },
        {
            "rule": "general/workflow.md#pull-requests",
            "instead": "Pull request titles are Czech, the same as the tracker titles; commits stay English.",
            "adr": "docs/adr/0051-czech-pull-request-titles.md"
        },
        {
            "rule": "framework/laravel/deployment.md#pipeline",
            "instead": "No scheduled db-engine job and no MariaDB extraChecks; concurrency is proven by design and review.",
            "adr": "docs/adr/0052-drop-mariadb-checks.md"
        }
    ]
}
```

- **`rule`** is `<layer>/<file>.md#<anchor>`: the source file in the layer (the paths `list` prints) and the heading anchor in it. The anchor is the GitHub slug of an `##`, `###` or `####` heading: lower case, backticks and punctuation dropped, spaces become hyphens, a repeated heading in the file gets `-1`, `-2` (`Classes Lunar stores by name` is `#classes-lunar-stores-by-name`). The layer is part of the reference, so the same heading text in two layers is two different rules. An exception covers the whole section under the heading; `instead` says which part of it.
- **`instead`** is one short sentence: what applies in this project in place of the rule.
- **`adr`** is the path of the accepted ADR that records the decision and the owner's approval, relative to the project root.
- **Rendering.** `sync` puts one paragraph directly under the overridden heading in the managed block, `> **Project exception:** <instead> ([ADR](../../<adr>))`, and a sentence in the index that explains the marker. A reader (or an agent) sees the override where the rule is, not in a separate list.
- **Validation.** `check` exits 1 when the layer is not selected or the file is excluded, when the file or the heading no longer exists in the installed guidelines (a rename is the usual cause; the message lists the anchors of the file), when the ADR file does not exist, or when the entry is refused. `sync` prints the same problems as a warning, gives the entry no marker and still exits 0, so `composer update` never stops on them; an exception whose ADR is missing still gets its marker. A malformed entry (a missing key, a rule that does not match the pattern, an absolute or `..` ADR path, the same rule twice) stops both commands with a message.
- **Never exceptable.** The security and production-data rules stay hard: every rule in a `security-and-data.md` (`general/security-and-data.md`: secrets, personal and client data, anonymized dumps, production access, authorization, sessions and accounts) is refused by `check`, and `sync` writes no marker for it. A guideline that is wrong for every project is changed in the guidelines, in its own pull request.
- **What is not a project exception.** A project rule that only adds to a guideline goes below the managed block. A rule the project wants to drop for good or does not use (a layer, a whole file) is the config's `layers` and `exclude`. An exception is for a rule that applies to the project and that the project deliberately does not follow.

The principle (owner): rules are overridden per project, never silently. A **new project** follows the rules closely and asks before it deviates. A **migration of a running project** is lenient: the project is not rewritten to fit the templates, compromises are expected, and each kept deviation is recorded here with its ADR. The agent never decides an exception alone: it asks the person, who gets the owner's approval. The `esoul-guidelines` skill and the retrofit procedure of `esoul-project-lifecycle` describe the working steps; [documentation.md](resources/guidelines/general/documentation.md#project-exceptions) holds the rule that projects follow.

## CI usage

```bash
vendor/bin/esoul-guidelines check
```

Run it next to the other read-only checks (it does not write). Exit code 1 means drift or outdated guidelines; the messages name the file and the fix (`sync` or `sync --force`).

`check` also fails when a project exception no longer holds, see [Project exceptions](#project-exceptions).

## Laravel Boost interop

Boost writes its own `<laravel-boost-guidelines>` block into `AGENTS.md` and this package writes a separate `<!-- esoul-guidelines:begin agents -->` block. The two never edit each other's content, and no Boost guidelines are shipped here. Where generated Boost text conflicts with the project guidelines (for example its Laravel Cloud advice), the project guidelines apply; the Laravel layer states this in `tools.md`, and `general/deployment.md` lists hosted platforms under what not to introduce. When both run from `post-update-cmd`, `boost:update` runs first and `esoul-guidelines sync` last.

## Development

```bash
composer update          # libraries do not commit composer.lock
composer test            # PHPUnit
composer phpstan         # PHPStan, level max with the phpunit, strict and deprecation rules
composer cs              # PHP-CS-Fixer dry run with esoul-cz/coding-standard; `composer cs:fix` applies it
bin/esoul-guidelines list
```

Tests cover the risky logic only: H2 merge (including a replaced section), managed block replacement, drift refusal and `--force`, `check` (outdated vs drift), layer removal, the `init` repository-kind choice, and project exceptions (parsing, the anchors, the rendered marker, `check` for a missing rule, layer or ADR, the refusal of the security rules).

CI is the `Jenkinsfile`: one call to `phpPackagePipeline` from the eSoul Jenkins libraries. It runs composer validate, PHPStan, the code style
check and composer audit, then PHPUnit on PHP 8.5, and PHPUnit on 8.4 and 8.6 after Composer re-resolved the dependencies on that version. A
build of `main` triggers the Satis mirror. The package supports PHP 8.4 and every later 8.x release (`^8.4`).

## Design notes

- Project exceptions reference a rule by the heading anchor of its source layer file instead of an explicit id. Why: the H2 text is already the merge key and a stable contract of every layer (see Layer authoring rules), `###` headings are named with the same care, and an anchor needs no change to the 60-odd layer files and no second naming scheme to keep in step with the headings. A renamed or removed heading is exactly what `check` reports (the exception names a rule that no longer exists), so the fragility surfaces in CI instead of silently dropping the override. Explicit ids stay an option if a rule that needs an exception turns out to have no heading of its own; until then an exception names the section.
- Layer content was seeded from the esoul-project-lifecycle template folder and the reference projects' guideline files, with project names removed.
- `package/lunar` (detected from `lunarphp/lunar`) holds what Lunar adds to a Laravel shop: the Lunar exception to the framework-free core, versions and upgrades, extension points, money, tax and the admin panel (`architecture.md`), the Lunar testing rules (`testing.md`) and the rules for payment gateway, carrier and ERP integrations (`integrations.md`, a file of its own; the outbox, carrier and ERP sections apply only where the project has such a system). It was written from the first Lunar project and carries no project names. Lunar's admin is a Filament panel, so `init` adds `package/filament` whenever it detects `lunarphp/lunar`, although a Lunar project never requires Filament directly. `package/filament` holds what is decided so far: the admin panel rule and a theming note; it grows when a real project needs more.
- `package/laravel-inertia` (detected when Composer requires both `laravel/framework` and `inertiajs/inertia-laravel`) holds the Inertia-specific part of the Laravel frontend and tools guidelines (`resources/js` structure, Inertia server side, Wayfinder, design system, Vite chunking, the `pnpm` quality commands); `framework/laravel` keeps what holds for every Laravel user interface. Inertia and Vue rules shared with LSR stay in `package/inertia-vue`.
- `package/laravel-inertia-react` is the React variant of `package/laravel-inertia` for a Laravel + Inertia project on React (React 19, `@inertiajs/react`, shadcn/ui, Vitest with React Testing Library). `init` adds it when it detects `package/laravel-inertia` and `@inertiajs/react` in `package.json`; it follows `package/laravel-inertia` in the config. It holds the React client rules (the counterpart of `package/inertia-vue`, with the same section names so the two can be compared) and replaces, with targeted replace markers, every part of `framework/laravel` and `package/laravel-inertia` that names Vue (the architecture intro; frontend Stack, Inertia server side and Frontend commands; tools Versions). A Vue project's output does not change. `InertiaReactLayerTest` fails when a replaced section no longer carries the current `package/laravel-inertia` text with Vue named as React, or when a `framework/laravel` or `package/laravel-inertia` section starts to name Vue without a React replacement.
- Frontmatter has one key beyond the brief: `summary` (one line for the index and the AGENTS.md block). `title` falls back to the H1.
- The AGENTS.md block lists files flagged `agents: true` (currently `testing`, `security-and-data`, `workflow`) as links with their summary, not the rule text itself.
- Layers are flat directories of `.md` files; there are no sub-folders inside a layer. Output file names equal source names.
- Layer order is the config order with `general` forced first; `init` writes `profile/internal` (when chosen), then frameworks, then packages.
- `package/mutation-testing` (local, optional mutation testing) and `package/test-evidence` (acceptance tests reported with `esoul-cz/phpunit-test-evidence`; its evidence tests are excluded from the default run by an `evidence` group) contribute to `testing.md` and are detected from `infection/infection` and `esoul-cz/phpunit-test-evidence`. The Laravel testing rules assume the Laravel template: SQLite `:memory:`, `LazilyRefreshDatabase` in the shared feature base class and `php artisan test --parallel`.
- Detection is limited to the packages listed under Commands; monorepos (backend in a sub-folder) are not scanned.
- Optional LSR modules get their own `package/lsr-<module>` layer, detected from the module's Composer package (`lsr/cqrs` now; `lsr/auth`, `lsr/otel` and others later). The `framework/lsr` layer never describes an optional module.
- `list` replaces Symfony's built-in `list` (command overview); use `--help` for commands.
- The package version in the lock is the one reported by Composer for `esoul-cz/guidelines`, or the release tag a PHAR was built from (`v0.3.1`); a development checkout reports `dev`.
- The note "local additions go below" lives inside the managed block (visible, regenerated), not outside it.
- `No test: <reason>` is the PR-description line for a behaviour change without a test (`general/testing.md`, `general/workflow.md`); it is English like the rest of the guidelines.
- The Jenkins delivery wording in `framework/lsr/deployment.md` follows the template `Jenkinsfile`, which calls the shared `lsrPipeline`; `framework/astro/deployment.md` describes `astroPipeline`, `deployStaticSite` and `rollbackStaticSite` (the `pipelines` and `deploy` libraries of the shared libraries).
- `general/deployment.md` is the deployment scheme shared by every stack, written as a reference for project people and agents (only devops deploy and change stacks). The framework `deployment.md` files add runtime detail and reuse its H2 texts (`Environments and releases`, `Local development`, `Reverse proxy and TLS`, `Health endpoint`, `Configuration and secrets`) so the rendered file reads as one; they do not repeat the scheme.
- `general/public-website.md` states the crawling, sitemap, `llms.txt`, Markdown and preview-gate rules for any application with public pages; `framework/astro/seo.md` keeps only the Astro implementation and links to it. `general/dependencies.md` holds the pnpm install hardening for every repository with a `package.json`; `framework/astro/tools.md` links to it.
- Stack versions (PHP, Laravel, Inertia and Vue, Node, pnpm, Vite and the like) are stated in the `Versions` table of each framework's `tools.md`, the one place to change them.
- The package is not a Composer plugin and ships no hook of its own: a project's `post-update-cmd` calls `sync` ([Sync on `composer update`](#sync-on-composer-update)). A repository without PHP installs the command as a PHAR, a Docker image or with `composer global` ([Repositories without PHP](#repositories-without-php)).
- The guidelines and the generated files are English only; there is no Czech variant of the blocks, the index or the layers.
