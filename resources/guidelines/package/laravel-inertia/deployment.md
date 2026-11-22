---
title: Deployment and runtime
---
# Project guidelines - Deployment and runtime

## Pipeline

An Inertia project adds these details to the pipeline stages:

- **Prepare** also runs `php artisan wayfinder:generate`, so the generated route modules exist before the JavaScript checks.
- **JS static checks** install the packages and run the read-only commands of [tools.md](tools.md#read-only-verification): ESLint, Prettier and TypeScript.
- **JS tests** run Vitest and the production build (`pnpm build`).
