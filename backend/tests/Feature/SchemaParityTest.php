<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards against drift between PHP enums and the database CHECK constraints.
 * Requires the test database to be PostgreSQL (see README: phpunit.xml).
 */
final class SchemaParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_enum_cast_matches_its_check_constraint(): void
    {
        $domains = $this->checkDomains();
        $compared = 0;

        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');
            /** @var Model $model */
            $model = new $class;

            foreach ($model->getCasts() as $column => $cast) {
                if (! is_string($cast) || ! enum_exists($cast)) {
                    continue;
                }
                $key = $model->getTable().'.'.$column;
                $this->assertArrayHasKey($column, $domains[$model->getTable()] ?? [], "{$key} has no CHECK (... IN ...) constraint");

                $enumValues = array_map(fn ($c) => $c->value, $cast::cases());
                $dbValues = $domains[$model->getTable()][$column];
                sort($enumValues);
                sort($dbValues);
                $this->assertSame($dbValues, $enumValues, "{$key} differs from ".class_basename($cast));
                $compared++;
            }
        }

        $this->assertGreaterThanOrEqual(10, $compared);
    }

    /** @return array<string, array<string, list<string>>> table => column => allowed values */
    private function checkDomains(): array
    {
        $domains = [];
        $rows = DB::select("SELECT conrelid::regclass::text AS tbl, pg_get_constraintdef(oid) AS def
                              FROM pg_constraint WHERE contype = 'c' AND connamespace = 'public'::regnamespace");

        foreach ($rows as $row) {
            if (preg_match('/^CHECK \(\((\w+) = ANY \(ARRAY\[([^\]]+)\]\)\)\)$/', $row->def, $m)) {
                preg_match_all("/'([^']+)'::/", $m[2], $values);
                $domains[$row->tbl][$m[1]] = $values[1];
            } elseif (preg_match("/^CHECK \\(\\((\\w+) = '([^']+)'::\\w+\\)\\)$/", $row->def, $m)) {
                // PostgreSQL rewrites a one-value IN (...) as a plain equality.
                $domains[$row->tbl][$m[1]] = [$m[2]];
            }
        }

        return $domains;
    }
}
