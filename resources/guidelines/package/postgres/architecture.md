---
title: Architecture
---
# Project guidelines - Architecture

## PostgreSQL

- Table, column, index and foreign key names MUST stay within 63 bytes (`NAMEDATALEN - 1`). PostgreSQL truncates a longer name without an error, so two long names can collide or a name can differ from the one the code uses. Give a long generated name an explicit short one ([Persistence](#persistence)).
- Laravel's `enum()` creates a `varchar` with a `CHECK` constraint on PostgreSQL, which is accepted as the implementation, so "database-native" is not literally true here. The PHP backed enum cast is the type; the constraint keeps the stored values in range.
