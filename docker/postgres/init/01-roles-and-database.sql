-- Local-development bootstrap for the two Postgres roles named in
-- docs/project-memory/09-decision-log.md D-0009/D-0020 and
-- docs/project-memory/08-deployment-and-operations.md.
--
-- Passwords here are fixed, local-only values matching .env.example —
-- fine for a disposable local container, never reused anywhere real.
-- Runs once, the first time the postgres data volume is initialized.

-- bookslot_migrator: owns the tables, holds BYPASSRLS. Used only by
-- migrations/seeders/backfills/pg_dump — never by the running application.
CREATE ROLE bookslot_migrator WITH LOGIN PASSWORD 'bookslot_migrator_local_only' CREATEDB BYPASSRLS;

-- bookslot_app: the only role the running application ever authenticates
-- as. Ordinary role, fully RLS-subject, never granted BYPASSRLS.
CREATE ROLE bookslot_app WITH LOGIN PASSWORD 'bookslot_app_local_only';

CREATE DATABASE bookslot OWNER bookslot_migrator;

-- A separate database for the automated test suite (07-testing-strategy.md),
-- so `composer ci:check` never reads or writes local development data.
CREATE DATABASE bookslot_test OWNER bookslot_migrator;

\connect bookslot

GRANT CONNECT ON DATABASE bookslot TO bookslot_app;
GRANT USAGE ON SCHEMA public TO bookslot_app;

-- Tables/sequences created later by bookslot_migrator's own migrations
-- automatically become usable by bookslot_app without a per-migration
-- GRANT statement.
ALTER DEFAULT PRIVILEGES FOR ROLE bookslot_migrator IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO bookslot_app;
ALTER DEFAULT PRIVILEGES FOR ROLE bookslot_migrator IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO bookslot_app;

\connect bookslot_test

GRANT CONNECT ON DATABASE bookslot_test TO bookslot_app;
GRANT USAGE ON SCHEMA public TO bookslot_app;

ALTER DEFAULT PRIVILEGES FOR ROLE bookslot_migrator IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO bookslot_app;
ALTER DEFAULT PRIVILEGES FOR ROLE bookslot_migrator IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO bookslot_app;
