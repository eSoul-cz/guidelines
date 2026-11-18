# esoul/guidelines

Layered, mergeable engineering guidelines for eSoul projects (Laravel, LSR, Astro), installed as plain Markdown into the project and protected against silent overwrites.

A standalone Symfony Console app (`bin/esoul-guidelines`). It has **no Laravel/Artisan dependency** and is **not a Composer plugin**, so it works in Laravel and LSR repositories and, through `composer global` or a PHAR later, in Astro repositories.

What it does:

- merges guideline files from the selected **layers** (`general`, `framework/*`, `package/*`) into `docs/project_guidelines/<name>.md`;
- generates `docs/project_guidelines/index.md` and a managed block in `AGENTS.md` that points agents to the index and lists the rules every task must follow;
- records what it wrote in `esoul-guidelines.lock` so local edits inside generated blocks are detected (drift) and a CI `check` can fail on drift or outdated content.

## Install

```bash
composer require --dev esoul/guidelines     # from the eSoul Satis repository (packages.esoul.cz)
vendor/bin/esoul-guidelines init            # detects the stack, writes esoul-guidelines.json
vendor/bin/esoul-guidelines sync            # writes docs/project_guidelines/*, AGENTS.md block, lock
```

Commit `esoul-guidelines.json`, `esoul-guidelines.lock`, `docs/project_guidelines/` and `AGENTS.md`. Update later with `composer update esoul/guidelines && vendor/bin/esoul-guidelines sync`.

The Satis repository must be configured in the consumer's `composer.json` (`"repositories": [{"type": "composer", "url": "https://packages.esoul.cz"}]`) - see the esoul-packages docs.

## Commands

| Command | Purpose |
|---|---|
| `init [--no-interaction]` | Detects the stack from `composer.json` / `package.json` (`laravel/framework`, `lsr/core`, `lsr/cqrs`, `astro`, `filament/filament`, `lunarphp/lunar`, `@inertiajs/vue3`, `infection/infection`, `esoul-cz/phpunit-test-evidence`), asks you to confirm the layers (multiselect) and writes `esoul-guidelines.json`. `--no-interaction` accepts the detection (or an existing config). Detected stacks without a layer yet are reported and skipped. |
| `sync [--dry-run] [--force]` | Renders and writes. Refuses to overwrite a managed block whose current hash differs from the lock (a local edit), listing the files, unless `--force`. `--dry-run` prints a summary (`-v` also lists unchanged files, `-vv` prints the changed lines) and writes nothing. |
| `check` | For CI. Exit 1 on **drift** (a managed block differs from the lock, or is missing) or **outdated** content (the lock differs from a fresh render, or the config/layers changed). Exit 0 otherwise. |
| `list` | Lists the available layers and their files (`*` = selected in the project). |

All commands accept `-d|--working-dir=<project root>` (default: current directory).

### Config: `esoul-guidelines.json`

```json
{
    "layers": ["general", "framework/laravel", "package/inertia-vue", "package/mutation-testing"],
    "exclude": ["cqrs"]
}
```

- `layers` is ordered: earlier layers come first when files are merged. `general` is always first (added when missing).
- `exclude` lists output file names (with or without `.md`) the project does not want. Removing a layer from the config removes its contribution on the next `sync`.

## Layer authoring rules

Sources live in `resources/guidelines/<layer>/<name>.md`; a layer id is `general` or `<group>/<name>` (`framework/laravel`, `framework/lsr`, `framework/astro`, `package/inertia-vue`, `package/lsr-cqrs`, `package/mutation-testing`, `package/test-evidence`, ...). Files are flat inside a layer; `index.md` is reserved for the generated index.

Optional YAML frontmatter:

```yaml
---
title: Testing          # short title for the index (default: H1 without "Project guidelines - ")
order: 20               # index ordering, lower first (default 1000); the first layer defining the file wins
summary: One-line scope # shown in the index and in the AGENTS.md block
agents: true            # also listed in the AGENTS.md block as a rule for every task (true in any layer wins)
---
```

Writing rules (from the esoul project-lifecycle guidelines): H1 `# Project guidelines - <Topic>`, English, RFC 2119 keywords, checkable rules, no project or client names, no secrets. Use one stable H2 text per topic: H2 text is the merge key. When a file name exists in several layers, reuse the exact H2 text to contribute to a section and never repeat what an earlier layer states.

