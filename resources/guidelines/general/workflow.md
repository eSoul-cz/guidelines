---
title: Workflow
order: 60
agents: true
summary: Tasking basics, branch naming, commit convention, pull request rules and the path from issue to merge.
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
- A project MAY prefix titles with the work category (`[BE]`, `[FE]`, `[DATA]`, `[OPS]`); if it
  does, its `CONTEXT.md` says so and every title follows it. A project that does not use prefixes
  keeps the category in labels. Titles never carry slice or phase identifiers.
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
- The message is English, imperative and starts lowercase (`add`, `fix`, `remove`; not `added` or
  `adding`); no trailing period. The whole subject is **at most 72 characters**.
- **Split commits by intention**: implementation, tests, refactoring, documentation, configuration
  or tooling and dependency changes are separate commits, even when they belong to one feature.
  A commit is a coherent change, not necessarily one file.
- Use the `gitmoji-commit` skill to split, stage and write commits. It inspects the diff, stages
  only the work that belongs to the task and respects hooks; never bypass hooks with
  `--no-verify`.
- Never rewrite published history (no force push to a shared branch) and never amend, squash or
  reformat commits you did not create.
- Every push starts a CI build, so do not push each commit separately. Push after a coherent unit
  is complete, and at least once per day so progress is visible.

## Pull requests

- Open the pull request when the work meets the Definition of Done; keep work in progress as a
  draft.
- Keep pull requests **small and focused**: one issue, no unrelated refactoring mixed with a
  feature. A pull request changing more than roughly 30 files is split, or its description says
  how to review it in parts ([code-review.md](code-review.md)).
- Fill in the repository's pull request template. It links the issue (`Closes #<issue>`), lists
  every check you ran with its result ([testing.md](testing.md#testing-workflow)), names accepted
  mutants and the `Bez testu:` line where they apply, and lists decisions you made on your own so
  the reviewer can confirm them.
- A pull request includes the documentation, changelog and glossary updates its change requires
  ([documentation.md](documentation.md)).
- Every pull request gets the CodeRabbit review and a human review. Fix valid CodeRabbit findings
  and answer invalid ones with evidence; do not resolve a thread silently
  ([code-review.md](code-review.md#answering-findings)). Finish the pull request with the
  `final-pr-review` skill.
- A green `Jenkins` check is required before merge. Reproduce a red build locally before pushing
  its fix.
- Squash merge after one human approval. Agents never merge, approve or bypass a ruleset.

## From issue to merge

1. Read the issue, its acceptance criteria and the guidelines of the area you touch.
2. Create the branch from an up-to-date default branch.
3. Implement in small steps with behavioural tests ([testing.md](testing.md)).
4. Run the stack's checks once on the code that will be pushed ([tools.md](tools.md)).
5. Commit by intention with the `gitmoji-commit` skill and push.
6. Open the pull request from the template and work through CI and review until it is approved.
7. A human merges. The task is then moved to its finished state and the work is reported.
