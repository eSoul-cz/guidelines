---
title: Workflow
order: 60
agents: true
summary: Tasking basics, branch naming, commit convention, repository rules, pull request rules and the path from issue to merge.
---
# Project guidelines - Workflow

How a change travels from a prepared issue to a merged pull request. Company-wide standards are at
https://dev.docs.esoul.cz; this file specialises them for the repository and never contradicts
them. The tasking and commit conventions chosen for this project are recorded in
[CONTEXT.md](../../CONTEXT.md).

## Tasking

- Every change starts from a prepared issue (or task) with a clear outcome and **acceptance
  criteria**. Work without one is first turned into one; an agent does not start from a vague
  request.
- One issue is one independently deliverable result. When separate work emerges during a task,
  open another issue instead of widening the current one.
- The title names the result: a context followed by an action. The issue, its tracker task and its
  pull request carry the same title.
- Titles carry **no prefix** such as `[BE]` or `[FE]`, and never a slice or phase identifier. The
  work category is a label on the issue (GitHub) and on the task (Freelo).
- Work blocked by an open dependency, an open question or a `proposed` ADR is not ready to start
  ([domain.md](domain.md#open-points-and-conflicts)).
- Use the `esoul-tasking` skill to create issues and tasks; it previews the result and asks for
  approval before writing.

## Branches

- Never push directly to the default branch. Every change goes through a pull request.
- Name a branch `<prefix>/<issue-number>-<english-kebab-case>` without diacritics, derived from the
  title, for example `feature/142-cart-shipping-recalculation`.
- Prefix by work type: `feature/`, `fix/`, `chore/`, `data/`, `docs/` or `design/`.
- One branch equals one issue.

## Commits

- Format: `:emoji: [optional-context] imperative message`, optionally followed by a blank line and
  a body that explains reasoning or trade-offs, not the subject again.

  ```text
  :sparkles: [checkout] add delivery selection
  :bug: prevent duplicate payment callbacks
  :white_check_mark: [checkout] cover delivery selection
  ```

- Exactly **one Gitmoji shortcode** per commit (`:sparkles:`, never the Unicode glyph). If one
  commit needs two, it is two commits.
- The optional `[context]` is a lowercase kebab-case subsystem, package or application name. Use
  it only when it narrows the change.
- The message is imperative and starts lowercase (`add`, `fix`, `remove`; not `added` or
  `adding`); no trailing period. Keep the subject to **72 characters or fewer** when practical.
- **Split commits by intention**: implementation, tests, refactoring, documentation, configuration
  or tooling and dependency changes are separate commits, even when they belong to one feature.
  A commit is a coherent change, not necessarily one file.
- Run the stack's **formatter before every commit** (the stack's tools guideline, `tools.md`); it is cheap. Tests and
  static analysis run once on the code that will be pushed
  ([testing.md](testing.md#testing-workflow)).
- Use the `gitmoji-commit` skill to split, stage and write commits. It inspects the diff, stages
  only the work that belongs to the task and respects hooks; never bypass hooks with
  `--no-verify`.
- Never rewrite published history (no force push to a shared branch) and never amend, squash or
  reformat commits you did not create.
- Every push starts a CI build, so do not push each commit separately. Push after a coherent unit
  is complete, and at least once per day so progress is visible.

## Repository rules

- **Language.** Code, commits, pull requests and developer documentation are English; text the
  user of the application reads is in the project's language
  ([documentation.md](documentation.md#authoring-rules), [domain.md](domain.md#languages-and-locales)).
- **No secrets or client data.** Never commit credentials, `.env` files, database exports,
  generated PDFs or personal data of the client and its customers
  ([security-and-data.md](security-and-data.md)).
- **No passing edits to shared sources.** `CONTEXT.md`, ADRs and guidelines are not changed in
  passing inside a feature pull request; a guideline change is its own pull request
  ([documentation.md](documentation.md#documentation-rides-with-the-change)).
- **New dependencies need maintainer approval.** The pull request that adds a package states the
  approval; without it the dependency is not added.
- **No report files.** An agent does not write summary, report or analysis Markdown files unless
  asked to.

## Pull requests

- Open the pull request when the work meets the Definition of Done; keep work in progress as a
  draft.
- Keep pull requests **small and focused**: one issue, no unrelated refactoring mixed with a
  feature. A pull request changing more than roughly 30 files is split; when that is impossible,
  its description says how to review it in parts. Review findings are never tracked in a
  repository file instead ([code-review.md](code-review.md#findings-stay-in-the-pull-request)).
- The pull request carries the same title as its issue. Set at least one other person as
  **Reviewer** and every contributor as **Assignee**.
- Fill in the repository's pull request template. It links the issue (`Closes #<issue>`), lists
  every check you ran with its result ([testing.md](testing.md#testing-workflow)), names accepted
  mutants and the `Bez testu:` line where they apply, and lists decisions you made on your own so
  the reviewer can confirm them.
- A pull request includes the documentation, changelog and glossary updates its change requires,
  and a change to the architecture or to an external contract comes with its ADR in the same
  pull request ([documentation.md](documentation.md#documentation-rides-with-the-change)).
- Reviews follow [code-review.md](code-review.md): CodeRabbit and a human review, every finding
  answered, and the `final-pr-review` skill to finish the pull request.
- A green `Jenkins` check is required before merge.
- **The author merges**, as a human, after one approval and a green build, with a **squash merge**
  whose message is the pull request title. Agents never merge, approve or use a ruleset bypass.

## From issue to merge

1. Read the issue, its acceptance criteria and the guidelines of the area you touch.
2. Create the branch from an up-to-date default branch.
3. Implement in small steps with behavioural tests ([testing.md](testing.md)).
4. Format before each commit; run tests and static analysis once on the code that will be pushed
   (the stack's tools guideline, `tools.md`).
5. Commit by intention with the `gitmoji-commit` skill and push.
6. Open the pull request from the template and work through CI and review until it is approved.
7. The author merges. The task is then moved to its finished state and the work is reported.