## Merge model

Files with the same relative name across the selected layers become one output file. Merging is by **H2 section**:

- Sections are keyed by normalised H2 text (case, spacing, backticks, trailing colon ignored). `##` inside fenced code blocks is not a heading.
- The first layer that defines the file decides the H1 and the section order.
- A later layer's content for an existing key is appended inside that section, preceded by `<!-- from: <layer> -->`. New keys are appended at the end (also attributed).
- Content before the first H2 (intro) is concatenated the same way.
- A later-layer section (or intro) whose first line is `<!-- replace -->` replaces the earlier content instead of appending. Use sparingly.

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
composer install
vendor/bin/phpunit
bin/esoul-guidelines list
```

Tests cover the risky logic only: H2 merge, managed block replacement, drift refusal and `--force`, `check` (outdated vs drift), layer removal.

## Draft assumptions

- Layer content is first-draft quality, seeded from the esoul-project-lifecycle template folder and the reference projects' guideline files with project names removed. Expect a review pass per layer.
- `package/filament` and `package/lunar` are **not written** (no source guide exists yet). `init` detects `filament/filament` and `lunarphp/lunar`, reports them and skips them until the layers exist.
- Frontmatter has one key beyond the brief: `summary` (one line for the index and the AGENTS.md block). `title` falls back to the H1.
- The AGENTS.md block lists files flagged `agents: true` (currently `testing`, `security-and-data`, `workflow`) as links with their summary, not the rule text itself.
- Layers are flat directories of `.md` files; there are no sub-folders inside a layer. Output file names equal source names.
- Layer order is the config order with `general` forced first; `init` writes frameworks before packages.
- `package/mutation-testing` (local, optional mutation testing) and `package/test-evidence` (acceptance tests reported with `esoul-cz/phpunit-test-evidence`; its evidence tests are excluded from the default run by an `evidence` group) contribute to `testing.md` and are detected from `infection/infection` and `esoul-cz/phpunit-test-evidence`. The Laravel testing rules assume the Laravel template: SQLite `:memory:`, `LazilyRefreshDatabase` in the shared feature base class and `php artisan test --parallel`.
- Detection is limited to the packages listed under Commands; monorepos (backend in a sub-folder) are not scanned.
- Optional LSR modules get their own `package/lsr-<module>` layer, detected from the module's Composer package (`lsr/cqrs` now; `lsr/auth`, `lsr/otel` and others later). The `framework/lsr` layer never describes an optional module.
- `list` replaces Symfony's built-in `list` (command overview); use `--help` for commands.
- The package version in the lock is the one reported by Composer for `esoul/guidelines`; a development checkout reports `dev`.
- The note "local additions go below" lives inside the managed block (visible, regenerated), not outside it.
- `Bez testu: <reason>` (Czech) is kept in `general/testing.md` as the company's PR-description convention from the seed templates.
- The Jenkins delivery wording in `framework/lsr/deployment.md` follows the template `Jenkinsfile`, which calls the shared `lsrPipeline`; that pipeline does not exist yet in the shared libraries (`laravelPipeline` only) and `astroCmsPipeline` is likewise planned.
- Baseline stack statements in the layers (PHP 8.5, Laravel 13, Inertia 3 + Vue 3, pnpm 10.20.0, Node 24, Vite 8, Pint, Larastan level 10, PHPUnit 13, sqlite test DB) are the proposed defaults and may change.
- No PHAR build and no Composer plugin or `post-update-cmd` hook is provided.

## Open questions

- Should `sync` run automatically on `composer update` (a consumer-side `post-update-cmd` script) or stay explicit?
- Should `esoul-guidelines.lock` pin the guideline versions per layer (today only the package version and source hashes)?
- Where do Astro-only repositories get PHP from: a PHAR, `composer global`, or a Node port?
- Should the blocks of `AGENTS.md` and the index also support a Czech variant for Czech-language projects?
- Who owns each layer (CODEOWNERS) and how are guideline changes released (tag per change, or a rolling `main`)?
- Is `Bez testu:` (Czech) acceptable in English guideline text?
