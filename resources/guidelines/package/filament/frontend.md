---
title: Frontend
---
# Project guidelines - Frontend

## Tailwind CSS

- A Filament panel is themed with a Filament theme: a CSS file built by Vite and registered on the panel (`viteTheme()`), generated with `php artisan make:filament-theme`. The theme and the application's own Blade views follow the Tailwind token rules of this file (tokens in `app.css`, semantic colours, no arbitrary values); the design-system and Vue rules do not apply to them.
- Colours come from the panel's colour configuration and the semantic design tokens, not from hex values in the theme; a utility class does not override a Filament component's internals where a panel option, a colour or a theme token exists.
