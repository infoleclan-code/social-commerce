<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\Currency;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * A cart is one row per product (quantity 1–3). Checkout places one order per line through the
 * existing checkout, keyed from the request's Idempotency-Key so a retry does not charge twice.
 * Street addresses are stored as ciphertext; country stays in the clear so landed cost can be priced.
 */
final class CartService
{
    public function __construct(private readonly CheckoutService $checkout) {}

    /** @return list<array<string, mixed>> */
    public function items(User $user): array
    {
        return DB::table('cart_items as c')
            ->join('products as p', 'p.id', '=', 'c.product_id')
            ->where('c.user_id', $user->id)
            ->orderBy('c.created_at')
            ->get(['c.product_id', 'c.quantity', 'p.title', 'p.brand', 'p.image_url', 'p.price_minor', 'p.currency', 'p.stock', 'p.listing_status'])
            ->map(fn (object $row): array => [
                'product_id' => $row->product_id,
                'title' => $row->title,
                'brand' => $row->brand,
                'image_url' => $row->image_url,
                'quantity' => (int) $row->quantity,
                'stock' => (int) $row->stock,
                'available' => $row->listing_status === 'active' && (int) $row->stock >= (int) $row->quantity,
                'price' => Currency::money((int) $row->price_minor, (string) $row->currency),
            ])->all();
    }

    public function put(User $user, string $productId, int $quantity): void
    {
        $product = Str::isUuid($productId)
            ? DB::table('products')->where('id', $productId)->where('listing_status', 'active')->where('stock', '>', 0)->first()
            : null;
        if ($product === null) {
            throw ApiException::outOfStock();
        }
        DB::table('cart_items')->updateOrInsert(
            ['user_id' => $user->id, 'product_id' => $productId],
            ['quantity' => $quantity, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function remove(User $user, string $productId): void
    {
        DB::table('cart_items')->where('user_id', $user->id)->where('product_id', $productId)->delete();
    }

    /**
     * @return array{orders: list<array<string, mixed>>}
     */
    public function checkout(User $user, ?string $addressId, string $idempotencyKey): array
    {
        $items = DB::table('cart_items')->where('user_id', $user->id)->orderBy('product_id')->get();
        if ($items->isEmpty()) {
            throw ApiException::outOfStock();
        }
        $orders = [];
        foreach ($items as $item) {
            $key = substr($idempotencyKey, 0, 24).substr(str_replace('-', '', (string) $item->product_id), 0, 24);
            $orders[] = $this->checkout->checkout(
                $user,
                (string) $item->product_id,
                null,
                null,
                null,
                (int) $item->quantity,
                $key,
                null,
                false,
                null,
                $addressId,
            );
            DB::table('cart_items')->where('user_id', $user->id)->where('product_id', $item->product_id)->delete();
        }

        return ['orders' => $orders];
    }

    /** @param  array{line: string, locality: string, postal_code: string, country: string, label?: string|null, is_default?: bool}  $input
     * @return array<string, mixed>
     */
    public function saveAddress(User $user, array $input): array
    {
        $id = (string) Str::uuid7();
        $default = (bool) ($input['is_default'] ?? false);
        DB::transaction(function () use ($user, $input, $id, $default): void {
            if ($default) {
                DB::table('shipping_addresses')->where('user_id', $user->id)->update(['is_default' => false]);
            }
            DB::table('shipping_addresses')->insert([
                'id' => $id,
                'user_id' => $user->id,
                'label' => $input['label'] ?? null,
                'country' => strtoupper($input['country']),
                'address_ciphertext' => Crypt::encryptString(json_encode([
                    'line' => $input['line'],
                    'locality' => $input['locality'],
                    'postal_code' => $input['postal_code'],
                ], JSON_THROW_ON_ERROR)),
                'is_default' => $default,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return $this->address($user, $id);
    }

    /** @return list<array<string, mixed>> */
    public function addresses(User $user): array
    {
        return DB::table('shipping_addresses')->where('user_id', $user->id)->orderByDesc('is_default')->orderBy('created_at')->get()
            ->map(fn (object $row): array => $this->present($row))->all();
    }

    public function deleteAddress(User $user, string $addressId): void
    {
        $deleted = DB::table('shipping_addresses')->where('id', $addressId)->where('user_id', $user->id)->delete();
        if ($deleted !== 1) {
            throw ApiException::addressNotFound();
        }
    }

    /** @return array<string, mixed> */
    private function address(User $user, string $id): array
    {
        $row = DB::table('shipping_addresses')->where('id', $id)->where('user_id', $user->id)->first();
        if ($row === null) {
            throw ApiException::addressNotFound();
        }

        return $this->present($row);
    }

    /** @return array<string, mixed> */
    private function present(object $row): array
    {
        $line = '';
        $locality = '';
        $postal = '';
        try {
            $decoded = json_decode(Crypt::decryptString((string) $row->address_ciphertext), true, 8, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $line = (string) ($decoded['line'] ?? '');
                $locality = (string) ($decoded['locality'] ?? '');
                $postal = (string) ($decoded['postal_code'] ?? '');
            }
        } catch (Throwable) {
            $line = '';
        }

        return [
            'id' => $row->id,
            'label' => $row->label,
            'line' => $line,
            'locality' => $locality,
            'postal_code' => $postal,
            'country' => $row->country,
            'is_default' => (bool) $row->is_default,
        ];
    }
}
