-- =============================================================================
-- invariants_test.sql — proof of the social schema.
--
--   psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f database/sql/tests/invariants_test.sql
-- Everything runs in one transaction and is rolled back.
-- =============================================================================
\set ON_ERROR_STOP 1
SET client_min_messages = notice;
BEGIN;

CREATE FUNCTION pg_temp.expect_error(p_sql text, p_sqlstate text, p_label text) RETURNS void
LANGUAGE plpgsql AS $$
BEGIN
  BEGIN
    EXECUTE p_sql;
  EXCEPTION WHEN OTHERS THEN
    IF SQLSTATE = p_sqlstate THEN
      RAISE NOTICE 'PASS  %  [%]', p_label, left(SQLERRM, 120);
      RETURN;
    END IF;
    RAISE EXCEPTION 'FAIL  % : expected SQLSTATE % but got % (%)', p_label, p_sqlstate, SQLSTATE, SQLERRM;
  END;
  RAISE EXCEPTION 'FAIL  % : expected SQLSTATE % but the statement succeeded', p_label, p_sqlstate;
END $$;

CREATE FUNCTION pg_temp.pass(p_label text) RETURNS void LANGUAGE plpgsql AS $$
BEGIN RAISE NOTICE 'PASS  %', p_label; END $$;

INSERT INTO users (id, email, username, display_name, social_feed_enabled) VALUES
  ('00000000-0000-7000-8000-0000000000a1', 'author@example.com', 'author', 'Author', true),
  ('00000000-0000-7000-8000-0000000000a2', 'fan@example.com', 'fan', 'Fan', true),
  ('00000000-0000-7000-8000-0000000000a3', 'quiet@example.com', 'quiet', 'Quiet', false);

INSERT INTO feed_posts (id, user_id, audience, media_type, media_path) VALUES
  ('00000000-0000-7000-8000-0000000000p1', '00000000-0000-7000-8000-0000000000a1', 'public', 'image', 'posts/a.jpg');

SELECT pg_temp.pass('media reel inserted');

SELECT pg_temp.expect_error(
  $$INSERT INTO feed_posts (user_id, audience, media_type, media_path)
    VALUES ('00000000-0000-7000-8000-0000000000a3', 'public', 'image', 'posts/q.jpg')$$,
  '23514', 'post requires social opt-in');

SELECT pg_temp.expect_error(
  $$INSERT INTO feed_posts (user_id, audience, media_type, media_path)
    VALUES ('00000000-0000-7000-8000-0000000000a1', 'public', 'video', 'posts/a.mp4')$$,
  '23514', 'video requires a poster');

INSERT INTO feed_post_likes (post_id, user_id) VALUES
  ('00000000-0000-7000-8000-0000000000p1', '00000000-0000-7000-8000-0000000000a2');
SELECT pg_temp.expect_error(
  $$INSERT INTO feed_post_likes (post_id, user_id)
    VALUES ('00000000-0000-7000-8000-0000000000p1', '00000000-0000-7000-8000-0000000000a2')$$,
  '23505', 'one like per user per post');

INSERT INTO feed_post_comments (id, post_id, user_id, body) VALUES
  ('00000000-0000-7000-8000-0000000000c1', '00000000-0000-7000-8000-0000000000p1', '00000000-0000-7000-8000-0000000000a2', 'nice');
INSERT INTO feed_post_comments (post_id, user_id, parent_id, body) VALUES
  ('00000000-0000-7000-8000-0000000000p1', '00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000c1', 'thanks');
SELECT pg_temp.expect_error(
  $$INSERT INTO feed_post_comments (post_id, user_id, parent_id, body)
    SELECT post_id, '00000000-0000-7000-8000-0000000000a1', id, 'too deep'
      FROM feed_post_comments WHERE parent_id IS NOT NULL$$,
  '23514', 'replies are one level deep');
SELECT pg_temp.expect_error(
  $$INSERT INTO feed_post_comments (post_id, user_id, body)
    VALUES ('00000000-0000-7000-8000-0000000000p1', '00000000-0000-7000-8000-0000000000a3', 'nope')$$,
  '23514', 'comment requires social opt-in');

INSERT INTO social_connections (requester_id, addressee_id, status) VALUES
  ('00000000-0000-7000-8000-0000000000a1', '00000000-0000-7000-8000-0000000000a2', 'pending');
UPDATE social_connections SET status = 'accepted', responded_at = now()
 WHERE requester_id = '00000000-0000-7000-8000-0000000000a1';
SELECT pg_temp.expect_error(
  $$UPDATE social_connections SET status = 'pending'
     WHERE requester_id = '00000000-0000-7000-8000-0000000000a1'$$,
  '23514', 'accepted cannot return to pending');

SELECT pg_temp.pass('social invariants');
ROLLBACK;
