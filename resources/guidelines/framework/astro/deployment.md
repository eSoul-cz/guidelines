---
title: Deployment and runtime
order: 140
summary: Jenkins delivery of a static Astro site (a FROM scratch image through Komodo, or deployStaticSite), the shared pipeline steps, publish flow, build arguments, preview protection, one NGINX site with the backend, proxy and headers.
---
# Project guidelines - Deployment and runtime

Astro runtime detail added to the deployment scheme. The `Jenkinsfile` is one call of the shared `astroPipeline` step ([Pipeline steps](#pipeline-steps)), which runs the checks of [tools.md](tools.md#jenkins-checks-stage) first and then delivers on the deploy branches. A site is **static**: what is deployed is the built `dist/` directory and the host NGINX serves it.

## Delivery paths

Two paths, chosen by whether the site has a CMS. Both end in the same server layout: `/data/apps/<client>/<app>/releases/<id>/` holds the complete build and `current` is a link that is switched atomically, so the previous version stays live until the new one is complete. No path puts a web server container or a shared volume between the build and NGINX.

### Site with a CMS: image to Komodo

The default (`C-headless`): a static Astro site and a Laravel/Filament backend that publishes it. The two halves come from the templates `astro-cms-frontend` and `laravel-cms`, tied together by the root template `astro-cms` ([Repository layout](architecture.md#repository-layout)).

```text
push to a branch with a deploy rule, or Publish in the backend
  └─ Jenkins (the frontend repository)
       ├─ Checks stage
       ├─ dockerBuildMultiArch → rg.fr-par.scw.cloud/app-images/<app>:latest (+ Git tags), an image FROM scratch holding only dist/
       └─ deployKomodoStack    → the site's Komodo stack
            └─ deploy hook: docker create the image, copy the assets into releases/<id>, switch current
```

- The `Dockerfile` is multi-stage. The build stage runs `pnpm install --frozen-lockfile`, `pnpm build` and then `pnpm verify` (it needs network: the Fonts API downloads fonts), so the image never holds a `dist/` that the checks of the [`Checks` stage](tools.md#jenkins-checks-stage) did not see, even when the CMS export changed between the two builds. The final stage is `FROM scratch` and copies in that verified `dist/` and nothing else: no operating system, no server, no source, no `.env`, no secrets. The image is only the container the assets travel in.
- `.dockerignore` keeps host `node_modules`, `.env`, runtime data, `docs/`, source photos and tests out of the build context. Native modules are installed inside the build, for the target platform.
- The deploy hook of the stack creates (does not start) a container from the image, copies the assets into a new `releases/<id>/` directory, switches `current` atomically and prunes old releases. A new image therefore never leaves the old site behind, and a failed copy leaves the previous release live.
- **Rollback is redeploying the previous image** (`previous-<stack>`, [Environments and releases](#environments-and-releases)): the hook turns it into a new release and switches `current`.
- **Hashed `_astro` files.** A page opened before the switch may request a lazy chunk from `/_astro/` that the new release no longer holds. The hook SHOULD share the hashed `_astro` files between releases as `deployStaticSite` does ([Site without a CMS](#site-without-a-cms-static-release)): it runs the same release script (`static-site-release.sh`, action `install`), which hardlinks them into `shared/`. **Target.** A hook that still copies the assets by hand does not share them, and such a page gets a `404` for that chunk and works again after a reload; which hook a stack runs is recorded in its manifest in the infrastructure repository.
- The site holds no persistent state. The CMS media are the backend's bind-mounted public directory ([Reverse proxy and TLS](#reverse-proxy-and-tls)); the backend is a separate repository with its own pipeline, image and stack ([architecture.md](architecture.md#repository-layout)).

### Site without a CMS: static release

For `A-static`, a truly static site. There is no image and no Komodo stack.

A push to a branch with a deploy rule starts the Jenkins job: install, the checks (which include `pnpm build` and `pnpm verify`), then `deployStaticSite` uploads `dist/` to a new `releases/<id>/` directory of the server, shares the hashed `_astro` files between releases, switches the `current` link atomically and prunes old releases. A failed build leaves the previous version live; `rollbackStaticSite` re-points `current` to the previous or a named release. The manifest uses the `static-release` shape.

### Exceptions: SSR and `D-static-api`

`B-admin` (SSR on Node) has the `astro-ssr` template and `D-static-api` has none; the ADR of either names how it is delivered. An SSR image follows the scheme like any application image, and the `astro-ssr` template implements it:

- It is built with `dockerBuildMultiArch` and deployed with `deployKomodoStack`; the image holds only what runs, runs as an unprivileged user under an init process, has a healthcheck and stops gracefully on `SIGTERM`.
- Where the host NGINX serves client files, the deploy hook copies them out of the image into the run directory (`./client`) on **every** deploy, so a new image never leaves the old site behind.
- Settings validation runs before the server listens, and an invalid setting exits the container. Migrations run next and a failing migration does not stop the container ([production start entrypoint](#the-production-start-entrypoint)): the start logs `MIGRATION FAILED` on standard error and the server listens anyway. The start never runs the seeds; a developer runs them by hand in the container (`node src/server/seed.ts`; the image holds no pnpm).
- Persistent state (database, uploads, form backups, logs) is bind-mounted from the stack's run directory (`./data` in the `node-http` shape) and listed in `README.md`; the infrastructure's backups cover it.

### Pipeline steps

The steps live in the shared Jenkins libraries (eSoul-cz/jenkins-libraries); each has a `.txt` with its parameters, stage names and validation.

- `astroPipeline` (`pipelines` library): the whole `Jenkinsfile`, in one call. Mode `static` runs the checks and then `deployStaticSite` for a site without a CMS; mode `image` verifies the build, builds the `FROM scratch` image from the verified `dist/` (`dockerBuildMultiArch`) and redeploys the stack (`deployKomodoStack`), with an optional report of a CMS publish back to the backend (the `cms` callback). A site configures only what differs from its defaults. A build that changes only documentation (`docs/`, Markdown at the top of the repository, `.github/`, agent and tool files; not content under `src/`) runs only the `Guidelines check` and, on a deploy branch, builds and deploys nothing (`changeFilter`, on by default); a publish of the CMS and `Build Now` always run everything.
- `Guidelines check`: a stage of `astroPipeline`, on by default when the project has `esoul-guidelines.lock` (`guidelinesCheck: false` opts out); it runs `esoul-guidelines check` with the installer version the lock records, so a Node-only repository needs no PHP project around it.
- `deployStaticSite` (`deploy` library): the atomic release of the path without a CMS. With `verify` it checks the live site after the switch (the release marker of the new release, the HTML and hashed-asset cache headers: HTML `no-cache`, hashed assets immutable) and switches back to the previous release when the check fails.
- `rollbackStaticSite` (`deploy` library): re-points `current` atomically to the previous release or a named one, on a site of either path; nothing is deleted.
- One server layout and one release script (`static-site-release.sh`) for both paths: `deployStaticSite`, `rollbackStaticSite` and the Komodo copy hook of an image run the same file.
- `astroCmsPipeline` is not a step: the image path of a CMS site is `astroPipeline` in mode `image`.

### Publish flow (archetype `C-headless`)

- The build agent fetches the export from the backend over its public URL, so **`CMS_API_ALLOWED_IPS` of the backend MUST contain the address of the build agent**. A changed agent address makes the export answer 403 and blocks every publish until the list is updated; record the address in `README.md`.
- A publish is not a code change: the editor's publish action starts the site's Jenkins job on the already merged code and needs no pull request ([How an application reaches production](#how-an-application-reaches-production)).
- The backend is reached on a subdomain (RECOMMENDED) or under a path such as `/admin`; the project records which in `CONTEXT.md` ([Reverse proxy and TLS](#reverse-proxy-and-tls)). The static build fetches the export server-side in both modes, and `CMS_API_ALLOWED_IPS` applies in both.
- A publish that has no result after **`CMS_PUBLISH_TIMEOUT`** minutes (default 30) is marked failed in the backend, so the publish button cannot stay blocked. The Jenkins callback to the backend is best effort and never fails the build; a late callback is still recorded.
- Concurrent builds are disabled: two publishes would overwrite each other's release.
- In CI the build fails when the export is unreachable or invalid; it never falls back to a fixture or to old data ([content.md](content.md#c-headless-edited-in-a-separate-backend)).

## Build arguments

A static build bakes public values in at build time, so they are passed as build arguments from the `Jenkinsfile`, never read from a runtime `.env`. The process environment wins over a local `.env`, so Jenkins needs no file:

| Build argument      | Source        | Meaning                                                       |
| ------------------- | ------------- | ------------------------------------------------------------- |
| `SITE_URL`          | `Jenkinsfile` | Canonical origin (feeds `site` in `astro.config.mjs`)         |
| `PUBLIC_NOINDEX`    | `Jenkinsfile` | `true` while the preview is protected                         |
| `PUBLIC_<NAME>`     | `Jenkinsfile` | Any other public value (analytics id, form endpoint URL); documented in `.env.example` |

- An empty analytics or consent id means the script is not included at all.
- Secrets (SMTP, database, captcha secret, API tokens) are **never** build arguments and never baked into an image or the build output. They belong to the backend's stack at runtime ([security-and-data.md](security-and-data.md#secrets)); a token the build itself needs, such as the export token, is a Jenkins credential.
- `.env.example` lists every variable with a placeholder.

## Preview protection

Until launch, the deployed site is a protected preview, as [public-website.md](public-website.md#preview-gate) requires. The Astro implementation:

- **HTTP basic auth** in the web server. The password is a secret: its home is a manifest secret, that is a Komodo variable ([Configuration and secrets](#configuration-and-secrets)), never the repository, the manifest or a rendered file; the NGINX site only enforces the authentication. It is a barrier against accidental visitors, but it is still a credential. `robots.txt` stays reachable without auth.
- **`PUBLIC_NOINDEX=true`** is the one switch: it puts `noindex` on every page, makes `robots.txt` disallow everything and builds no sitemap.
- The preview URL is recorded in `README.md`.

Switching protection off is the launch step in the project's launch checklist: remove basic auth, set `PUBLIC_NOINDEX=false`, deploy, then verify headers, sitemap and indexability. Nothing else changes in the same deploy.

## Reverse proxy and TLS

- TLS ends at the load balancer; the site is HTTPS-only with HSTS, and `www` and non-`www` redirect to the canonical host. A static site has no upstream: the host NGINX serves `current` itself. An SSR site on Node is one of the specific cases the host NGINX reaches over plain HTTP (the `node-http` shape).
- **One NGINX site definition serves the frontend and the backend.** The backend is on a subdomain (**RECOMMENDED** and the default of the templates, for example `admin.<domain>`) or under a path of the site's domain (such as `/admin`, the documented exception); the project decides per application and records it in `CONTEXT.md`. The backend is reached over FastCGI like every PHP application; on a path, only the backend's own paths are routed to it (`/admin`, `/cms-api`, `/livewire*`, `/up`) and everything else is the static site.
- The backend's session cookie is host-only: it is never scoped to the parent domain. On a **subdomain** that keeps the admin session away from the public site. Under a **path** of the site's domain a host-only cookie is still sent with every request to that host, so the cookie MUST be scoped to the backend's path (`Path=/admin`) or the backend moves to a subdomain.
- The proxy sends `X-Forwarded-Host`, `X-Forwarded-Proto: https` and an `X-Forwarded-For` that it **replaces**, not appends; an SSR site trusts forwarded headers only for its own domains (`security.allowedDomains`). Rate limits depend on the client address being right.
- The **Content-Security-Policy of a static site comes from Astro's `security.csp`** at build time (a `<meta>` tag per page; the build computes the hashes of inline scripts and styles, so no `unsafe-inline` is needed). `frame-ancestors` does not work in `<meta>`.
- nginx sets the other response headers on every response, static files included: `X-Frame-Options` (or `frame-ancestors` in a header CSP), `X-Content-Type-Options: nosniff`, `Referrer-Policy` and HSTS. In nginx, a `location` with its own `add_header` repeats the shared header snippet, because nginx drops inherited headers. HTML is served `no-cache`; hashed assets (`/_astro/*` and the media path) get a long, immutable cache.
- On `D-static-api`, a runtime API call from the browser needs the backend paths in `connect-src` of `security.csp`.
- On `C-headless`, NGINX serves the media under `/_cms/` from the backend's bind-mounted public directory on the same host (under the backend's run directory). The site makes no runtime call to the backend, but its media depend on that directory being readable by NGINX.
- Redirects from an old site live in this NGINX site, handed over in the pull request's deploy notes ([public-website.md](public-website.md#redirects-from-an-old-site)). A `B-admin` site MAY keep them in the application instead, managed in its admin ([architecture.md](architecture.md#server-code)).

## Forms in production

- The form's captcha keys are registered for the production domain, SMTP is configured from the backend's stack, and a test submission is sent and received after every launch-relevant deploy.
- The retention period for stored submissions is recorded in the project's decisions ([security-and-data.md](security-and-data.md#personal-and-client-data)); submissions kept in the database, the backup table or the last-resort file are all covered by it.
