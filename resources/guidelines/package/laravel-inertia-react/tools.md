---
title: Tools
---
# Project guidelines - Tools

## Versions

<!-- replace: package/laravel-inertia -->
An Inertia project adds these rows:

| Area | Tool | Version / source |
|---|---|---|
| User interface | Inertia 3 (`@inertiajs/react`), React 19, TypeScript, Tailwind CSS 4, shadcn/ui, Laravel Wayfinder | see [frontend.md](frontend.md) |
| Frontend quality | ESLint (flat config), Prettier (`printWidth` 150, `tabWidth` 4), TypeScript, Vitest | `eslint.config.js`, `.prettierrc`, `tsconfig*.json`, `vitest.config.ts` |

- React projects add React Testing Library (`@testing-library/react`) to Vitest and the React Hooks rules (`eslint-plugin-react-hooks`) to ESLint; `lucide-react` is the icon package.
