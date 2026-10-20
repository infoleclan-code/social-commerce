-- =============================================================================
-- 10_identity.sql
-- users, user_devices, user_consents, social_connections (follows and blocks)
--
-- Privacy: social features are off until the user turns them on
-- (social_feed_enabled). Users are never hard-deleted: erasure is
-- anonymize_user() in 70_operations.sql.
-- =============================================================================

CREATE TABLE users (
  id                         uuid PRIMARY KEY DEFAULT gen_uuid_v7(),

  email                      citext NOT NULL UNIQUE,
  email_verified_at          timestamptz,
  password                   text,
  remember_token             varchar(100),

  username                   citext NOT NULL UNIQUE
                             CHECK (username ~ '^[A-Za-z0-9_.]{3,30}$'),
  display_name               text   NOT NULL CHECK (char_length(display_name) BETWEEN 1 AND 60),
  avatar_path                text,
  bio                        text CHECK (char_length(bio) <= 500),

  locale                     text    NOT NULL DEFAULT 'en' CHECK (locale ~ '^[a-z]{2}(-[A-Z]{2})?$'),
  country_code               char(2) CHECK (country_code ~ '^[A-Z]{2}$'),
  timezone                   text    NOT NULL DEFAULT 'UTC',

  social_feed_enabled        boolean NOT NULL DEFAULT false,
  profile_visibility         text    NOT NULL DEFAULT 'private'
                             CHECK (profile_visibility IN ('private', 'friends', 'public')),

  -- Engagement reputation (likes and comments received). Recomputed by a job, never by clients.
  social_score               integer NOT NULL DEFAULT 0 CHECK (social_score >= 0),
  settings                   jsonb   NOT NULL DEFAULT '{}'::jsonb,

  invite_code                citext  NOT NULL UNIQUE
                             DEFAULT upper(substr(replace(gen_random_uuid()::text, '-', ''), 1, 10)),
  referred_by_user_id        uuid    REFERENCES users (id) ON DELETE SET NULL,

  risk_status                text NOT NULL DEFAULT 'ok'
                             CHECK (risk_status IN ('ok', 'review', 'restricted', 'banned')),

  last_active_at             timestamptz,
  created_at                 timestamptz NOT NULL DEFAULT now(),
  updated_at                 timestamptz NOT NULL DEFAULT now(),
  deleted_at                 timestamptz,
  anonymized_at              timestamptz,

  CONSTRAINT users_not_self_referred CHECK (referred_by_user_id IS DISTINCT FROM id),
  CONSTRAINT users_settings_object CHECK (jsonb_typeof(settings) = 'object'),
  CONSTRAINT users_anonymized_is_deleted CHECK (anonymized_at IS NULL OR deleted_at IS NOT NULL)
);

COMMENT ON TABLE users IS 'Accounts. Never hard-delete; call anonymize_user().';
COMMENT ON COLUMN users.social_feed_enabled IS
  'Master opt-in for posting and commenting. While false, feed_posts and feed_post_comments inserts are rejected.';
COMMENT ON COLUMN users.settings IS
  'Client preferences. Shape: {"feed":{"autoplay":"always|wifi|never","muted":true},"haptics":true}.';
COMMENT ON COLUMN users.referred_by_user_id IS 'Who invited this user into the app.';

CREATE INDEX users_referred_by_idx ON users (referred_by_user_id) WHERE referred_by_user_id IS NOT NULL;
CREATE INDEX users_risk_review_idx ON users (risk_status) WHERE risk_status <> 'ok';
CREATE INDEX users_social_score_idx ON users (social_score DESC) WHERE deleted_at IS NULL;
CREATE TRIGGER users_set_updated_at BEFORE UPDATE ON users
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- -----------------------------------------------------------------------------
-- user_devices: one row per installed app.
-- -----------------------------------------------------------------------------
CREATE TABLE user_devices (
  id                       uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
  user_id                  uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
  platform                 text NOT NULL CHECK (platform IN ('ios', 'android', 'web')),
  expo_push_token          text UNIQUE,
  push_enabled             boolean NOT NULL DEFAULT true,
  app_version              text,
  device_fingerprint_hash  char(64) CHECK (device_fingerprint_hash ~ '^[0-9a-f]{64}$'),
  last_seen_at             timestamptz,
  revoked_at               timestamptz,
  created_at               timestamptz NOT NULL DEFAULT now(),
  updated_at               timestamptz NOT NULL DEFAULT now()
);

