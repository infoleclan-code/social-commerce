-- =============================================================================
-- 20_feed.sql
-- Reels (feed_posts), likes (feed_post_likes), comments (feed_post_comments).
--
-- Counters and rank_score are trigger-maintained. A post or comment insert is
-- rejected while users.social_feed_enabled is false. Likes do not require it.
-- =============================================================================

CREATE TABLE feed_posts (
  id                  uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
  user_id             uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
  kind                text NOT NULL DEFAULT 'media' CHECK (kind IN ('media')),
  caption             text CHECK (char_length(caption) <= 2000),
  audience            text NOT NULL DEFAULT 'friends' CHECK (audience IN ('friends', 'public')),
  media_type          text CHECK (media_type IN ('image', 'video')),
  media_path          text CHECK (char_length(media_path) <= 500),
  thumbnail_path      text CHECK (char_length(thumbnail_path) <= 500),
  media_width         integer CHECK (media_width BETWEEN 1 AND 10000),
  media_height        integer CHECK (media_height BETWEEN 1 AND 10000),
  media_duration_ms   integer CHECK (media_duration_ms BETWEEN 1 AND 600000),
  tags                jsonb NOT NULL DEFAULT '[]'::jsonb,
  likes_count         integer NOT NULL DEFAULT 0 CHECK (likes_count >= 0),
  comments_count      integer NOT NULL DEFAULT 0 CHECK (comments_count >= 0),
  shares_count        integer NOT NULL DEFAULT 0 CHECK (shares_count >= 0),
  rank_score          double precision NOT NULL DEFAULT 0,
  created_at          timestamptz NOT NULL DEFAULT now(),
  updated_at          timestamptz NOT NULL DEFAULT now(),
  deleted_at          timestamptz,

  CONSTRAINT feed_posts_tags_shape CHECK (
    jsonb_typeof(tags) = 'array' AND jsonb_array_length(tags) <= 10
    AND NOT jsonb_path_exists(tags, '$[*] ? (@.type() != "string")')),
  CONSTRAINT feed_posts_media_shape CHECK (
    (kind = 'media') = (media_path IS NOT NULL)
    AND (media_path IS NULL) = (media_type IS NULL)
    AND (media_type IS DISTINCT FROM 'video' OR thumbnail_path IS NOT NULL))
);

COMMENT ON TABLE feed_posts IS 'Reels. A row exists only because the user posted it. kind is media: one image or one video.';
COMMENT ON COLUMN feed_posts.media_path IS 'Storage key of the uploaded media. The API builds a CDN URL; never store a public bucket URL.';
COMMENT ON COLUMN feed_posts.rank_score IS 'Trigger-maintained: log(max(1, likes + 2*comments + 3*shares)) + epoch(created_at)/45000.';

CREATE INDEX feed_posts_user_idx ON feed_posts (user_id, created_at DESC) WHERE deleted_at IS NULL;
CREATE INDEX feed_posts_public_idx ON feed_posts (created_at DESC) WHERE audience = 'public' AND deleted_at IS NULL;
CREATE INDEX feed_posts_for_you_idx ON feed_posts (rank_score DESC, id DESC)
  WHERE audience = 'public' AND deleted_at IS NULL;

CREATE TRIGGER feed_posts_set_updated_at BEFORE UPDATE ON feed_posts
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE OR REPLACE FUNCTION feed_posts_require_opt_in() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM users u WHERE u.id = NEW.user_id AND u.social_feed_enabled AND u.deleted_at IS NULL) THEN
    RAISE EXCEPTION 'User % has not opted in to the social feed', NEW.user_id USING ERRCODE = 'check_violation';
  END IF;
  RETURN NEW;
END $$;

CREATE TRIGGER feed_posts_require_opt_in BEFORE INSERT ON feed_posts
  FOR EACH ROW EXECUTE FUNCTION feed_posts_require_opt_in();

CREATE OR REPLACE FUNCTION feed_posts_rank_score() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  NEW.rank_score := log(greatest(NEW.likes_count + 2 * NEW.comments_count + 3 * NEW.shares_count, 1)::double precision)
                    + extract(epoch FROM NEW.created_at)::double precision / 45000.0;
  RETURN NEW;
END $$;

CREATE TRIGGER feed_posts_rank_score
  BEFORE INSERT OR UPDATE OF likes_count, comments_count, shares_count, created_at ON feed_posts
  FOR EACH ROW EXECUTE FUNCTION feed_posts_rank_score();

-- -----------------------------------------------------------------------------
-- Likes. Who liked a post is private: the API exposes the count and the
-- viewer's own flag. Liking does not require the social opt-in.
-- -----------------------------------------------------------------------------
CREATE TABLE feed_post_likes (
  post_id     uuid NOT NULL REFERENCES feed_posts (id) ON DELETE CASCADE,
  user_id     uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
  created_at  timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (post_id, user_id)
);

