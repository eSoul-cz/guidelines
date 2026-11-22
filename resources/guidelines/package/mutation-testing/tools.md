---
title: Tools
---
# Project guidelines - Tools

## Mutation testing (local, optional)

```bash
composer mutation
```

`composer mutation` runs Infection on your machine before you push. It is optional, never a CI gate and sets no score threshold. How to read the output and handle escaped mutants is described in the mutation testing section of [testing.md](testing.md#mutation-testing).
