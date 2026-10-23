<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Commerce\GrowthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class GrowthController extends Controller
{
    public function __construct(private readonly GrowthService $growth) {}

    public function activities(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->growth->activities($request->user())]);
    }

    public function openRoom(Request $request): JsonResponse
    {
        $data = $request->validate([
            'post_id' => ['nullable', 'uuid'],
            'visibility' => ['sometimes', 'in:private,public'],
        ]);

        return new JsonResponse(['data' => $this->growth->openRoom($request->user(), $data['post_id'] ?? null, $data['visibility'] ?? 'private')], 201);
    }

    public function joinRoom(Request $request, string $token): JsonResponse
    {
        return new JsonResponse(['data' => $this->growth->joinRoom($request->user(), $token)]);
    }

    public function voteRoom(Request $request, string $token): JsonResponse
    {
        $data = $request->validate(['choice' => ['required', 'string', 'max:40']]);

        return new JsonResponse(['data' => $this->growth->voteRoom($request->user(), $token, $data['choice'])]);
    }

    public function brief(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->growth->brief($request->user())]);
    }

    public function saveBrief(Request $request): JsonResponse
    {
        $data = $request->validate([
            'budget_minor' => ['required', 'integer', 'min:0'],
            'monthly_cap_minor' => ['required', 'integer', 'min:0'],
            'styles' => ['nullable', 'string', 'max:200'],
            'alerts_per_day' => ['sometimes', 'integer', 'min:0', 'max:5'],
            'allow_bid' => ['sometimes', 'boolean'],
            'allow_squad' => ['sometimes', 'boolean'],
        ]);

        return new JsonResponse(['data' => $this->growth->saveBrief(
            $request->user(),
            (int) $data['budget_minor'],
            (int) $data['monthly_cap_minor'],
            (string) ($data['styles'] ?? ''),
            (int) ($data['alerts_per_day'] ?? 3),
            (bool) ($data['allow_bid'] ?? false),
            (bool) ($data['allow_squad'] ?? false),
        )]);
    }

    public function propose(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->growth->propose($request->user())]);
    }

    public function undoAlert(Request $request, string $alert): JsonResponse
    {
        $this->growth->undoAlert($request->user(), $alert);

        return new JsonResponse(['data' => ['undone' => true]]);
    }

    public function shows(): JsonResponse
    {
        return new JsonResponse(['data' => $this->growth->shows()]);
    }

    public function remind(Request $request, string $show): JsonResponse
    {
        $this->growth->remind($request->user(), $show);

        return new JsonResponse(['data' => ['reminded' => true]]);
    }

    public function places(): JsonResponse
    {
        return new JsonResponse(['data' => $this->growth->places()]);
    }

    public function joinPlace(Request $request, string $place): JsonResponse
    {
        return new JsonResponse(['data' => $this->growth->joinPlace($request->user(), $place)]);
    }

    public function reputation(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->growth->reputation($request->user())]);
    }

    public function season(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->growth->season($request->user())]);
    }

    public function advanceQuest(Request $request, string $quest): JsonResponse
    {
        $this->growth->advanceQuest($request->user(), $quest);

        return new JsonResponse(['data' => $this->growth->season($request->user())]);
    }

    public function proof(string $product): JsonResponse
    {
        return new JsonResponse(['data' => $this->growth->proof($product)]);
    }

    public function haul(Request $request): JsonResponse
    {
        $data = $request->validate([
            'caption' => ['required', 'string', 'min:1', 'max:160'],
            'order_id' => ['required', 'uuid'],
        ]);
        $this->growth->publishHaul($request->user(), $data['order_id'], $data['caption']);

        return new JsonResponse(['data' => ['published' => true]], 201);
    }

    public function trust(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->growth->trust($request->user())]);
    }

    public function setLimit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'monthly_limit_minor' => ['nullable', 'integer', 'min:0'],
            'night_hold' => ['sometimes', 'boolean'],
        ]);
        $this->growth->setLimit($request->user(), $data['monthly_limit_minor'] ?? null, (bool) ($data['night_hold'] ?? true));

        return new JsonResponse(['data' => $this->growth->trust($request->user())]);
    }

    public function streaks(Request $request): JsonResponse
    {
        $data = $request->validate(['on' => ['required', 'boolean']]);
        $this->growth->setStreaks($request->user(), (bool) $data['on']);

        return new JsonResponse(['data' => $this->growth->trust($request->user())]);
    }

    public function mute(Request $request): JsonResponse
    {
        $data = $request->validate(['channel' => ['required', 'in:auction,squad,drop,show,agent']]);
        $this->growth->mute($request->user(), $data['channel']);

        return new JsonResponse(['data' => $this->growth->trust($request->user())]);
    }

    public function pause(Request $request): JsonResponse
    {
        $this->growth->pause($request->user());

        return new JsonResponse(['data' => $this->growth->trust($request->user())]);
    }

    public function unpause(Request $request): JsonResponse
    {
        $this->growth->unpause($request->user());

        return new JsonResponse(['data' => $this->growth->trust($request->user())]);
    }

    public function deleteAccount(Request $request): JsonResponse
    {
        $this->growth->requestDeletion($request->user());

        return new JsonResponse(['data' => ['deletion_requested' => true]]);
    }

    public function export(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->growth->export($request->user())]);
    }

    public function withdraw(Request $request, string $order): JsonResponse
    {
        $this->growth->withdraw($request->user(), $order);

        return new JsonResponse(['data' => ['withdrawn' => true]]);
    }

    public function age(Request $request): JsonResponse
    {
        $data = $request->validate(['confirmed' => ['required', 'accepted']]);
        if ($data['confirmed']) {
            DB::table('users')->where('id', $request->user()->id)->whereNull('age_assured_at')->update(['age_assured_at' => now()]);
        }

        return new JsonResponse(['data' => ['age_assured' => true]]);
    }

    public function catalog(Request $request): JsonResponse
    {
        $cursor = $request->query('cursor');

        return new JsonResponse($this->growth->catalog(is_string($cursor) && Str::isUuid($cursor) ? $cursor : null));
    }

    public function card(string $kind, string $id): JsonResponse
    {
        return new JsonResponse(['data' => $this->growth->card($kind, $id)]);
    }
}