CREATE INDEX feed_post_likes_user_idx ON feed_post_likes (user_id, created_at DESC);

CREATE OR REPLACE FUNCTION feed_post_likes_count() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'INSERT' THEN
    UPDATE feed_posts SET likes_count = likes_count + 1 WHERE id = NEW.post_id;
  ELSE
    UPDATE feed_posts SET likes_count = likes_count - 1 WHERE id = OLD.post_id AND likes_count > 0;
  END IF;
  RETURN NULL;
END $$;

CREATE TRIGGER feed_post_likes_count AFTER INSERT OR DELETE ON feed_post_likes
  FOR EACH ROW EXECUTE FUNCTION feed_post_likes_count();

-- -----------------------------------------------------------------------------
-- Comments. One level of replies; a reply must belong to the same post.
-- Comments require the social opt-in because they show the username.
-- -----------------------------------------------------------------------------
CREATE TABLE feed_post_comments (
  id          uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
  post_id     uuid NOT NULL REFERENCES feed_posts (id) ON DELETE CASCADE,
  user_id     uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
  parent_id   uuid,
  body        text NOT NULL CHECK (char_length(btrim(body)) BETWEEN 1 AND 1000),
  created_at  timestamptz NOT NULL DEFAULT now(),
  updated_at  timestamptz NOT NULL DEFAULT now(),
  deleted_at  timestamptz,

  CONSTRAINT feed_post_comments_id_post_key UNIQUE (id, post_id),
  CONSTRAINT feed_post_comments_parent_fk FOREIGN KEY (parent_id, post_id)
    REFERENCES feed_post_comments (id, post_id) ON DELETE CASCADE,
  CONSTRAINT feed_post_comments_not_own_parent CHECK (parent_id IS DISTINCT FROM id)
);

CREATE INDEX feed_post_comments_thread_idx ON feed_post_comments (post_id, created_at, id)
  WHERE parent_id IS NULL AND deleted_at IS NULL;
CREATE INDEX feed_post_comments_replies_idx ON feed_post_comments (parent_id, created_at, id)
  WHERE parent_id IS NOT NULL AND deleted_at IS NULL;
CREATE INDEX feed_post_comments_user_idx ON feed_post_comments (user_id, created_at DESC);
CREATE TRIGGER feed_post_comments_set_updated_at BEFORE UPDATE ON feed_post_comments
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE OR REPLACE FUNCTION feed_post_comments_guard() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM users u WHERE u.id = NEW.user_id AND u.social_feed_enabled AND u.deleted_at IS NULL) THEN
    RAISE EXCEPTION 'User % has not opted in to the social feed', NEW.user_id USING ERRCODE = 'check_violation';
  END IF;
  IF NEW.parent_id IS NOT NULL
     AND EXISTS (SELECT 1 FROM feed_post_comments p WHERE p.id = NEW.parent_id AND p.parent_id IS NOT NULL) THEN
    RAISE EXCEPTION 'Replies are one level deep: comment % is already a reply', NEW.parent_id USING ERRCODE = 'check_violation';
  END IF;
  RETURN NEW;
END $$;

CREATE TRIGGER feed_post_comments_guard BEFORE INSERT ON feed_post_comments
  FOR EACH ROW EXECUTE FUNCTION feed_post_comments_guard();

CREATE OR REPLACE FUNCTION feed_post_comments_count() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
  v_delta integer := 0;
  v_post  uuid;
BEGIN
  IF TG_OP = 'INSERT' THEN
    v_post := NEW.post_id;
    v_delta := CASE WHEN NEW.deleted_at IS NULL THEN 1 ELSE 0 END;
  ELSIF TG_OP = 'DELETE' THEN
    v_post := OLD.post_id;
    v_delta := CASE WHEN OLD.deleted_at IS NULL THEN -1 ELSE 0 END;
  ELSE
    v_post := NEW.post_id;
    v_delta := CASE
      WHEN OLD.deleted_at IS NULL AND NEW.deleted_at IS NOT NULL THEN -1
      WHEN OLD.deleted_at IS NOT NULL AND NEW.deleted_at IS NULL THEN 1
      ELSE 0 END;
  END IF;

  IF v_delta <> 0 THEN
    UPDATE feed_posts SET comments_count = greatest(comments_count + v_delta, 0) WHERE id = v_post;
  END IF;
  RETURN NULL;
END $$;

CREATE TRIGGER feed_post_comments_count AFTER INSERT OR DELETE OR UPDATE OF deleted_at ON feed_post_comments
  FOR EACH ROW EXECUTE FUNCTION feed_post_comments_count();
