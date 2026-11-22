---
title: Frontend
order: 150
summary: Vite and asset builds, Tailwind CSS tokens, pnpm and the frontend commands that hold for every Laravel user interface.
---
# Project guidelines - Frontend

## Stack

Vite builds the frontend assets, styled with Tailwind CSS, on Node with pnpm. `package.json` and
its lockfile are authoritative for versions ([tools.md](tools.md#versions)).

A Filament admin panel is a separate user interface: the Inertia and Vue rules do not apply to it.
Only the Vite, Tailwind CSS and command rules of this file hold for its theme.

## Vite and bundling

- Vite builds the frontend assets with the Laravel plugin (`@vite` in Blade views, the Laravel
  Vite entry points); `pnpm build` is part of CI.

## Tailwind CSS

- Tailwind CSS 4, configured CSS-first in `resources/css/app.css`; no JavaScript Tailwind
  configuration and no deprecated v3 utilities.
- Colours, type, spacing and radii come from the semantic design tokens declared in `app.css`
  (`@theme`), which mirror the project's design source. Use semantic colours (`bg-background`,
  `text-foreground`, `text-muted-foreground`, `border-border`); no hex values and no arbitrary
  values where a token or a standard utility exists.
- New UI works at the application's responsive breakpoints and, when the project supports dark
  mode, in dark mode.
- Global CSS is limited to tokens, base styles and genuinely global behaviour.

## Frontend commands

- pnpm is the only package manager: the version is pinned in `package.json` `packageManager` (the
  latest release), the `pnpm-lock.yaml` is committed, and `npm` or `yarn` are never run in the
  repository.
- Vite builds the assets: `pnpm dev` serves them during development and `pnpm build` produces the
  production build that CI runs ([deployment.md](deployment.md#pipeline)).
