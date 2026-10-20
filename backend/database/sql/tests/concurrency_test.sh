#!/usr/bin/env bash
# =============================================================================
# concurrency_test.sh — two sessions like the same reel at once.
# Exactly one insert commits; the other hits feed_post_likes_pkey.
#
#   PGHOST=127.0.0.1 PGUSER=postgres ./database/sql/tests/concurrency_test.sh
# =============================================================================
set -euo pipefail
cd "$(dirname "$0")/.."
DB="concurrency_test_$$"
psql_db() { psql -X -q -v ON_ERROR_STOP=1 -d "$DB" "$@"; }

cleanup() { psql -X -q -d postgres -c "DROP DATABASE IF EXISTS $DB" >/dev/null 2>&1 || true; }
trap cleanup EXIT

psql -X -q -d postgres -c "CREATE DATABASE $DB"
cat [0-9]*.sql | psql_db -1 -f - >/dev/null

psql_db <<'SQL'
INSERT INTO users (id, email, username, display_name, social_feed_enabled) VALUES
  ('00000000-0000-7000-8000-0000000000a1', 'author@example.com', 'author', 'Author', true),
  ('00000000-0000-7000-8000-0000000000a2', 'fan@example.com', 'fan', 'Fan', true);
INSERT INTO feed_posts (id, user_id, audience, media_type, media_path) VALUES
  ('00000000-0000-7000-8000-0000000000p1', '00000000-0000-7000-8000-0000000000a1', 'public', 'image', 'posts/a.jpg');
SQL

like_sql="INSERT INTO feed_post_likes (post_id, user_id) VALUES ('00000000-0000-7000-8000-0000000000p1', '00000000-0000-7000-8000-0000000000a2');"
psql_db -c "$like_sql" >/dev/null &
psql_db -c "$like_sql" >/dev/null &
wait || true

count=$(psql_db -tA -c "SELECT count(*) FROM feed_post_likes")
likes=$(psql_db -tA -c "SELECT likes_count FROM feed_posts WHERE id = '00000000-0000-7000-8000-0000000000p1'")
if [[ "$count" != "1" || "$likes" != "1" ]]; then
  echo "FAIL  concurrent like: rows=$count likes_count=$likes" >&2
  exit 1
fi
echo "PASS  one like survived two concurrent inserts"
