<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Growth expansion: opted-in live moments, rooms, an agent with caps,
 * scheduled shows, city unlocks, seasons, and the trust layer.
 * Streaks stay off until the user turns them on. No ads in live activities.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE users
              ADD COLUMN trend_hunter_points integer NOT NULL DEFAULT 0 CHECK (trend_hunter_points >= 0),
              ADD COLUMN trend_hunter_level integer NOT NULL DEFAULT 0 CHECK (trend_hunter_level BETWEEN 0 AND 5),
              ADD COLUMN streaks_opt_in boolean NOT NULL DEFAULT false,
              ADD COLUMN paused_at timestamptz,
              ADD COLUMN deletion_requested_at timestamptz;

            CREATE TABLE live_activities (
              id          uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              user_id     uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
              kind        text NOT NULL CHECK (kind IN ('auction', 'squad', 'drop', 'order')),
              subject_id  uuid NOT NULL,
              title       text NOT NULL CHECK (char_length(title) BETWEEN 1 AND 80),
              body        text NOT NULL CHECK (char_length(body) <= 160),
              action      text NOT NULL CHECK (action IN ('rebid', 'open', 'track')),
              ends_at     timestamptz NOT NULL,
              created_at  timestamptz NOT NULL DEFAULT now(),
              CHECK (ends_at > created_at)
            );
            CREATE INDEX live_activities_user_idx ON live_activities (user_id, ends_at);

            CREATE TABLE shop_rooms (
              id          uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              host_id     uuid NOT NULL REFERENCES users (id),
              post_id     uuid REFERENCES feed_posts (id) ON DELETE SET NULL,
              token       text NOT NULL UNIQUE CHECK (token ~ '^[A-Za-z0-9]{10,32}$'),
              visibility  text NOT NULL DEFAULT 'private' CHECK (visibility IN ('private', 'public')),
              ends_at     timestamptz NOT NULL,
              created_at  timestamptz NOT NULL DEFAULT now(),
              CHECK (ends_at > created_at)
            );

            CREATE TABLE shop_room_members (
              room_id    uuid NOT NULL REFERENCES shop_rooms (id) ON DELETE CASCADE,
              user_id    uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
              joined_at  timestamptz NOT NULL DEFAULT now(),
              PRIMARY KEY (room_id, user_id)
            );

            CREATE TABLE shop_room_votes (
              room_id    uuid NOT NULL REFERENCES shop_rooms (id) ON DELETE CASCADE,
              user_id    uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
              choice     text NOT NULL CHECK (char_length(choice) BETWEEN 1 AND 40),
              created_at timestamptz NOT NULL DEFAULT now(),
              PRIMARY KEY (room_id, user_id)
            );

            CREATE TABLE agent_briefs (
              user_id            uuid PRIMARY KEY REFERENCES users (id) ON DELETE CASCADE,
              budget_minor       bigint NOT NULL CHECK (budget_minor >= 0),
              monthly_cap_minor  bigint NOT NULL CHECK (monthly_cap_minor >= 0),
              styles             text NOT NULL DEFAULT '' CHECK (char_length(styles) <= 200),
              alerts_per_day     integer NOT NULL DEFAULT 3 CHECK (alerts_per_day BETWEEN 0 AND 5),
              allow_bid          boolean NOT NULL DEFAULT false,
              allow_squad        boolean NOT NULL DEFAULT false,
              updated_at         timestamptz NOT NULL DEFAULT now()
            );

            CREATE TABLE agent_alerts (
              id          uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              user_id     uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
              product_id  uuid NOT NULL REFERENCES products (id),
              body        text NOT NULL,
              status      text NOT NULL CHECK (status IN ('pending', 'approved', 'undone')),
              created_at  timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX agent_alerts_user_idx ON agent_alerts (user_id, created_at DESC);

            CREATE TABLE live_shows (
              id         uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              host_id    uuid NOT NULL REFERENCES users (id),
              title      text NOT NULL,
              category   text NOT NULL,
              region     text NOT NULL,
              starts_at  timestamptz NOT NULL,
              ends_at    timestamptz NOT NULL,
              created_at timestamptz NOT NULL DEFAULT now(),
              CHECK (ends_at > starts_at AND ends_at <= starts_at + interval '2 hours')
            );

            CREATE TABLE show_reminders (
              show_id    uuid NOT NULL REFERENCES live_shows (id) ON DELETE CASCADE,
              user_id    uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
              created_at timestamptz NOT NULL DEFAULT now(),
              PRIMARY KEY (show_id, user_id)
            );

            CREATE TABLE show_raids (
              id           uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              from_show_id uuid NOT NULL REFERENCES live_shows (id),
              to_show_id   uuid NOT NULL REFERENCES live_shows (id),
              created_at   timestamptz NOT NULL DEFAULT now(),
              CHECK (from_show_id <> to_show_id)
            );

            CREATE TABLE places (
              id           uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              name         text NOT NULL,
              kind         text NOT NULL CHECK (kind IN ('city', 'campus')),
              member_goal  integer NOT NULL CHECK (member_goal >= 3),
              unlocked_at  timestamptz
            );

            CREATE TABLE place_members (
              place_id   uuid NOT NULL REFERENCES places (id) ON DELETE CASCADE,
              user_id    uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
              founding   boolean NOT NULL DEFAULT false,
              joined_at  timestamptz NOT NULL DEFAULT now(),
              PRIMARY KEY (place_id, user_id)
            );

            CREATE TABLE seasons (
              id         uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              name       text NOT NULL,
              starts_on  date NOT NULL,
              ends_on    date NOT NULL,
              CHECK (ends_on = starts_on + 56)
            );

            CREATE TABLE season_quests (
              id         uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              season_id  uuid NOT NULL REFERENCES seasons (id) ON DELETE CASCADE,
              title      text NOT NULL,
              target     integer NOT NULL CHECK (target BETWEEN 1 AND 100)
            );

            CREATE TABLE quest_progress (
              quest_id     uuid NOT NULL REFERENCES season_quests (id) ON DELETE CASCADE,
              user_id      uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
              progress     integer NOT NULL DEFAULT 0 CHECK (progress >= 0),
              completed_at timestamptz,
              PRIMARY KEY (quest_id, user_id)
            );

            CREATE TABLE hauls (
              id          uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              user_id     uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
              caption     text NOT NULL CHECK (char_length(caption) BETWEEN 1 AND 160),
              published   boolean NOT NULL DEFAULT false,
              created_at  timestamptz NOT NULL DEFAULT now()
            );

            CREATE TABLE wear_logs (
              id              uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              closet_item_id  uuid NOT NULL REFERENCES closet_items (id) ON DELETE CASCADE,
              user_id         uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
              worn_on         date NOT NULL,
              UNIQUE (closet_item_id, worn_on)
            );

            CREATE TABLE trend_awards (
              user_id     uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
              product_id  uuid NOT NULL REFERENCES products (id),
              points      integer NOT NULL CHECK (points > 0),
              created_at  timestamptz NOT NULL DEFAULT now(),
              PRIMARY KEY (user_id, product_id)
            );

            CREATE TABLE spend_limits (
              user_id              uuid PRIMARY KEY REFERENCES users (id) ON DELETE CASCADE,
              monthly_limit_minor  bigint CHECK (monthly_limit_minor IS NULL OR monthly_limit_minor >= 0),
              night_hold           boolean NOT NULL DEFAULT true
            );

            CREATE TABLE night_holds (
              id          uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              user_id     uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
              order_id    uuid NOT NULL UNIQUE REFERENCES orders (id),
              status      text NOT NULL CHECK (status IN ('held', 'released', 'cancelled')),
              created_at  timestamptz NOT NULL DEFAULT now()
            );

            CREATE TABLE notification_mutes (
              user_id   uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
              channel   text NOT NULL CHECK (channel IN ('auction', 'squad', 'drop', 'show', 'agent')),
              muted_at  timestamptz NOT NULL DEFAULT now(),
              PRIMARY KEY (user_id, channel)
            );

            CREATE TABLE account_exports (
              id          uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              user_id     uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
              status      text NOT NULL CHECK (status IN ('ready')),
              created_at  timestamptz NOT NULL DEFAULT now()
            );

            INSERT INTO places (id, name, kind, member_goal)
            VALUES (gen_uuid_v7(), 'Tbilisi', 'city', 3);

            INSERT INTO seasons (id, name, starts_on, ends_on)
            VALUES (gen_uuid_v7(), 'Season 1', CURRENT_DATE, CURRENT_DATE + 56);
            SQL);

        $seasonId = DB::table('seasons')->orderByDesc('starts_on')->value('id');
        DB::table('season_quests')->insert([
            ['id' => (string) \Illuminate\Support\Str::uuid7(), 'season_id' => $seasonId, 'title' => 'Scan ten pieces', 'target' => 10],
            ['id' => (string) \Illuminate\Support\Str::uuid7(), 'season_id' => $seasonId, 'title' => 'Share a kept purchase', 'target' => 1],
        ]);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS account_exports, notification_mutes, night_holds, spend_limits,
              trend_awards, wear_logs, hauls, quest_progress, season_quests, seasons,
              place_members, places, show_raids, show_reminders, live_shows,
              agent_alerts, agent_briefs, shop_room_votes, shop_room_members, shop_rooms,
              live_activities;
            ALTER TABLE users
              DROP COLUMN IF EXISTS trend_hunter_points,
              DROP COLUMN IF EXISTS trend_hunter_level,
              DROP COLUMN IF EXISTS streaks_opt_in,
              DROP COLUMN IF EXISTS paused_at,
              DROP COLUMN IF EXISTS deletion_requested_at;
            SQL);
    }
};
