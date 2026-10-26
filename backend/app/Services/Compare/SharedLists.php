<?php

declare(strict_types=1);

namespace App\Services\Compare;

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** A list friends can open, vote on, and tally pledges against. No payment is processed. */
final class SharedLists
{
    /** @return array<string, mixed> */
    public function create(User $user, string $title, string $currency): array
    {
        $id = (string) Str::uuid7();
        $now = now();
        DB::table('shared_lists')->insert([
            'id' => $id,
            'owner_id' => $user->id,
            'title' => $title,
            'token' => bin2hex(random_bytes(8)),
            'currency' => $currency,
            'created_at' => $now,
        ]);
        DB::table('shared_list_members')->insert([
            'list_id' => $id,
            'user_id' => $user->id,
            'role' => 'owner',
            'created_at' => $now,
        ]);

        return $this->present($user, $id);
    }

    /** @return list<array<string, mixed>> */
    public function mine(User $user): array
    {
        $ids = DB::table('shared_list_members')->where('user_id', $user->id)->orderByDesc('created_at')->pluck('list_id');
        $lists = [];
        foreach ($ids as $id) {
            $lists[] = $this->present($user, (string) $id);
        }

        return $lists;
    }

    /** @return array<string, mixed> */
    public function show(User $user, string $id): array
    {
        $this->member($user, $id);

        return $this->present($user, $id);
    }

    /** @return array<string, mixed> */
    public function join(User $user, string $token): array
    {
        $list = DB::table('shared_lists')->where('token', $token)->first();
        if ($list === null) {
            throw ApiException::listNotFound();
        }
        $existing = DB::table('shared_list_members')->where('list_id', $list->id)->where('user_id', $user->id)->first();
        if ($existing === null) {
            DB::table('shared_list_members')->insert([
                'list_id' => $list->id,
                'user_id' => $user->id,
                'role' => 'member',
                'created_at' => now(),
            ]);
        }

        return $this->present($user, (string) $list->id);
    }

    /** @return array<string, mixed> */
    public function addItem(User $user, string $id, string $query, ?string $brand, ?string $size): array
    {
        $this->member($user, $id);
        DB::table('shared_list_items')->insert([
            'id' => (string) Str::uuid7(),
            'list_id' => $id,
            'query' => $query,
            'brand' => $brand,
            'size' => $size,
            'created_at' => now(),
        ]);

        return $this->present($user, $id);
    }

    /** @return array<string, mixed> */
    public function vote(User $user, string $id, string $item, string $vote): array
    {
        $this->member($user, $id);
        $row = DB::table('shared_list_items')->where('id', $item)->where('list_id', $id)->first();
        if ($row === null) {
            throw ApiException::listNotFound();
        }
        DB::table('shared_list_votes')->updateOrInsert(
            ['item_id' => $item, 'user_id' => $user->id],
            ['vote' => $vote, 'created_at' => now()],
        );

        return $this->present($user, $id);
    }

    /** @return array<string, mixed> */
    public function pledge(User $user, string $id, int $amount, ?string $note): array
    {
        $list = $this->member($user, $id);
        DB::table('shared_list_pledges')->insert([
            'id' => (string) Str::uuid7(),
            'list_id' => $id,
            'user_id' => $user->id,
            'amount_minor' => $amount,
            'currency' => (string) $list->currency,
            'note' => $note,
            'created_at' => now(),
        ]);

        return $this->present($user, $id);
    }

    private function member(User $user, string $id): object
    {
        if (! Str::isUuid($id)) {
            throw ApiException::listNotFound();
        }
        $list = DB::table('shared_lists')->where('id', $id)->first();
        $member = $list === null ? null : DB::table('shared_list_members')->where('list_id', $id)->where('user_id', $user->id)->first();
        if ($list === null || $member === null) {
            throw ApiException::listNotFound();
        }

        return $list;
    }

    /** @return array<string, mixed> */
    private function present(User $user, string $id): array
    {
        $list = DB::table('shared_lists')->where('id', $id)->first();
        $role = (string) DB::table('shared_list_members')->where('list_id', $id)->where('user_id', $user->id)->value('role');
        $items = [];
        foreach (DB::table('shared_list_items')->where('list_id', $id)->orderBy('created_at')->get() as $item) {
            $votes = DB::table('shared_list_votes')->where('item_id', $item->id)->get();
            $mine = $votes->firstWhere('user_id', $user->id);
            $items[] = [
                'id' => (string) $item->id,
                'query' => (string) $item->query,
                'brand' => $item->brand !== null ? (string) $item->brand : null,
                'size' => $item->size !== null ? (string) $item->size : null,
                'votes_up' => $votes->where('vote', 'up')->count(),
                'votes_down' => $votes->where('vote', 'down')->count(),
                'mine' => $mine !== null ? (string) $mine->vote : null,
            ];
        }
        $pledges = [];
        $total = 0;
        $pledgeRows = DB::table('shared_list_pledges')
            ->join('users', 'users.id', '=', 'shared_list_pledges.user_id')
            ->where('shared_list_pledges.list_id', $id)
            ->orderBy('shared_list_pledges.created_at')
            ->get(['shared_list_pledges.amount_minor', 'shared_list_pledges.currency', 'shared_list_pledges.note', 'shared_list_pledges.user_id', 'users.username']);
        foreach ($pledgeRows as $pledge) {
            $total += (int) $pledge->amount_minor;
            $pledges[] = [
                'username' => (string) $pledge->username,
                'amount_minor' => (int) $pledge->amount_minor,
                'currency' => (string) $pledge->currency,
                'note' => $pledge->note !== null ? (string) $pledge->note : null,
                'mine' => (string) $pledge->user_id === (string) $user->id,
            ];
        }

        return [
            'id' => (string) $list->id,
            'title' => (string) $list->title,
            'token' => (string) $list->token,
            'currency' => (string) $list->currency,
            'role' => $role,
            'items' => $items,
            'pledges' => $pledges,
            'pledge_total_minor' => $total,
            'payment' => 'not_processed',
        ];
    }
}
