# esoul-cz/guidelines

Layered, mergeable engineering guidelines for eSoul projects (Laravel, LSR, Astro), installed as plain Markdown into the project and protected against silent overwrites.

A standalone Symfony Console app (`bin/esoul-guidelines`). It has **no Laravel/Artisan dependency** and is **not a Composer plugin**, so it works in Laravel and LSR repositories and, through `composer global` or a PHAR later, in Astro repositories.

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

Commit `esoul-guidelines.json`, `esoul-guidelines.lock`, `docs/project_guidelines/` and `AGENTS.md`. Update later with `composer update esoul-cz/guidelines && vendor/bin/esoul-guidelines sync`.

The package is published on [Packagist](https://packagist.org/packages/esoul-cz/guidelines); the eSoul Satis repository (`https://packages.esoul.cz`) mirrors it as a fallback.

## Commands

| Command | Purpose |
|---|---|
| `init [--no-interaction]` | Asks whether the repository is a **client/application** repository (default) or an **internal** repository (guidelines, templates, libraries, packages, infrastructure); internal adds the `profile/internal` layer. The kind is never detected from the repository. `--no-interaction` keeps client, unless the existing config already has `profile/internal`. Then detects the stack from `composer.json` / `package.json` (`laravel/framework`, `lsr/core`, `lsr/cqrs`, `astro`, `filament/filament`, `lunarphp/lunar`, `@inertiajs/vue3`, `infection/infection`, `esoul-cz/phpunit-test-evidence`), asks you to confirm the layers (multiselect; entry `none` selects no stack layer, for example in an internal documentation repository) and writes `esoul-guidelines.json`. `--no-interaction` accepts the detection (or an existing config). Detected stacks without a layer yet are reported and skipped. |
| `sync [--dry-run] [--force]` | Renders and writes. Refuses to overwrite a managed block whose current hash differs from the lock (a local edit), listing the files, unless `--force`. `--dry-run` prints a summary (`-v` also lists unchanged files, `-vv` prints the changed lines) and writes nothing. |
| `check` | For CI. Exit 1 on **drift** (a managed block differs from the lock, or is missing) or **outdated** content (the lock differs from a fresh render, or the config/layers changed). Exit 0 otherwise. |
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

## Layer authoring rules

Sources live in `resources/guidelines/<layer>/<name>.md`; a layer id is `general` or `<group>/<name>` (`profile/internal`, `framework/laravel`, `framework/lsr`, `framework/astro`, `package/inertia-vue`, `package/lsr-cqrs`, `package/mutation-testing`, `package/test-evidence`, ...). Files are flat inside a layer; `index.md` is reserved for the generated index.

Optional YAML frontmatter:

```yaml
---
title: Testing          # short title for the index (default: H1 without "Project guidelines - ")
order: 20               # index ordering, lower first (default 1000); the first layer defining the file wins
summary: One-line scope # shown in the index and in the AGENTS.md block
agents: true            # also listed in the AGENTS.md block as a rule for every task (true in any layer wins)
---
```

Writing rules (from the esoul project-lifecycle guidelines): H1 `# Project guidelines - <Topic>`, English, RFC 2119 keywords, checkable rules, no project or client names, no secrets. Use one stable H2 text per topic: H2 text is the merge key. When a file name exists in several layers, reuse the exact H2 text to contribute to a section and never repeat what an earlier layer states. Markdown is not hard-wrapped to a fixed width.

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
- `esoul-guidelines.lock` (JSON, committed) stores `schema_version`, the package version (Composer installed metadata, fallback `dev`), the selected layers and exclusions and, per managed block, the sha256 of the block content and the source files with their hashes. Line endings and blank lines around the block are ignored by the hash.
- **Drift** = current block hash differs from the lock (someone edited inside the block, or the lock has no entry for it). `sync` refuses and lists the files; move the change below the block or use `--force` to discard it.
- **Outdated** = the lock matches the block but a fresh render differs (guidelines package updated, layers or exclusions changed). `sync` updates it.
- A file that is no longer produced (layer or file removed) loses its managed block on `sync`; local content around it is kept, and the file is deleted only when nothing else is left (never `AGENTS.md`). A removed block with local edits is also refused without `--force`.

## CI usage

```bash
vendor/bin/esoul-guidelines check
```

Run it next to the other read-only checks (it does not write). Exit code 1 means drift or outdated guidelines; the messages name the file and the fix (`sync` or `sync --force`).

## Laravel Boost interop

Boost writes its own `<laravel-boost-guidelines>` block into `AGENTS.md` and this package writes a separate `<!-- esoul-guidelines:begin agents -->` block. The two never edit each other's content, and no Boost guidelines are shipped here. Where generated Boost text conflicts with the project guidelines (for example its Laravel Cloud advice), the project guidelines apply; the Laravel layer states this in `deployment.md` and `tools.md`.

## Development

```bash
composer update          # libraries do not commit composer.lock
composer test            # PHPUnit
composer phpstan         # PHPStan, level max with the phpunit, strict and deprecation rules
composer cs              # PHP-CS-Fixer dry run with esoul-cz/coding-standard; `composer cs:fix` applies it
bin/esoul-guidelines list
```

Tests cover the risky logic only: H2 merge (including a replaced section), managed block replacement, drift refusal and `--force`, `check` (outdated vs drift), layer removal, the `init` repository-kind choice.

CI is the `Jenkinsfile`: one call to `phpPackagePipeline` from the eSoul Jenkins libraries. It runs composer validate, PHPStan, the code style
check and composer audit, then PHPUnit on PHP 8.5, and PHPUnit on 8.4 and 8.6 after Composer re-resolved the dependencies on that version. A
build of `main` triggers the Satis mirror. The package supports PHP 8.4 and every later 8.x release (`^8.4`).

## Draft assumptions

- Layer content is first-draft quality, seeded from the esoul-project-lifecycle template folder and the reference projects' guideline files with project names removed. Expect a review pass per layer.
- `package/filament` and `package/lunar` are **not written** (no source guide exists yet). `init` detects `filament/filament` and `lunarphp/lunar`, reports them and skips them until the layers exist.
- Frontmatter has one key beyond the brief: `summary` (one line for the index and the AGENTS.md block). `title` falls back to the H1.
- The AGENTS.md block lists files flagged `agents: true` (currently `testing`, `security-and-data`, `workflow`) as links with their summary, not the rule text itself.
- Layers are flat directories of `.md` files; there are no sub-folders inside a layer. Output file names equal source names.
- Layer order is the config order with `general` forced first; `init` writes `profile/internal` (when chosen), then frameworks, then packages.
- `package/mutation-testing` (local, optional mutation testing) and `package/test-evidence` (acceptance tests reported with `esoul-cz/phpunit-test-evidence`; its evidence tests are excluded from the default run by an `evidence` group) contribute to `testing.md` and are detected from `infection/infection` and `esoul-cz/phpunit-test-evidence`. The Laravel testing rules assume the Laravel template: SQLite `:memory:`, `LazilyRefreshDatabase` in the shared feature base class and `php artisan test --parallel`.
- Detection is limited to the packages listed under Commands; monorepos (backend in a sub-folder) are not scanned.
- Optional LSR modules get their own `package/lsr-<module>` layer, detected from the module's Composer package (`lsr/cqrs` now; `lsr/auth`, `lsr/otel` and others later). The `framework/lsr` layer never describes an optional module.
- `list` replaces Symfony's built-in `list` (command overview); use `--help` for commands.
- The package version in the lock is the one reported by Composer for `esoul-cz/guidelines`; a development checkout reports `dev`.
- The note "local additions go below" lives inside the managed block (visible, regenerated), not outside it.
- `No test: <reason>` is the PR-description line for a behaviour change without a test (`general/testing.md`, `general/workflow.md`); it is English like the rest of the guidelines.
- The Jenkins delivery wording in `framework/lsr/deployment.md` follows the template `Jenkinsfile`, which calls the shared `lsrPipeline`; that pipeline does not exist yet in the shared libraries (`laravelPipeline` only) and `astroCmsPipeline` is likewise planned.
- Baseline stack statements in the layers (PHP 8.5, Laravel 13, Inertia 3 + Vue 3, pnpm 12, Node 26, Vite 8, Pint, Larastan level 10, PHPUnit 13, sqlite test DB) are the proposed defaults and may change.
- No PHAR build and no Composer plugin or `post-update-cmd` hook is provided.

## Open questions

- Should `sync` run automatically on `composer update` (a consumer-side `post-update-cmd` script) or stay explicit?
- Should `esoul-guidelines.lock` pin the guideline versions per layer (today only the package version and source hashes)?
- Where do Astro-only repositories get PHP from: a PHAR, `composer global`, or a Node port?
- Should the blocks of `AGENTS.md` and the index also support a Czech variant for Czech-language projects?
- Who owns each layer (CODEOWNERS) and how are guideline changes released (tag per change, or a rolling `main`)?
