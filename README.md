# Migrations

Migration utility for PostgreSQL using combination of Symfony command with plain SQL to gain better readability. 

## Installation

- `composer require --dev pmgdev/migrations`
- Import whole `structure.sql` to your database

## Flow

- Create new branch `git checkout -b feature/PROJECT-1689-count-number-of-episodes`
- Create new migration script `vendor/bin/pmgdev migrations:create --config="/path/to/config.neon"`

The script may look like:
```sql
-- ###### DEPLOY SCRIPT 2019-07-09-PROJECT-1689-count-number-of-episodes.sql ######
SELECT system.deploy_script('2019-07-09-PROJECT-1689-count-number-of-episodes.sql');

ALTER TABLE films ADD COLUMN new_column integer;

-- Write whatever SQL you need for migration.

INSERT INTO films (id, new_column) VALUES (1, 100);

-- ###### DEPLOY SCRIPT 2019-07-09-PROJECT-1689-count-number-of-episodes.sql ######
```

- Write changes to `structure.sql` as well to match migration script after deploy:

```sql
-- This is the table before changes
CREATE TABLE films (id serial PRIMARY KEY);

-- Along with migration script, we must edit structure.sql - this is how it's going to look like after deploying migration:

CREATE TABLE films (id serial PRIMARY KEY, new_column integer); -- note the added column

-- note that data are ignored - structure.sql contains only DDL

```

- To verify you did not make any mistake run `vendor/bin/pmgdev migrations:compare-structure --config="/path/to/config.neon"`
- If everything is ok, you can merge your changes to master and let others to import these changes as well. They can simply run `vendor/bin/pmgdev migrations:list --config="/path/to/config.neon"` which outputs all migrations missing in their database.

## Configuration
See [config.sample.neon](config.sample.neon) for details
>**IMPORTANT!** Don't use connection to pgbouncer, because it persists error connection, when script fails.

### FAQ
1. Why `structure.sql`?
   - There can be a lot of changes in migration scripts - for example to edit function, you have to do `CREATE OR REPLACE ...` with the full body. It may become less readable for reviewer of task. To see relevant diff, just open `structure.sql`.
2. Why not fully automated?
   - Some parts of SQL must be repeated multiple times (in batches) to not block other reading/writing. 
3. What's for `system.show_deploy_warning`
    - When you need to execute query multiple times, or proceed some manual changes to finish migration correctly, it's a good habit to stop execution right before the affected part.
4. Is it possible to make dependencies on other migrations?
    - Just pass 2nd argument of `system.deploy_script`
