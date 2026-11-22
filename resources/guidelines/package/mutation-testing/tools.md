---
title: Tools
---
# Project guidelines - Tools

## Versions

Mutation testing adds this row:

| Area | Tool | Source |
|---|---|---|
| Mutation testing | Infection (local, optional) | `infection.json5` |

## Tool caches

On LSR, Infection's cache is `temp/tools/infection`, next to the other tool caches.

## Mutation testing (local, optional)

```bash
composer mutation
```

`composer mutation` runs Infection on your machine before you push. It is optional, never a CI gate and sets no score threshold. How to read the output and handle escaped mutants is described in the mutation testing section of [testing.md](testing.md#mutation-testing).
