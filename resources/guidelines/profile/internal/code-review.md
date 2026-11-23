# Project guidelines - Code review

## Who reviews

<!-- replace -->
- Only privileged people change this repository, so the human review is the **owner's explicit
  go-ahead to merge**, given in the pull request, instead of a second reviewer with write access.
  Every pull request is also reviewed by **CodeRabbit**. CodeRabbit review is configured at the
  platform level; the repository's `.coderabbit.yaml` only excludes generated paths. A CodeRabbit
  approval never replaces the owner's go-ahead.
- The author finishes the pull request with the `esoul-review` skill: green `Jenkins` check,
  every CodeRabbit finding answered, local review run where the skill asks for it.
- When CodeRabbit hits its rate limit or runs out of credits, nobody waits for it: the
  `esoul-review` skill posts a fallback review of the same revision into the pull request, and
  its findings are answered the same way. Once they are resolved, its review (a `COMMENT`, not a
  GitHub approval) stands in for CodeRabbit's. It does not replace the owner's go-ahead.
- The author merges under the rules in [workflow.md](workflow.md#pull-requests): the owner's
  explicit go-ahead (or the approval of a reviewer, when one is set) and a green `Jenkins`
  check. Agents MUST NOT merge, approve or use a ruleset bypass unless the human
  owner explicitly authorises it for that pull request or batch; the authorisation, and the reason
  for a bypass, go in the pull request description.
- When review finds defects, the task moves back to `In progress`; review time is logged as a
  work report.
