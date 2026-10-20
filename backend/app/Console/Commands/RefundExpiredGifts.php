<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Payments\OrderPayments;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class RefundExpiredGifts extends Command
{
    protected $signature = 'gifts:refund-expired {--limit=200}';

    protected $description = 'Refund paid gifts nobody claimed before the link expired';

    public function handle(OrderPayments $payments): int
    {
        $expired = DB::table('gifts as g')
            ->join('orders as o', 'o.id', '=', 'g.order_id')
            ->where('g.status', 'paid')
            ->where('g.expires_at', '<', now())
            ->where('o.payment_status', 'captured')
            ->orderBy('g.expires_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get(['o.id', 'o.total_minor', 'o.amount_refunded_minor']);
        $refunded = 0;
        foreach ($expired as $order) {
            try {
                $payments->refund((string) $order->id, (int) $order->total_minor - (int) $order->amount_refunded_minor, 'requested_by_customer', restock: true);
                $refunded++;
            } catch (Throwable $exception) {
                $this->warn('Gift order '.$order->id.' left for the next run ('.$exception::class.').');
            }
        }
        $this->info("Refunded {$refunded} expired gift(s).");

        return self::SUCCESS;
    }
}
