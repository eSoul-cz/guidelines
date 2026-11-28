---
title: Architecture
---
# Project guidelines - Architecture

## MariaDB and MySQL

- Table, column, index and foreign key names MUST stay within 64 characters; the engine rejects longer ones. Give a long generated name an explicit short one ([Persistence](#persistence)).
- A column with more than two values SHOULD be a native `ENUM`, mapped to a PHP backed enum through a cast.
