---
title: Frontend
order: 70
summary: React + Inertia rules - project structure and locality, component architecture, TypeScript, Inertia data flow, forms, performance, design system, Tailwind usage, frontend commands and tests.
---
# Project guidelines - Frontend

The frontend is React (function components in `.tsx`), Inertia, TypeScript, shadcn/ui and Vitest with React Testing Library. Vue is not used next to it. Domain behaviour, validation and authorization stay on the server; the frontend owns presentation state, interaction state and client-side orchestration only. Code that contradicts these rules is a bug to fix, not a precedent to follow.

Paths under `<js-root>/` are written relative to the JavaScript asset root; any other path is a repository path. The framework's frontend section names `<js-root>`, and the `@` import alias points to it.

## Stack

<!-- replace: framework/laravel, package/laravel-inertia -->
Vite builds the frontend assets, styled with Tailwind CSS, on Node with pnpm. `package.json` and its lockfile are authoritative for versions ([tools.md](tools.md#versions)).

A Filament admin panel is a separate user interface: the Inertia and React rules do not apply to it. Only the Vite, Tailwind CSS and command rules of this file hold for its theme.

The pages are Inertia (`@inertiajs/react`) with React and TypeScript, with Laravel Wayfinder for routes, shadcn/ui for the design system's primitives and Vitest for frontend tests, on top of the Vite and Tailwind CSS setup above. `<js-root>` is `resources/js`. The structure, design system, component, TypeScript, Inertia data flow, forms and frontend test rules are the React sections of this file; this layer adds the Laravel server side, Wayfinder and bundling.

## Inertia server side

<!-- replace: package/laravel-inertia -->
- A controller returns `Inertia::render('<branch>/<Page>', [...])`. The component name is the page's path under `resources/js/pages`, with the same casing.
- Page props come from the DTOs queries return ([cqrs.md](cqrs.md#writing-queries)), mapped by a JSON resource or the DTO itself. An Eloquent model is never passed as a prop.
- Props are minimal and serializable and carry only what the acting user may see. Hiding data in the browser is not authorization: sensitive attributes never reach the response. Abilities the page needs (can edit, can delete) are sent as booleans computed by the policy.
- Numbers, amounts and dates are formatted on the server ([domain.md](domain.md#money)); the frontend renders the string it receives.
- Shared data (`HandleInertiaRequests`) holds only what every page needs: the acting user's public fields, abilities, flash messages and locale. It never holds a large or per-page collection.
- Expensive secondary data uses deferred props (`Inertia::defer()`); large datasets are filtered and paginated on the server, never shipped whole.
- Mutations validate in a form request, which maps itself to the command ([Mapping to commands and queries](requests-and-validation.md#mapping-to-commands-and-queries)), and redirect afterwards (`back()` or `to_route()`); validation errors and flash messages reach the page through Inertia's shared errors and flash data, never as a hand-built JSON error. Page props, errors and flash keys are a contract tested by Laravel feature tests with `assertInertia()` ([testing.md](testing.md#controller-testing)).
- An inline script in a Blade view carries `nonce="{{ Vite::cspNonce() }}"`. A third-party script or a request to another origin adds its origin to the Content Security Policy in the same change.
- Page behaviour on the client (forms, navigation, loading states) is in the React sections of this file.

## Project structure and locality

```text
<js-root>/
├── pages/            # Inertia page entry points and their branch-local code
├── design-system/    # domain-independent public UI (App* components)
│   ├── ui/           # private shadcn/ui and vendor-like primitives
│   └── index.ts      # curated public exports
├── components/       # components reused by several page branches
├── layouts/          # persistent and route-level layouts
├── hooks/            # globally reusable React behaviour
├── utils/            # globally reusable pure functions
├── model/            # globally reusable frontend model classes
├── payload/types.ts  # globally shared backend transport contracts
└── types.ts          # globally shared frontend-only types
```

- Code MUST start in the page branch that owns it. Its components, hooks, utils, models, payload contracts and types live directly below that branch.
- The exception is the Inertia entry point (`createInertiaApp` and its setup): it belongs to no page, so it lives directly in `<js-root>`.
- Per-page directories such as `Index/components` MUST NOT be introduced; a branch-level `components` directory is used even with one consumer.
- Code moves to a global location only when it has consumers in **separate page branches**, and only as far outward as needed. Moving code outward "because it might be reused" is prohibited; brief duplication is fine until real consumers show the shared boundary.
- Reuse alone does not make a component part of the design system (see [Design system](#design-system)).
- No `components/shared` and no generic `lib` directory. `utils` holds stateless functions only; stateful or lifecycle-aware behaviour is a hook.
- Global modules MUST NOT import from `pages`. Imports outside the current branch use `@/`.
- Hand-maintained directories use kebab-case. Renaming or moving an Inertia page updates the page name the controller passes to Inertia in the same change.

```text
pages/catalog/products/components/ProductSummary.tsx   one page branch
          │ reused by another branch
          ▼
components/ProductSummary.tsx                          several branches
```

## Component architecture

- Pages compose route-level screens and coordinate Inertia data. Pages and application components compose the project's shared UI components (shadcn/ui based); they do not reimplement generic controls.
- Components are TypeScript function components in `.tsx` files. Class components and `React.FC` are not used.
- Every component has one clear responsibility. Prefer composition, props, callback props and `children` over hidden global state or a context reached for convenience.
- Centralize route, query, filter, parsing and payload builders within their owning feature. Do not duplicate backend-contract mappings across components.
- Props MUST be typed. Props MUST NOT be mutated: derive values during render and ask the parent for a change through a callback prop.
- Derive values during render; `useMemo` and `useCallback` are for a measured cost or a real referential-identity requirement, not a default wrapper. State that can be derived MUST NOT be synchronised in an effect.
- Use `useEffect` only for a real side effect (a subscription, a timer, focus management, a request outside Inertia), and clean up timers, listeners and requests in its cleanup function.
- Lists MUST use a stable domain identifier as their `key`. Array indexes MUST NOT be used when items can be inserted, removed, filtered or reordered.
- Extract before extending a component that approaches 500 lines or mixes UI, business and transport logic.
- Non-trivial transformation and method calls SHOULD be moved out of the JSX into derived values or handlers. Simple conditions and property access MAY remain in the JSX.
- A child component MUST NOT call `usePage()` for data that can be passed explicitly through props.
- Stateful or lifecycle-aware behaviour that more than one component needs is a hook named `useXxx.ts` and follows the rules of hooks. Stateless helpers are plain functions in a module named by its purpose.

### Hooks with module-level state

- A hook that holds state at module level (outside the function, shared by every caller) says so in a comment on that state, and its tests reset it between tests. Otherwise state leaks from one test, and from one page to the next.

### Component files

- Order: package imports; `@/` imports; branch-local imports; named type aliases; the component; inside it hooks and external state, local state, derived values, handlers, effects; then the returned JSX.
- Contracts are named `type` aliases (`Props`), never inline object types in the component signature. Callback props are named `onXxx` and the handlers that implement them `handleXxx`.
- Props MAY be destructured in the parameter list, with defaults there. A prop is not renamed through a destructuring alias to work around a collision; the contract is improved instead.
- `forwardRef` is not used: in React 19 `ref` is an ordinary prop, declared in `Props` when a component exposes one.
- Component files use PascalCase and application components have multi-word names. A component is a named export; a module that a lazy resolver loads by its default export (an Inertia page module, a component an `import.meta.glob` resolver loads by path) default-exports that component, and the function is still named (`export default function ProductIndex()`).
- Inertia components that collide with native elements or design-system components are aliased on import: `Link as InertiaLink`, `Head as InertiaHead`, `Form as InertiaForm`.
- Icons are React components imported by name from the icon package (`lucide-react`) and passed as components. They are never resolved from a string at runtime, and code never imports the whole namespace (`import * as icons from 'lucide-react'`) or the icons map: that keeps every icon in the bundle. Decorative icons are hidden from assistive technology.

## TypeScript

- Keep imports at module top. An inline import needs a documented circular-dependency reason.
- Page props, component contracts, form data and API payloads MUST be modeled explicitly.
- Type-only imports MUST use `import type`.
- A `switch` over a discriminated union or enum ends with an exhaustive `never` check in its `default` branch.
- Nullability and optional states MUST be represented, not hidden with assertions.
- `any`, unsafe casts and non-null assertions SHOULD NOT be used. Unknown external values MUST be narrowed or validated at their boundary.
- Server-owned enums, lookup values and constants MUST NOT be duplicated ad hoc. The backend passes their values through an explicit props contract.
- Lint or type errors MUST NOT be suppressed without a real boundary. Any exception is documented and narrowed to the smallest possible scope; the rules of hooks are never disabled.

## Inertia data flow

- Server props are the source of truth for server state. Props MUST NOT be copied into local state unless the user is editing a local draft.
- Internal navigation MUST use `InertiaLink` or `router` for programmatic visits. Do not use `window.location` or a plain internal `<a>`.
- Processing, validation, empty, success and failure states MUST be visible to the user, and duplicate submission MUST be prevented while a request is processing.
- Deferred props MUST handle their initial `undefined` state and render a purposeful fallback.
- Partial reloads, polling, prefetching, optimistic updates and infinite scroll SHOULD be used only when they improve a concrete interaction and their loading, stale-data and rollback behaviour is handled.
- Scroll and component state are preserved only intentionally. Blanket preservation options MUST NOT hide stale server data.

## Forms

- Ordinary forms use Inertia's form component; use `useForm` when the form needs programmatic control, and define a typed initial shape.
- Every control MUST have an accessible label and a stable `name`; a visible `<label>` is tied to its control with `htmlFor` and `id`.
- Server validation errors MUST be connected to the relevant control (`aria-describedby`), and its invalid state MUST be perceivable (`aria-invalid` as well as visually).
- A controlled input always receives a defined value and never switches between controlled and uncontrolled.
- Submit actions MUST be disabled or otherwise guarded while processing.
- Entered values SHOULD be preserved after a validation error unless the product flow requires a reset.
- Client validation MAY improve usability but MUST NOT replace server-side validation or authorization.
- Domain value normalization happens on the server. The browser performs only UI-specific conversion, such as turning an input string into its typed form value.

## Performance

- Avoid repeated expensive work during render, needless re-renders of large subtrees and unbounded state. Memoize a value or component only when a measurement shows it pays off.
- Remote search SHOULD be debounced, and stale requests MUST be cancelled or ignored.
- Heavy components that are not needed for the first render (a map, an editor, a chart, a non-default tab, a dialog or an edit-only form of a heavy feature) SHOULD load lazily through `React.lazy` with a `Suspense` fallback, or a dynamic `import()`. Content visible on first render MUST NOT be lazy: it only adds a request after the page renders.
- Entire collections MUST NOT be fetched merely to filter or paginate them in the browser.

## Frontend tests

- Vitest tests SHOULD primarily cover pure functions with meaningful logic, non-trivial hooks (through React Testing Library's `renderHook`) and concrete regressions. Prefer extracting behaviour into a function or hook and testing it directly over rendering a component to reach it.
- Component tests are added only for meaningful user-observable behaviour that cannot be covered more directly. They use React Testing Library and query the output the way a person perceives it (role, label, text), never class names, component internals or a `data-testid` added only for the assertion.
- Static markup, styling details, trivial derived values, prop forwarding, pass-through UI wrappers, framework guarantees and implementation details MUST NOT be tested. Broad component snapshots MUST NOT replace assertions about intentional behaviour.
- Every test MUST protect against a plausible application-code failure. A test that repeats the implementation or verifies React, Inertia or browser behaviour MUST NOT be introduced.
- Authorization, validation and payload shape are tested in the backend tests (selected Inertia page and its props); Vitest MUST NOT duplicate those assertions.
- Existing tests are not removed mechanically. Redundant or implementation-coupled tests SHOULD be removed when their area is changed and no meaningful regression protection is lost.
- Tests are deterministic: control time, network boundaries and browser globals, and restore mocks after each test ([testing.md](testing.md#writing-tests)).
- Run the narrowest affected test first, then the project's frontend checks (lint, format check, type check, Vitest, build; commands are in [tools.md](tools.md)).

## Design system

- `<js-root>/design-system` is the domain-independent public UI module. Pages, layouts and application components consume it only through the curated entry point `@/design-system`.
- **Public design-system components use the `App` prefix** (`AppButton`, `AppTable`). Private primitives in `design-system/ui` are imported only inside the design system, with the `Ui` prefix (`Button as UiButton`). Application code MUST NOT import a `Ui` primitive.
- An unchanged primitive MAY be exported with an `App` alias (`export { Button as AppButton } from './ui/button'`). A dedicated `App` component is created only when it narrows the API, sets application-wide variants or composes primitives; a component that only forwards everything is not created.
- The design system MUST NOT import pages, application components, models, payload contracts, generated route modules or Inertia, and contains no domain names, rules, routes or copy.
- **shadcn/ui** primitives are added with its CLI into `@/design-system/ui`; `components.json` aliases point there and `cn()` comes from `@/utils/classes`. Private primitives change only for upstream sync, accessibility, security, correctness or a deliberate design-system-wide change.
- A new visual pattern starts in its page branch and is promoted once its domain-independent contract is clear and real reuse justifies it.
- Icons come from the project's icon package (`lucide-react`); how they are imported is in [Component files](#component-files).

### Accessibility ownership

- The design system owns keyboard interaction, focus management, visible focus, ARIA relations and the disabled, loading, invalid, empty, success and failure states of reusable controls. Application code never rebuilds them.
- The consuming page provides labels, descriptions, errors, headings and alternative content.
- Colour is never the only carrier of a state. Buttons are for actions, links for navigation. Decorative icons are hidden from assistive technology; icon-only controls have an accessible name.

### Utility classes and tokens

- A colour that carries domain meaning (a status, a category) is a named semantic token with light and dark values, and one application component owns the mapping from the domain value to it. Pages never apply raw palette classes or borrow an unrelated token.
- Utility classes live primarily in design-system components. Outside them, utilities are for route-level layout, responsive placement and genuinely local presentation; repeated class groups are a signal of a missing component, variant or token.
- Consumers never override a design-system component's internals with classes; add a variant, size or slot to the public component, or place it with a surrounding layout element.
- Use `cn()` from `@/utils/classes` for conditional classes. Prefer `gap` over sibling margins.

## Frontend commands

<!-- replace: package/laravel-inertia -->
```bash
pnpm eslint        # ESLint flat config with the React Hooks rules, read-only
pnpm format:check  # Prettier (printWidth 150, tabWidth 4), read-only
pnpm tsc           # type check (tsc --noEmit)
pnpm test          # Vitest
pnpm eslint:fix && pnpm format   # explicit autofix, review the diff
```

- CI runs the read-only commands that the stack's pipeline lists ([deployment.md](deployment.md)); the autofix commands (`eslint:fix`, `format`) are developer actions ([tools.md](tools.md#formatting-and-lint-autofixes)).

- `pnpm dev` and `pnpm build` generate the Wayfinder modules. CI runs the read-only commands of the React sections ([deployment.md](deployment.md#pipeline)).
