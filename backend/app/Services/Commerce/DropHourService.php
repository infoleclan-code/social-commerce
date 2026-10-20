<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\Currency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** A timed drop whose remaining units are reserved with one conditional update. */
final class DropHourService
{
    /**
     * The live drop hour, if any. Drop hours are started by the scheduler (drops:rotate); a GET no
     * longer creates one.
     *
     * @return array<string, mixed>
     */
    public function current(): array
    {
        $this->releaseExpired();
        $live = DB::table('drop_hours')->where('ends_at', '>', now())->where('starts_at', '<=', now())->orderBy('ends_at')->first();

        return ['drop' => $live === null ? null : $this->shape($live)];
    }

    /** @return array<string, mixed> */
    public function reserve(User $user, string $dropId): array
    {
        return DB::transaction(function () use ($user, $dropId): array {
            $this->releaseExpired();
            $existing = DB::table('drop_reservations')
                ->where('drop_hour_id', $dropId)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();
            if ($existing !== null && $existing->status === 'reserved' && now()->lessThan($existing->expires_at)) {
                $drop = DB::table('drop_hours')->where('id', $dropId)->first();

                return ['reservation_id' => $existing->id, 'replayed' => true, 'drop' => $this->shape($drop)];
            }
            $taken = DB::update(
                'UPDATE drop_hours SET reserved = reserved + 1 WHERE id = ? AND starts_at <= now() AND ends_at > now() AND reserved + 1 <= stock',
                [$dropId],
            );
            if ($taken !== 1) {
                throw ApiException::outOfStock();
            }
            $id = (string) Str::uuid7();
            $expires = now()->addMinutes(2);
            if ($existing === null) {
                DB::table('drop_reservations')->insert([
                    'id' => $id,
                    'drop_hour_id' => $dropId,
                    'user_id' => $user->id,
                    'quantity' => 1,
                    'status' => 'reserved',
                    'expires_at' => $expires,
                    'created_at' => now(),
                ]);
            } else {
                $id = (string) $existing->id;
                DB::table('drop_reservations')->where('id', $id)->update([
                    'status' => 'reserved',
                    'expires_at' => $expires,
                ]);
            }

            return [
                'reservation_id' => $id,
                'replayed' => false,
                'expires_at' => (string) $expires,
                'drop' => $this->shape(DB::table('drop_hours')->where('id', $dropId)->first()),
            ];
        });
    }

    /** @return array<string, mixed> */
    public function shape(object $drop): array
    {
        $product = DB::table('products')->where('id', $drop->product_id)->first();

        return [
            'id' => $drop->id,
            'product_id' => $drop->product_id,
            'title' => $product->title ?? '',
            'ends_at' => (string) $drop->ends_at,
            'remaining' => (int) $drop->stock - (int) $drop->reserved,
            'price' => Currency::money((int) $drop->price_minor, (string) ($product->currency ?? 'USD')),
        ];
    }

    private function releaseExpired(): void
    {
        DB::transaction(function (): void {
            $expired = DB::table('drop_reservations')
                ->where('status', 'reserved')
                ->where('expires_at', '<=', now())
                ->lockForUpdate()
                ->get();
            foreach ($expired as $row) {
                DB::update(
                    'UPDATE drop_hours SET reserved = reserved - ? WHERE id = ? AND reserved >= ?',
                    [(int) $row->quantity, $row->drop_hour_id, (int) $row->quantity],
                );
                DB::table('drop_reservations')->where('id', $row->id)->update(['status' => 'released']);
            }
        });
    }
}
