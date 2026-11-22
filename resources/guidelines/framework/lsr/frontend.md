---
title: Frontend
order: 150
summary: LSR frontend specifics - asset root, Vite and Latte integration, Inertia on the server, design system and Tailwind setup and tooling commands.
---
# Project guidelines - Frontend

In an LSR application the frontend is built with Vite, TypeScript and Tailwind CSS and rendered into a Latte page shell by LSR controllers. `package.json` and its lockfile are authoritative for installed versions ([tools.md](tools.md#versions)).

## Frontend layout

| Path                       | Holds                                                                         |
| -------------------------- | ----------------------------------------------------------------------------- |
| `assets/js/app.ts`         | Inertia entrypoint (`createInertiaApp`)                                       |
| `assets/js/pages/**/*.vue` | Inertia pages, resolved by name (`Home`, `Auth/Login`); one per route-level screen |
| `assets/css/tailwind.css`  | Tailwind entry and design tokens                                              |
| `components.json`          | shadcn-vue configuration (style, aliases, icon library)                       |

- `<js-root>` is `assets/js`. The Vue structure rules ([Project structure and locality](#project-structure-and-locality)) come from the `package/inertia-vue` layer, which the installer adds whenever `@inertiajs/vue3` is in `package.json`, as it is in every LSR frontend of this layout; they apply under `<js-root>`. The template ships only the entrypoint, `pages` and `design-system/ui`; the other folders are created when first needed.
- The path alias `@` points to `<js-root>`; use it instead of long relative imports.
- A page name used by a controller MUST exist in `assets/js/pages` with exactly the same case and path.
- Tests are colocated with the code they cover as `*.test.ts`.

## Vite and Latte integration

- Vite builds into `build/` (`outDir`, with `assetsDir` `assets`); `build/` is generated and ignored by git. The config sets no `base`.
- The Latte layout (`templates/@layout.latte`) includes the entries through the asset helpers of `nette/assets` (`{asset 'assets/css/tailwind.css'}`, `<script n:asset="assets/js/app.ts" type="module">`). The entries are declared once in `vite.config.ts` through `@nette/vite-plugin`; a new entry is declared there and in the layout, nowhere else.
- In development the Vite dev server (`pnpm dev`) serves the entries with HMR; in production the layout references the hashed files from the build manifest. The production image contains the built `build/` directory ([deployment.md](deployment.md#image)).
- Vite never serves secrets: the dev server denies `.env*`, certificates, `.git`, `private/`, `config/`, `temp/`, `logs/`, `vendor/` and `docker/`. Keep that deny list when editing `vite.config.ts`.
- Only public values are exposed to the browser through Vite's client env mechanism or through Inertia shared props; nothing else leaves the server.

## Inertia on the server

- Inertia controllers extend the application base controller (`App\Core\Controller`) and use `Lsr\Inertia\Http\WithInertia`: `return $this->inertia('Auth/Login', $parameters);`, with the parameters set as a typed DTO from `src/Http/Templates`.
- `Lsr\Inertia\Middleware\InertiaMiddleware` is registered in the `web` middleware group ([architecture.md](architecture.md#routing-and-controllers)) and attached to every Inertia route. Controllers MUST NOT set Inertia headers or version-mismatch responses themselves.
- Props are the page contract: minimal, serializable, free of sensitive attributes, services and framework objects. Return DTOs, models or scalars whose normalised shape is intentional.
- Use the adapter's prop helpers (`inertiaLazy`, `inertiaAlways`, `inertiaDefer`, `inertiaMerge`, `inertiaOnce`) for partial, deferred and merged data instead of custom conventions. Read the installed `lsr/inertia` source for the exact semantics.
- Shared props (authenticated user, locale, flash messages) are provided in one place, the Inertia shared-data factory, and are updated on every navigation.
- Error responses (404, 403, 500) render an Inertia `Error` page, and use a non-Inertia fallback when the frontend itself is the failure.
- Do not hard-code route paths in Vue. Receive URLs from the backend route contract so route names and localised paths stay a server concern.
- Server-side rendering is **disabled by default** (`inertia.ssr.enabled: false`). When a project enables it by decision, only an ordinary GET HTML response is rendered by the Node renderer: Inertia (`X-Inertia`) requests, HEAD and POST bypass it, and a renderer failure falls back to client rendering. Enabling it adds a second Vite build, a renderer service and a rendered head in the layout, and is not part of the default image.

## Design system

- shadcn-vue is the component system: style `new-york`, CSS variables enabled, components generated into `assets/js/design-system/ui` through the `components.json` aliases. `components.json` sets the icon library to `lucide`, but the template installs no icon package; add it with the first component that needs icons. Generated components become application code: review accessibility, imports and tokens when adding or updating one.
- Do not add a second component library or a second styling system.

## Tailwind CSS

- Tailwind CSS runs through `@tailwindcss/vite`; there is no `tailwind.config.js`. Tokens (colours, radii, fonts, spacing) are CSS variables defined once in the Tailwind entry and mapped with `@theme`.
- Colours and spacing come from the tokens; no arbitrary hex values or magic sizes in templates.

## Frontend commands

```bash
pnpm dev            # Vite dev server
pnpm build          # production build
```

- The quality commands follow in the shared Vue list. **Target.** The pipeline runs only `eslint` and `format:check` today ([Delivery path](deployment.md#delivery-path)); `tsc` and `test` are read-only commands too and join it with the V2 template item, so the CI state described here is the target, not the present one.
- ESLint uses the flat config (`eslint.config.js`) with `@typescript-eslint/no-explicit-any` enabled; do not switch it off. Prettier uses printWidth 150 and tabWidth 4 with the organise-imports and Tailwind class-sorting plugins and covers `assets/`.
