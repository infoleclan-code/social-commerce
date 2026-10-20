<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Services\Payments\StripeWebhookHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StripeWebhookController extends Controller
{
    public function __construct(private readonly StripeWebhookHandler $webhooks) {}

    public function handle(Request $request): JsonResponse
    {
        if ((int) $request->header('Content-Length', '0') > StripeWebhookHandler::MAX_PAYLOAD_BYTES) {
            throw ApiException::payloadTooLarge();
        }
        $event = $this->webhooks->verify($request->getContent(), (string) $request->header('Stripe-Signature', ''));

        return new JsonResponse(['received' => true, 'outcome' => $this->webhooks->handle($event)]);
    }
}
