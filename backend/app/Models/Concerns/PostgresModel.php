<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * Conventions shared by every model backed by this schema:
 *  - uuid v7 primary keys (Laravel 13 HasUuids generates v7; the DB default
 *    gen_uuid_v7() covers raw inserts), so ids are time-ordered and never
 *    sequential/enumerable;
 *  - timestamps written with microseconds and an explicit UTC offset, so
 *    timestamptz columns are correct regardless of the DB session time zone.
 */
trait PostgresModel
{
    use HasUuids;

    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.uP';
    }
}
