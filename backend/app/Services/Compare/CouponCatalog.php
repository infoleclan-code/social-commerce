<?php

declare(strict_types=1);

namespace App\Services\Compare;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CouponCatalog
{
    /** @return list<array{code: string, label: string}> */
    public function forHost(string $host, string $country): array
    {
        return DB::table('store_coupons')
            ->where('host', $host)
            ->where('active', true)
            ->where(function ($query) use ($country): void {
                $query->whereNull('country')->orWhere('country', $country);
            })
            ->orderBy('code')
            ->get(['code', 'label'])
            ->map(fn (object $row): array => [
                'code' => (string) $row->code,
                'label' => (string) $row->label,
            ])
            ->all();
    }

    /** @return array{id: string, host: string, code: string, label: string, country: string|null} */
    public function create(string $host, string $code, string $label, ?string $country): array
    {
        $id = (string) Str::uuid7();
        DB::table('store_coupons')->insert([
            'id' => $id,
            'host' => $host,
            'code' => $code,
            'label' => $label,
            'country' => $country,
            'active' => true,
            'created_at' => now(),
        ]);

        return ['id' => $id, 'host' => $host, 'code' => $code, 'label' => $label, 'country' => $country];
    }
}
