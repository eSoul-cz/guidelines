---
title: Frontend
order: 150
summary: LSR frontend layout - Vite and Latte integration, Inertia on the server, design system setup and tooling commands.
---
# Project guidelines - Frontend

The frontend is Vue 3 + Inertia 3 (React is not supported) built with Vite, TypeScript and Tailwind CSS 4, rendered into a Latte page shell by LSR controllers. `package.json` and its lockfile are authoritative for installed versions.

## Frontend layout

| Path                                  | Holds                                                                                  |
| ------------------------------------- | -------------------------------------------------------------------------------------- |
| `assets/js/app.ts`                    | Inertia entrypoint (`createInertiaApp`)                                                |
| `assets/js/pages/**/*.vue`            | Inertia pages, resolved by name (`Index`, `Auth/Login`); one per route-level screen    |
| `assets/js/layouts`                   | shared layouts assigned with `defineOptions({ layout: ... })`                          |
| `assets/js/components`                | application components                                                                 |
| `assets/js/components/ui`             | shadcn-vue primitives, one folder per component with an `index.ts`                     |
| `assets/js/composables`               | composables (`useXxx.ts`)                                                              |
| `assets/js/lib/utils.ts`              | shared helpers, including `cn()` for merging Tailwind classes                          |
| `assets/js/types`                     | shared TypeScript types                                                                |
| `assets/css/tailwind.css`             | Tailwind entry and design tokens                                                       |
| `components.json`                     | shadcn-vue configuration (style, aliases, icon library)                                |

- The path alias `@` points to `assets/js`; use it instead of long relative imports.
- A page name used by a controller MUST exist in `assets/js/pages` with exactly the same case and path.
- Tests are colocated with the code they cover as `*.test.ts`.

## Vite and Latte integration

- Vite builds into `build/` (`base: '/build/'`); `build/` is generated and ignored by git.
- The Latte layout (`templates/@layout.latte`) includes the entries through the asset helpers of `nette/assets` (`{asset 'assets/css/tailwind.css'}`, `<script n:asset="assets/js/app.ts" type="module">`). The entries are declared once in `vite.config.ts` through `@nette/vite-plugin`; a new entry is declared there and in the layout, nowhere else.
- In development the Vite dev server (`pnpm dev`) serves the entries with HMR; in production the layout references the hashed files from the build manifest. The production image contains the built `build/` directory.
- Vite never serves secrets: the dev server denies `.env*`, `private/`, `config/`, `temp/`, `logs/` and `vendor/`.
- Only public values are exposed to the browser through Vite's client env mechanism or through Inertia shared props; nothing else leaves the server.
- Server-side rendering is opt-in. It adds a second Vite build, a Node process supervised by RoadRunner and a rendered head in the layout; enable it only by decision ([deployment.md](deployment.md)).

## Inertia on the server

- Inertia controllers extend `Lsr\Core\Controllers\Controller` and use `Lsr\Inertia\Http\WithInertia`: `return $this->inertia('Auth/Login');`, with the parameters set as a typed DTO from `src/Http/Templates`.
- `Lsr\Inertia\Middleware\InertiaMiddleware` is registered in a named route middleware group and attached to every Inertia route. Controllers MUST NOT set Inertia headers or version-mismatch responses themselves.
- Props are the page contract: minimal, serializable and free of sensitive attributes. Return DTOs, models or scalars whose normalised shape is intentional; never `Dibi\Row`, services or framework objects.
- Use the adapter's prop helpers (`inertiaLazy`, `inertiaAlways`, `inertiaDefer`, `inertiaMerge`, `inertiaOnce`) for partial, deferred and merged data instead of custom conventions. Read the installed `lsr/inertia` source for the exact semantics.
- Server-owned enums, lookup values and constants reach the frontend through an explicit props contract; they are not duplicated by hand in TypeScript.
- Domain validation, normalisation and authorization stay on the server: request DTOs validate input, the frontend only displays the returned errors.
- Shared props (authenticated user, locale, flash messages) are provided in one place, the Inertia shared-data factory, and are updated on every navigation.
- Error responses (404, 403, 500) render an Inertia `Error` page, and use a non-Inertia fallback when the frontend itself is the failure.
- Do not hard-code route paths in Vue. Receive URLs from the backend route contract so route names and localised paths stay a server concern.

## Design system and Tailwind

- Tailwind CSS 4 runs through `@tailwindcss/vite`; there is no `tailwind.config.js`. Tokens (colours, radii, fonts, spacing) are CSS variables defined once in the Tailwind entry and mapped with `@theme`.
- shadcn-vue is the component system: style `new-york`, CSS variables enabled, Lucide icons, components generated into `assets/js/components/ui` through the `components.json` aliases. Generated components become application code: review accessibility, imports and tokens when adding or updating one.
- Import shadcn-vue components through each folder's `index.ts`; keep feature behaviour out of the generic `ui` primitives.
- Use `cn()` from `assets/js/lib/utils.ts` for conditional or merged classes.
- Do not add a second component library or a second styling system.
- Colours and spacing come from the tokens; no arbitrary hex values or magic sizes in templates.

## Frontend tooling

```bash
pnpm dev            # Vite dev server
pnpm build          # production build
pnpm eslint         # ESLint, read-only
pnpm format:check   # Prettier, read-only
pnpm tsc            # vue-tsc --noEmit
pnpm test:fe        # Vitest
pnpm lint:fix       # ESLint --fix (rewrites files)
pnpm format         # Prettier --write (rewrites files)
```

- ESLint uses the flat config (`eslint.config.js`); Prettier uses printWidth 150 and tabWidth 4 with the organise-imports and Tailwind class-sorting plugins.
- Lint or type errors MUST NOT be suppressed without a real boundary; document any exception and narrow it to the smallest possible scope.
- Tool caches go to `temp/tools/` ([tools.md](tools.md#tool-caches)).
- CI runs the read-only commands only ([tools.md](tools.md#read-only-verification)).
