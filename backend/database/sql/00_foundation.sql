-- =============================================================================
-- 00_foundation.sql
-- Extensions, shared trigger functions, and the status state-machine registry.
-- Every later file depends on this one.
--
-- Target: PostgreSQL 15+.
-- =============================================================================

CREATE EXTENSION IF NOT EXISTS citext;   -- case-insensitive email and username

-- -----------------------------------------------------------------------------
-- gen_uuid_v7(): RFC 9562 version-7 UUIDs.
-- Laravel HasUuids also generates v7, so ids made in PHP and ids made by this
-- DEFAULT sort the same way.
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION gen_uuid_v7() RETURNS uuid
LANGUAGE plpgsql VOLATILE PARALLEL SAFE AS $$
DECLARE
  v_ms  bigint := floor(extract(epoch FROM clock_timestamp()) * 1000)::bigint;
  v_raw bytea  := uuid_send(gen_random_uuid());
BEGIN
  v_raw := overlay(v_raw PLACING substring(int8send(v_ms) FROM 3) FROM 1 FOR 6);
  v_raw := set_bit(set_bit(v_raw, 52, 1), 53, 1);
  RETURN encode(v_raw, 'hex')::uuid;
END $$;

COMMENT ON FUNCTION gen_uuid_v7() IS 'Time-ordered UUID v7 used as the default primary key everywhere.';

CREATE OR REPLACE FUNCTION set_updated_at() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.updated_at IS NOT DISTINCT FROM OLD.updated_at THEN
    NEW.updated_at := now();
  END IF;
  RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION forbid_mutation() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  RAISE EXCEPTION '% is append-only: % is not allowed', TG_TABLE_NAME, TG_OP
    USING ERRCODE = 'restrict_violation';
END $$;

-- -----------------------------------------------------------------------------
-- Status state machines. Inserts are not checked; only changes of an existing
-- row's status are. To change a lifecycle, add or remove rows here in a new
-- migration.
-- -----------------------------------------------------------------------------
CREATE TABLE status_transitions (
  table_name  text NOT NULL,
  from_status text NOT NULL,
  to_status   text NOT NULL,
  PRIMARY KEY (table_name, from_status, to_status),
  CHECK (from_status <> to_status)
);

COMMENT ON TABLE status_transitions IS
  'Registry of legal status changes per table, enforced by enforce_status_transition().';

CREATE OR REPLACE FUNCTION enforce_status_transition() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.status IS DISTINCT FROM OLD.status AND NOT EXISTS (
       SELECT 1 FROM status_transitions t
        WHERE t.table_name = TG_TABLE_NAME
          AND t.from_status = OLD.status
          AND t.to_status   = NEW.status) THEN
    RAISE EXCEPTION 'Illegal % status transition: % -> %', TG_TABLE_NAME, OLD.status, NEW.status
      USING ERRCODE = 'check_violation';
  END IF;
  RETURN NEW;
END $$;
