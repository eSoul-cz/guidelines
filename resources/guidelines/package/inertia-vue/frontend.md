---
title: Frontend
order: 70
summary: Vue 3 + Inertia rules - component architecture, TypeScript, Inertia data flow, forms, performance and frontend tests.
---
# Project guidelines - Frontend

The frontend is Vue 3 (Composition API, `<script setup lang="ts">`), Inertia 3, TypeScript,
shadcn-vue and Vitest. React is not supported. `package.json` and its lockfile are authoritative
for installed versions. Domain behaviour, validation and authorization stay on the server; the
frontend owns presentation state, interaction state and client-side orchestration only. Code that
contradicts these rules is a bug to fix, not a precedent to follow.

## Component architecture

- Pages compose route-level screens and coordinate Inertia data. Pages and application components
  compose the project's shared UI components (shadcn-vue based); they do not reimplement generic
  controls.
- Every component has one clear responsibility. Prefer composition, props, emits and slots over
  hidden global state or tightly coupled parent access.
- Centralize route, query, filter, parsing and payload builders within their owning feature. Do
  not duplicate backend-contract mappings across components.
- Props and emits MUST be typed. Props MUST NOT be mutated: derive values with `computed` and emit
  requested changes upward.
- Use `computed` for derived state and explicit event handlers for reactions. Use `watch` only for
  a real side effect, and clean up timers, listeners and requests.
- `v-for` MUST use a stable domain identifier as its key. Array indexes MUST NOT be used when
  items can be inserted, removed, filtered or reordered.
- Extract before extending a component that approaches 500 lines or mixes UI, business and
  transport logic.
- Non-trivial transformation and method calls SHOULD be moved out of the template into computed
  values or handlers. Simple conditions and property access MAY remain in the template.
- A child component MUST NOT call `usePage()` for data that can be passed explicitly through props.
- Reactive or lifecycle-aware behaviour that more than one component needs is a composable named
  `useXxx.ts`. Stateless helpers are plain functions in a module named by its purpose.

### Single-file components

- Order: `<script setup lang="ts">`, then `<template>`, then optional `<style scoped>`.
- Script order: package imports; `@/` imports; branch-local imports; named type aliases; contract
  macros (`defineModel`, `defineProps`, `defineEmits`, `defineSlots`, `defineExpose`);
  composables; local state; computed values; handlers; watchers and lifecycle hooks.
- Contracts are named `type` aliases (`Props`, `Emits`, `Slots`), never inline object types in a
  macro. `Emits` uses call signatures: `(e: 'select-item', item: Item): void`.
- `defineProps()` is never destructured, and templates access props by name, never via `props.` or
  `$props`.
- Component files use PascalCase and application components have multi-word names. Template tags,
  attributes and custom events use kebab-case (`<app-button>`, `@select-item`).
- Inertia components that collide with native elements are aliased on import: `Link as InertiaLink`,
  `Head as InertiaHead`, `Form as InertiaForm`.

## TypeScript

- Keep imports at module top. An inline import needs a documented circular-dependency reason.
- Page props, component contracts, form data and API payloads MUST be modeled explicitly.
- Type-only imports MUST use `import type`.
- A `switch` over a discriminated union or enum ends with an exhaustive `never` check in its
  `default` branch.
- Nullability and optional states MUST be represented, not hidden with assertions.
- `any`, unsafe casts and non-null assertions SHOULD NOT be used. Unknown external values MUST be
  narrowed or validated at their boundary.
- Server-owned enums, lookup values and constants MUST NOT be duplicated ad hoc. The backend passes
  their values through an explicit props contract.
- Lint or type errors MUST NOT be suppressed without a real boundary. Any exception is documented
  and narrowed to the smallest possible scope.

## Inertia data flow

- Server props are the source of truth for server state. Props MUST NOT be copied into local state
  unless the user is editing a local draft.
- Internal navigation MUST use `InertiaLink` or `router` for programmatic visits. Do not use
  `window.location` or a plain internal `<a>`.
- Processing, validation, empty, success and failure states MUST be visible to the user, and
  duplicate submission MUST be prevented while a request is processing.
- Deferred props MUST handle their initial `undefined` state and render a purposeful fallback.
- Partial reloads, polling, prefetching, optimistic updates and infinite scroll SHOULD be used only
  when they improve a concrete interaction and their loading, stale-data and rollback behaviour is
  handled.
- Scroll and component state are preserved only intentionally. Blanket preservation options MUST
  NOT hide stale server data.
- Props MUST be minimal, serializable and free of sensitive attributes. Send only the data the
  page needs and only what the acting user may see; filtering in the browser is not authorization
  ([security-and-data.md](security-and-data.md#authorization)).
- Numbers, amounts and dates arrive formatted from the server; the frontend never recomputes them
  ([domain.md](domain.md#money)).

## Forms

- Ordinary forms use Inertia's form component; use `useForm` when the form needs programmatic
  control, and define a typed initial shape.
- Every control MUST have an accessible label and a stable `name`.
- Server validation errors MUST be connected to the relevant control, and its invalid state MUST
  be perceivable.
- Submit actions MUST be disabled or otherwise guarded while processing.
- Entered values SHOULD be preserved after a validation error unless the product flow requires a
  reset.
- Client validation MAY improve usability but MUST NOT replace server-side validation or
  authorization.
- Domain value normalization happens on the server. The browser performs only UI-specific
  conversion, such as turning an input string into its typed form value.

## Performance

- Avoid deep watchers, repeated expensive template work and unbounded reactive state. Memoize
  derived values with `computed` where it pays off.
- Remote search SHOULD be debounced, and stale requests MUST be cancelled or ignored.
- Large datasets MUST use server-side filtering and pagination, and expensive secondary data
  SHOULD use Inertia deferred props.
- Entire collections MUST NOT be fetched merely to filter or paginate them in the browser.

## Frontend tests

- Vitest tests SHOULD primarily cover pure functions with meaningful logic, non-trivial
  composables and concrete regressions. Prefer extracting behaviour into a function or composable
  and testing it directly over mounting a component to reach it.
- Component tests are added only for meaningful user-observable behaviour that cannot be covered
  more directly.
- Static markup, styling details, trivial computed values, prop forwarding, pass-through UI
  wrappers, framework guarantees and implementation details MUST NOT be tested. Broad component
  snapshots MUST NOT replace assertions about intentional behaviour.
- Every test MUST protect against a plausible application-code failure. A test that repeats the
  implementation or verifies Vue, Inertia or browser behaviour MUST NOT be introduced.
- Authorization, validation and payload shape are tested in the backend tests (selected Inertia
  page and its props); Vitest MUST NOT duplicate those assertions.
- Existing tests are not removed mechanically. Redundant or implementation-coupled tests SHOULD be
  removed when their area is changed and no meaningful regression protection is lost.
- Tests are deterministic: control time, network boundaries and browser globals, and restore mocks
  after each test ([testing.md](testing.md#writing-tests)).
- Run the narrowest affected test first, then the project's frontend checks (lint, format check,
  type check, Vitest, build; commands are in [tools.md](tools.md)).
