---
title: Tools
order: 130
summary: Versions, pnpm hardening, local checks, the Jenkins Checks stage, Playwright and Lighthouse budgets.
---
# Project guidelines - Tools

Company-wide standards are at https://dev.docs.esoul.cz; this file lists the tools the project uses and how to run them.

## Versions

| Area            | Tool                                                    | Version / source                                  |
| --------------- | ------------------------------------------------------- | ------------------------------------------------- |
| Runtime         | Node.js                                                 | 24 (`engines` in `package.json`, `.nvmrc`)        |
| Package manager | pnpm                                                    | 10.20.0 (`packageManager` in `package.json`)      |
| Framework       | Astro                                                   | 7 (`package.json` is authoritative)               |
| Styling         | Tailwind CSS 4 via `@tailwindcss/vite`                  | [frontend.md](frontend.md)                        |
| Islands         | one UI framework (Vue or React), by decision            | [architecture.md](architecture.md#islands)        |
| Type checks     | `astro check`, `tsc --noEmit`                           | `tsconfig.json`                                   |
| Lint and format | ESLint (flat config), Prettier with the Astro plugin    | `eslint.config.mjs`, `prettier.config.mjs`        |
| Browser tests   | Playwright                                              | `playwright.config.ts`, `e2e/`                    |
| Audits          | Lighthouse, axe                                         | `scripts/` or `e2e/`                              |
| Delivery        | Docker or static rsync, Jenkins, Komodo                 | [deployment.md](deployment.md)                    |

`package.json` and `pnpm-lock.yaml` are authoritative for versions. Use the Astro documentation for the installed major (the Astro Docs MCP when available) and check the installed source before concluding that a feature does not exist. pnpm 11 does not work with older Node versions; stay on the pinned pnpm (`corepack prepare pnpm@10.20.0 --activate`).

## Where tools run

- Run the Astro CLI through pnpm: **`pnpm astro ...`, never `npx`** (and no `npx` for any other CLI the project installs).
- Start the dev server with `pnpm dev`; an agent runs it in the background and stops it when done.
- Zod comes from `astro/zod`. Invalid HTML is a compile error; close every non-void element.
- Jenkins runs the same scripts as local development, in a Node 24 image ([Jenkins `Checks` stage](#jenkins-checks-stage)).
- Do not keep the working copy in a cloud-synchronised folder; duplicate folders have produced phantom routes. When the build behaves oddly, `rm -rf dist && pnpm build`.

## pnpm hardening

`pnpm-workspace.yaml` hardens installs; never remove these settings:

- `minimumReleaseAge: 1440`: new versions install only once they are a day old. Exempt a newer version only when it is needed (a security fix), in `minimumReleaseAgeExclude`, with a comment.
- `trustPolicy: no-downgrade`: an install fails when a version has weaker trust signals than earlier ones. Exempt only exact, verified versions in `trustPolicyExclude`, with a comment.
- Build scripts run only for packages listed in `allowBuilds`.
- `engine-strict` keeps everyone on the Node version CI uses.

Adding a dependency needs the maintainer's approval. When a maintained library that works with Astro solves the problem, use it instead of writing our own.

## Read-only verification

```bash
pnpm check          # astro check + tsc --noEmit
pnpm lint           # ESLint
pnpm format:check   # Prettier, read-only
pnpm build          # astro build, plus any post-build verification
pnpm test:e2e       # Playwright smoke and SEO specs at 1440 and 390 px
```

Run them before every push; Jenkins runs the same commands. They MUST NOT rewrite tracked files.

## Formatting and lint autofixes

`pnpm format` (Prettier `--write`) and `pnpm lint --fix` rewrite files. Run them deliberately, review the diff, then rerun the read-only checks. CI uses the read-only commands only.

## Documentation checks

`README.md` documents how to run, build and deploy the site, how content is edited (Markdown, admin or backend) and how to add a repeated content entry. When the repository ships a documentation launcher (`docs/documentation validate`, `docs/documentation build`), validate the guides with it before publishing them; the build requires Docker.

## Jenkins `Checks` stage

The `Jenkinsfile` has a **`Checks` stage that is mandatory** and runs before any image is built or any file is deployed. It fails the build when any step fails:

1. `pnpm check`: `astro check` and `tsc --noEmit`;
2. `pnpm lint` and `pnpm format:check`;
3. `pnpm build`, including the post-build verification of the output;
4. **Playwright smoke** of every route at **1440x900 and 390x844**, against the built site (`pnpm preview` or the built image), failing on any console error, failed request or broken image;
5. **SEO spec**: every indexable route has a title and description within limits, one `h1`, a canonical URL, Open Graph tags and an image; the sitemap lists exactly the indexable routes; `robots.txt` and `llms.txt` exist ([seo.md](seo.md)).

## Playwright

- Specs live in `e2e/*.spec.ts` and import `test` and `expect` from `e2e/fixtures.ts`, which fails any test that logs a console error. A test that causes one on purpose lists it explicitly.
- Two projects match the design frames: `desktop` (1440x900) and `mobile` (390x844, touch), with the site's locale and time zone.
- Wait for islands to hydrate before interacting with them.
- A test that changes content is tagged `@writes` and never runs against a deployed environment; `BASE_URL` points the read-only tests at a running deployment.
- Admin end-to-end tests (archetype `B-admin`) log in once in a setup project and never log out the shared session.

## Lighthouse and accessibility

Recommended on every pull request that changes layout, images, fonts or scripts, and **mandatory before launch**:

| Metric (mobile, production build)    | Budget  |
| ------------------------------------ | ------- |
| Performance                          | >= 90   |
| Accessibility, Best practices, SEO   | 100     |
| Largest Contentful Paint             | < 2.5 s |
| Cumulative Layout Shift              | < 0.05  |

- axe reports no violations on any route at both widths.
- While basic auth and `noindex` protect the preview, the SEO score is lower by design; measure it again after they are switched off.
- Record the measured results with the date in `README.md`.

## Admin checks

When the admin (archetype `B-admin`) uses React, run `pnpm react-doctor --scope changed` after React changes.
