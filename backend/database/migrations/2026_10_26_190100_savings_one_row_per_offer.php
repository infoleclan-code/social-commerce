<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One savings row per person per offer. Repeating the same store click
 * must not count the gap twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            delete from savings_events
            where id in (
                select id from (
                    select id, row_number() over (
                        partition by user_id, offer_key order by created_at, id
                    ) as n
                    from savings_events
                ) ranked
                where n > 1
            )
        SQL);

        DB::statement('create unique index savings_events_user_offer_unique on savings_events (user_id, offer_key)');
    }

    public function down(): void
    {
        DB::statement('drop index if exists savings_events_user_offer_unique');
    }
};
