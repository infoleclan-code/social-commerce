<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\ConnectionSource;
use App\Enums\ConnectionStatus;
use App\Exceptions\ApiException;
use App\Models\SocialConnection;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Friendship is mutual: the schema stores one row per unordered pair, so there is no one-way follow.
 * followers_count and following_count both stay the accepted-friend count.
 * Unblock deletes the row (the documented inverse of block). Declined can become pending again.
 */
final class SocialGraphService
{
    /** @return array<string, mixed> */
    public function request(User $viewer, string $otherId): array
    {
        $other = $this->other($viewer, $otherId);
        $row = SocialConnection::between($viewer, $other);
        if ($row !== null && $row->status === ConnectionStatus::Blocked) {
            throw ApiException::profileNotFound();
        }
        if ($row !== null && $row->status === ConnectionStatus::Accepted) {
            return $this->state($viewer, $other, $row);
        }
        if ($row !== null && $row->status === ConnectionStatus::Pending) {
            return $this->state($viewer, $other, $row);
        }
        if ($row !== null && $row->status === ConnectionStatus::Declined) {
            if ((string) $row->requester_id === (string) $viewer->id) {
                $row->transitionTo(ConnectionStatus::Pending, ['responded_at' => null]);
            } else {
                $row->delete();
                $row = $this->open($viewer, $other);
            }

            return $this->state($viewer, $other, $row->refresh());
        }
        $row = $this->open($viewer, $other);

        return $this->state($viewer, $other, $row);
    }

    /** @return array<string, mixed> */
    public function accept(User $viewer, string $otherId): array
    {
        $other = $this->other($viewer, $otherId);
        $row = $this->require($viewer, $other);
        if ($row->status !== ConnectionStatus::Pending || (string) $row->addressee_id !== (string) $viewer->id) {
            throw ApiException::friendRequestClosed();
        }
        $row->transitionTo(ConnectionStatus::Accepted, ['responded_at' => now()]);

        return $this->state($viewer, $other, $row);
    }

    /** @return array<string, mixed> */
    public function decline(User $viewer, string $otherId): array
    {
        $other = $this->other($viewer, $otherId);
        $row = $this->require($viewer, $other);
        if ($row->status !== ConnectionStatus::Pending || (string) $row->addressee_id !== (string) $viewer->id) {
            throw ApiException::friendRequestClosed();
        }
        $row->transitionTo(ConnectionStatus::Declined, ['responded_at' => now()]);

        return $this->state($viewer, $other, $row);
    }

    /** @return array<string, mixed> */
    public function block(User $viewer, string $otherId): array
    {
        $other = $this->other($viewer, $otherId);
        $row = SocialConnection::between($viewer, $other);
        if ($row === null) {
            $row = new SocialConnection([
                'addressee_id' => $other->id,
                'source' => ConnectionSource::Profile,
            ]);
            $row->forceFill([
                'requester_id' => $viewer->id,
                'status' => ConnectionStatus::Blocked,
                'blocked_by_user_id' => $viewer->id,
                'responded_at' => now(),
            ])->save();

            return $this->state($viewer, $other, $row);
        }
        if ($row->status === ConnectionStatus::Blocked) {
            if ((string) $row->blocked_by_user_id !== (string) $viewer->id) {
                throw ApiException::profileNotFound();
            }

            return $this->state($viewer, $other, $row);
        }
        $row->transitionTo(ConnectionStatus::Blocked, [
            'blocked_by_user_id' => $viewer->id,
            'responded_at' => now(),
        ]);

        return $this->state($viewer, $other, $row);
    }

    public function unblock(User $viewer, string $otherId): void
    {
        $other = $this->other($viewer, $otherId);
        $row = SocialConnection::between($viewer, $other);
        if ($row === null || $row->status !== ConnectionStatus::Blocked || (string) $row->blocked_by_user_id !== (string) $viewer->id) {
            throw ApiException::profileNotFound();
        }
        $row->delete();
    }

    public function label(User $viewer, User $user): string
    {
        if ($user->is($viewer)) {
            return 'self';
        }
        $row = SocialConnection::between($viewer, $user);
        if ($row === null || $row->status === ConnectionStatus::Declined) {
            return 'none';
        }
        if ($row->status === ConnectionStatus::Blocked) {
            return 'blocked';
        }
        if ($row->status === ConnectionStatus::Accepted) {
            return 'friends';
        }

        return (string) $row->requester_id === (string) $viewer->id ? 'pending_out' : 'pending_in';
    }

    private function other(User $viewer, string $otherId): User
    {
        if (! Str::isUuid($otherId) || $otherId === (string) $viewer->id) {
            throw ApiException::friendRequestClosed();
        }
        $other = User::query()->whereKey($otherId)->first();
        if ($other === null) {
            throw ApiException::profileNotFound();
        }

        return $other;
    }

    private function open(User $viewer, User $other): SocialConnection
    {
        $row = new SocialConnection([
            'addressee_id' => $other->id,
            'source' => ConnectionSource::Profile,
        ]);
        $row->forceFill(['requester_id' => $viewer->id])->save();

        return $row;
    }

    private function require(User $viewer, User $other): SocialConnection
    {
        return SocialConnection::between($viewer, $other) ?? throw ApiException::friendRequestClosed();
    }

    /** @return array<string, mixed> */
    private function state(User $viewer, User $other, SocialConnection $row): array
    {
        return [
            'user_id' => $other->id,
            'connection' => $this->label($viewer, $other),
            'status' => $row->status instanceof ConnectionStatus ? $row->status->value : (string) $row->status,
        ];
    }
}
