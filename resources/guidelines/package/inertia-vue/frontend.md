---
title: Frontend
order: 70
summary: Vue + Inertia rules - project structure and locality, component architecture, TypeScript, Inertia data flow, forms, performance, design system, Tailwind usage, frontend commands and tests.
---
# Project guidelines - Frontend

The frontend is Vue (Composition API, `<script setup lang="ts">`), Inertia, TypeScript, shadcn-vue and Vitest. React is not supported. Domain behaviour, validation and authorization stay on the server; the frontend owns presentation state, interaction state and client-side orchestration only. Code that contradicts these rules is a bug to fix, not a precedent to follow.

Paths in this file are relative to the JavaScript asset root, written `<js-root>`. The framework's frontend section names it, and the `@` import alias points to it.

## Project structure and locality

```text
<js-root>/
├── pages/            # Inertia page entry points and their branch-local code
├── design-system/    # domain-independent public UI (App* components)
│   ├── ui/           # private shadcn-vue and vendor-like primitives
│   └── index.ts      # curated public exports
├── components/       # components reused by several page branches
├── layouts/          # persistent and route-level layouts
├── composables/      # globally reusable reactive behaviour
├── utils/            # globally reusable pure functions
├── model/            # globally reusable frontend model classes
├── payload/types.ts  # globally shared backend transport contracts
└── types.ts          # globally shared frontend-only types
```

