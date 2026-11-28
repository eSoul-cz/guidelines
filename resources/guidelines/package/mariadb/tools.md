---
title: Tools
---
# Project guidelines - Tools

## Known pitfalls

- On MariaDB and MySQL a `timestamp()` column without `nullable()` or a default fails under strict mode (error 1067), and `ONLY_FULL_GROUP_BY` rejects a `SELECT` expression that differs from the `GROUP BY` one. Both pass on SQLite.
