---
title: Testing
---
# Project guidelines - Testing

The general rules apply to an Astro project with Playwright (browser specs) and, where islands or helpers hold logic, Vitest. The commands and the Playwright setup are in [tools.md](tools.md#playwright); this file names the Astro equivalents of the PHPUnit mechanisms the general text refers to.

## Test doubles

- **Network guard.** Browser specs run against the built `dist/` and fail on a console error, a failed request or a broken image ([tools.md](tools.md#jenkins-checks-stage)); those checks catch only a request that fails. A spec that needs an external system (a map, an embed, a form backend) answers it with `page.route`. The Playwright setup also adds a catch-all `page.route` that aborts every request to an origin other than the tested site that the spec did not route explicitly, so an unrouted request that would succeed fails the spec instead of making a real call. Explicit mocks keep working. Vitest tests do not touch the network.

## Test groups

- The `sandbox` and `quarantine` groups are PHPUnit groups and do not apply: the default run is every spec. A flaky spec is fixed or removed, not parked.