- Code MUST start in the page branch that owns it. Its components, composables, utils, models, payload contracts and types live directly below that branch.
- Per-page directories such as `Index/components` MUST NOT be introduced; a branch-level `components` directory is used even with one consumer.
- Code moves to a global location only when it has consumers in **separate page branches**, and only as far outward as needed. Moving code outward "because it might be reused" is prohibited; brief duplication is fine until real consumers show the shared boundary.
- Reuse alone does not make a component part of the design system (see [Design system](#design-system)).
- No `components/shared` and no generic `lib` directory. `utils` holds stateless functions only; reactive or lifecycle-aware behaviour is a composable.
- Global modules MUST NOT import from `pages`. Imports outside the current branch use `@/`.
- Hand-maintained directories use kebab-case. Renaming or moving an Inertia page updates the page name the controller passes to Inertia in the same change.

```text
pages/catalog/products/components/ProductSummary   one page branch
          │ reused by another branch
          ▼
components/ProductSummary                          several branches
```

## Component architecture

- Pages compose route-level screens and coordinate Inertia data. Pages and application components compose the project's shared UI components (shadcn-vue based); they do not reimplement generic controls.
- Every component has one clear responsibility. Prefer composition, props, emits and slots over hidden global state or tightly coupled parent access.
- Centralize route, query, filter, parsing and payload builders within their owning feature. Do not duplicate backend-contract mappings across components.
- Props and emits MUST be typed. Props MUST NOT be mutated: derive values with `computed` and emit requested changes upward.
- Use `computed` for derived state and explicit event handlers for reactions. Use `watch` only for a real side effect, and clean up timers, listeners and requests.
- `v-for` MUST use a stable domain identifier as its key. Array indexes MUST NOT be used when items can be inserted, removed, filtered or reordered.
- Extract before extending a component that approaches 500 lines or mixes UI, business and transport logic.
- Non-trivial transformation and method calls SHOULD be moved out of the template into computed values or handlers. Simple conditions and property access MAY remain in the template.
- A child component MUST NOT call `usePage()` for data that can be passed explicitly through props.
- Reactive or lifecycle-aware behaviour that more than one component needs is a composable named `useXxx.ts`. Stateless helpers are plain functions in a module named by its purpose.

### Single-file components

- Order: `<script setup lang="ts">`, then `<template>`, then optional `<style scoped>`.
- Script order: package imports; `@/` imports; branch-local imports; named type aliases; contract macros (`defineModel`, `defineProps`, `defineEmits`, `defineSlots`, `defineExpose`); composables; local state; computed values; handlers; watchers and lifecycle hooks.
- Contracts are named `type` aliases (`Props`, `Emits`, `Slots`), never inline object types in a macro. `Emits` uses call signatures: `(e: 'select-item', item: Item): void`.
- `defineProps()` is never destructured, and templates access props by name, never via `props.` or `$props`.
- Component files use PascalCase and application components have multi-word names. Template tags, attributes and custom events use kebab-case (`<app-button>`, `@select-item`).
- Inertia components that collide with native elements are aliased on import: `Link as InertiaLink`, `Head as InertiaHead`, `Form as InertiaForm`.
- Icons are Vue components imported by name from the icon package (`@lucide/vue`) and passed as components. They are never resolved from a string at runtime, and code never imports the whole namespace (`import * as icons from '@lucide/vue'`) or the icons map: that keeps every icon in the bundle. Decorative icons are hidden from assistive technology.

## TypeScript

- Keep imports at module top. An inline import needs a documented circular-dependency reason.
- Page props, component contracts, form data and API payloads MUST be modeled explicitly.
- Type-only imports MUST use `import type`.
- A `switch` over a discriminated union or enum ends with an exhaustive `never` check in its `default` branch.
- Nullability and optional states MUST be represented, not hidden with assertions.
- `any`, unsafe casts and non-null assertions SHOULD NOT be used. Unknown external values MUST be narrowed or validated at their boundary.
- Server-owned enums, lookup values and constants MUST NOT be duplicated ad hoc. The backend passes their values through an explicit props contract.
- Lint or type errors MUST NOT be suppressed without a real boundary. Any exception is documented and narrowed to the smallest possible scope.

## Inertia data flow

- Server props are the source of truth for server state. Props MUST NOT be copied into local state unless the user is editing a local draft.
- Internal navigation MUST use `InertiaLink` or `router` for programmatic visits. Do not use `window.location` or a plain internal `<a>`.
- Processing, validation, empty, success and failure states MUST be visible to the user, and duplicate submission MUST be prevented while a request is processing.
- Deferred props MUST handle their initial `undefined` state and render a purposeful fallback.
- Partial reloads, polling, prefetching, optimistic updates and infinite scroll SHOULD be used only when they improve a concrete interaction and their loading, stale-data and rollback behaviour is handled.
- Scroll and component state are preserved only intentionally. Blanket preservation options MUST NOT hide stale server data.

## Forms

- Ordinary forms use Inertia's form component; use `useForm` when the form needs programmatic control, and define a typed initial shape.
- Every control MUST have an accessible label and a stable `name`.
- Server validation errors MUST be connected to the relevant control, and its invalid state MUST be perceivable.
- Submit actions MUST be disabled or otherwise guarded while processing.
- Entered values SHOULD be preserved after a validation error unless the product flow requires a reset.
- Client validation MAY improve usability but MUST NOT replace server-side validation or authorization.
- Domain value normalization happens on the server. The browser performs only UI-specific conversion, such as turning an input string into its typed form value.

## Performance

- Avoid deep watchers, repeated expensive template work and unbounded reactive state. Memoize derived values with `computed` where it pays off.
- Remote search SHOULD be debounced, and stale requests MUST be cancelled or ignored.
- Heavy components that are not needed for the first render (a map, an editor, a chart, a non-default tab, a dialog or an edit-only form of a heavy feature) SHOULD load lazily through `defineAsyncComponent` or a dynamic `import()`. Content visible on first render MUST NOT be lazy: it only adds a request after the page renders.
- Entire collections MUST NOT be fetched merely to filter or paginate them in the browser.

## Frontend tests

- Vitest tests SHOULD primarily cover pure functions with meaningful logic, non-trivial composables and concrete regressions. Prefer extracting behaviour into a function or composable and testing it directly over mounting a component to reach it.
- Component tests are added only for meaningful user-observable behaviour that cannot be covered more directly.
- Static markup, styling details, trivial computed values, prop forwarding, pass-through UI wrappers, framework guarantees and implementation details MUST NOT be tested. Broad component snapshots MUST NOT replace assertions about intentional behaviour.
- Every test MUST protect against a plausible application-code failure. A test that repeats the implementation or verifies Vue, Inertia or browser behaviour MUST NOT be introduced.
- Authorization, validation and payload shape are tested in the backend tests (selected Inertia page and its props); Vitest MUST NOT duplicate those assertions.
- Existing tests are not removed mechanically. Redundant or implementation-coupled tests SHOULD be removed when their area is changed and no meaningful regression protection is lost.
- Vitest shares one module graph across test files (`isolate: false`) for speed. A file that calls `vi.mock()`, `vi.doMock()`, `vi.unmock()` or `vi.doUnmock()` runs isolated, in a separate project of `vitest.config.ts` that detects such files, because a module mock registered on the shared graph either misses modules cached by earlier files or leaks into later ones. A new test needs no configuration; do not remove the split.
- Tests are deterministic: control time, network boundaries and browser globals, and restore mocks after each test ([testing.md](testing.md#writing-tests)).
- Run the narrowest affected test first, then the project's frontend checks (lint, format check, type check, Vitest, build; commands are in [tools.md](tools.md)).

## Design system

- `<js-root>/design-system` is the domain-independent public UI module. Pages, layouts and application components consume it only through the curated entry point `@/design-system`.
- **Public design-system components use the `App` prefix** (`AppButton`, `AppTable`). Private primitives in `design-system/ui` are imported only inside the design system, with the `Ui` prefix (`Button as UiButton`). Application code MUST NOT import a `Ui` primitive.
- An unchanged primitive MAY be exported with an `App` alias (`export { Button as AppButton } from './ui/button'`). A dedicated `App` component is created only when it narrows the API, sets application-wide variants or composes primitives; a component that only forwards everything is not created.
- The design system MUST NOT import pages, application components, models, payload contracts, generated route modules or Inertia, and contains no domain names, rules, routes or copy.
- **shadcn-vue** primitives (built on Reka UI) are added with its CLI into `@/design-system/ui`; `components.json` aliases point there and `cn()` comes from `@/utils/classes`. Private primitives change only for upstream sync, accessibility, security, correctness or a deliberate design-system-wide change.
- A new visual pattern starts in its page branch and is promoted once its domain-independent contract is clear and real reuse justifies it.
- Icons come from the project's icon package (`@lucide/vue`); how they are imported is in [Single-file components](#single-file-components).

### Accessibility ownership

- The design system owns keyboard interaction, focus management, visible focus, ARIA relations and the disabled, loading, invalid, empty, success and failure states of reusable controls. Application code never rebuilds them.
- The consuming page provides labels, descriptions, errors, headings and alternative content.
- Colour is never the only carrier of a state. Buttons are for actions, links for navigation. Decorative icons are hidden from assistive technology; icon-only controls have an accessible name.

## Tailwind CSS

- A colour that carries domain meaning (a status, a category) is a named semantic token with light and dark values, and one application component owns the mapping from the domain value to it. Pages never apply raw palette classes or borrow an unrelated token.
- Utility classes live primarily in design-system components. Outside them, utilities are for route-level layout, responsive placement and genuinely local presentation; repeated class groups are a signal of a missing component, variant or token.
- Consumers never override a design-system component's internals with classes; add a variant, size or slot to the public component, or place it with a surrounding layout element.
- Use `cn()` from `@/utils/classes` for conditional classes. Prefer `gap` over sibling margins.

## Frontend commands

```bash
pnpm eslint        # ESLint flat config, read-only
pnpm format:check  # Prettier (printWidth 150, tabWidth 4), read-only
pnpm tsc           # type check (vue-tsc --noEmit)
pnpm test          # Vitest
pnpm eslint:fix && pnpm format   # explicit autofix, review the diff
```

- The read-only commands are what CI runs; the autofix commands (`eslint:fix`, `format`) are developer actions ([tools.md](tools.md#formatting-and-lint-autofixes)).