COMMENT ON TABLE user_devices IS 'Push targets per device. Send pushes only where revoked_at IS NULL AND push_enabled.';

CREATE INDEX user_devices_user_active_idx ON user_devices (user_id) WHERE revoked_at IS NULL;
CREATE INDEX user_devices_fingerprint_idx ON user_devices (device_fingerprint_hash) WHERE device_fingerprint_hash IS NOT NULL;
CREATE TRIGGER user_devices_set_updated_at BEFORE UPDATE ON user_devices
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- -----------------------------------------------------------------------------
-- user_consents: append-only. Current state per purpose = newest row.
-- -----------------------------------------------------------------------------
CREATE TABLE user_consents (
  id              uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
  user_id         uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
  purpose         text NOT NULL CHECK (purpose IN (
                    'marketing_push', 'marketing_email', 'personalization',
                    'analytics', 'contacts_matching')),
  granted         boolean NOT NULL,
  policy_version  text NOT NULL,
  source          text NOT NULL CHECK (source IN ('onboarding', 'settings', 'prompt', 'api')),
  created_at      timestamptz NOT NULL DEFAULT now()
);

COMMENT ON TABLE user_consents IS 'Append-only. Withdrawal = a new row with granted=false.';

CREATE INDEX user_consents_current_idx ON user_consents (user_id, purpose, created_at DESC);
CREATE TRIGGER user_consents_append_only BEFORE UPDATE ON user_consents
  FOR EACH ROW EXECUTE FUNCTION forbid_mutation();

-- -----------------------------------------------------------------------------
-- social_connections: follow requests and blocks. One row per unordered pair.
-- -----------------------------------------------------------------------------
CREATE TABLE social_connections (
  id                  uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
  requester_id        uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
  addressee_id        uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
  status              text NOT NULL DEFAULT 'pending'
                      CHECK (status IN ('pending', 'accepted', 'declined', 'blocked')),
  blocked_by_user_id  uuid REFERENCES users (id) ON DELETE CASCADE,
  source              text NOT NULL DEFAULT 'search'
                      CHECK (source IN ('invite_code', 'contacts', 'search', 'profile')),
  invite_code_used    citext,
  responded_at        timestamptz,
  created_at          timestamptz NOT NULL DEFAULT now(),
  updated_at          timestamptz NOT NULL DEFAULT now(),

  CONSTRAINT social_connections_not_self CHECK (requester_id <> addressee_id),
  CONSTRAINT social_connections_block_owner CHECK (
    (status = 'blocked') = (blocked_by_user_id IS NOT NULL)
    AND (blocked_by_user_id IS NULL OR blocked_by_user_id IN (requester_id, addressee_id))
  )
);

COMMENT ON TABLE social_connections IS 'Follow graph. Friends of X = accepted rows where X is requester or addressee. Unblock = delete the row.';

CREATE UNIQUE INDEX social_connections_pair_idx
  ON social_connections (LEAST(requester_id, addressee_id), GREATEST(requester_id, addressee_id));
CREATE INDEX social_connections_requester_idx ON social_connections (requester_id, status);
CREATE INDEX social_connections_addressee_idx ON social_connections (addressee_id, status);
CREATE TRIGGER social_connections_set_updated_at BEFORE UPDATE ON social_connections
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER social_connections_status_transition BEFORE UPDATE OF status ON social_connections
  FOR EACH ROW EXECUTE FUNCTION enforce_status_transition();

INSERT INTO status_transitions (table_name, from_status, to_status) VALUES
  ('social_connections', 'pending',  'accepted'),
  ('social_connections', 'pending',  'declined'),
  ('social_connections', 'pending',  'blocked'),
  ('social_connections', 'accepted', 'blocked'),
  ('social_connections', 'declined', 'pending'),
  ('social_connections', 'declined', 'blocked');
