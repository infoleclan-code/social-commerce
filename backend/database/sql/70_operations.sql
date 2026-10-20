-- =============================================================================
-- 70_operations.sql
-- anonymize_user(): GDPR erasure. Identity is scrubbed in place. Posts are
-- deleted (likes and comments on them cascade). Comments the user left on
-- other people's reels are redacted so replies still have a parent.
-- =============================================================================

CREATE OR REPLACE FUNCTION anonymize_user(p_user_id uuid) RETURNS void
LANGUAGE plpgsql AS $$
DECLARE
  v_tag text := replace(p_user_id::text, '-', '');
BEGIN
  UPDATE users SET
    email               = 'erased+' || v_tag || '@erased.invalid',
    username            = 'erased_' || right(v_tag, 23),
    display_name        = 'Deleted user',
    password            = NULL,
    remember_token      = NULL,
    email_verified_at   = NULL,
    avatar_path         = NULL,
    bio                 = NULL,
    settings            = '{}'::jsonb,
    social_feed_enabled = false,
    profile_visibility  = 'private',
    invite_code         = 'X' || right(v_tag, 20),
    last_active_at      = NULL,
    deleted_at          = COALESCE(deleted_at, now()),
    anonymized_at       = now()
  WHERE id = p_user_id;

  IF NOT FOUND THEN
    RAISE EXCEPTION 'User % does not exist', p_user_id USING ERRCODE = 'no_data_found';
  END IF;

  DELETE FROM user_devices       WHERE user_id = p_user_id;
  DELETE FROM feed_posts         WHERE user_id = p_user_id;   -- cascades that post's likes and comments
  DELETE FROM feed_post_likes    WHERE user_id = p_user_id;
  UPDATE feed_post_comments SET body = '[deleted]', deleted_at = COALESCE(deleted_at, now())
   WHERE user_id = p_user_id;
  DELETE FROM social_connections WHERE p_user_id IN (requester_id, addressee_id);
END $$;

COMMENT ON FUNCTION anonymize_user(uuid) IS 'GDPR erasure. Scrubs the account and removes their social content. Call after the grace period.';
