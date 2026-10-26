<?php

declare(strict_types=1);

namespace App\Services\Compare;

use Illuminate\Support\Carbon;

/** Typical global sale dates. The day can move; the forecast says so. */
final class SaleCalendar
{
    /** @return array{name: string, on: string, typical: bool, drop_percent: int} */
    public function next(?Carbon $from = null): array
    {
        $from = ($from ?? now())->copy()->startOfDay();
        $events = array_merge($this->year((int) $from->year), $this->year((int) $from->year + 1));
        usort($events, fn (array $left, array $right): int => $left['on'] <=> $right['on']);
        foreach ($events as $event) {
            if ($event['on'] >= $from->toDateString()) {
                return $event;
            }
        }

        return $events[0];
    }

    /** @return list<array{name: string, on: string, typical: bool, drop_percent: int}> */
    private function year(int $year): array
    {
        $friday = Carbon::parse('fourth thursday of november '.$year)->addDay();

        return [
            ['name' => 'Prime Day', 'on' => sprintf('%d-07-16', $year), 'typical' => true, 'drop_percent' => 15],
            ['name' => 'Back to school', 'on' => sprintf('%d-08-15', $year), 'typical' => true, 'drop_percent' => 8],
            ['name' => 'Prime Day', 'on' => sprintf('%d-10-08', $year), 'typical' => true, 'drop_percent' => 15],
            ['name' => 'Singles Day', 'on' => sprintf('%d-11-11', $year), 'typical' => false, 'drop_percent' => 10],
            ['name' => 'Black Friday', 'on' => $friday->toDateString(), 'typical' => false, 'drop_percent' => 20],
            ['name' => 'Cyber Monday', 'on' => $friday->copy()->addDays(3)->toDateString(), 'typical' => false, 'drop_percent' => 15],
            ['name' => 'Boxing Day', 'on' => sprintf('%d-12-26', $year), 'typical' => false, 'drop_percent' => 12],
        ];
    }
}
