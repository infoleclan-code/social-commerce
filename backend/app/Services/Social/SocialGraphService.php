<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\ConnectionSource;
use App\Enums\ConnectionStatus;
use App\Exceptions\ApiException;
use App\Models\SocialConnection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Friendship is mutual. Follows are one-way and counted separately. A block removes both follows
 * and hides the profile. Unblock deletes the connection row. Declined can become pending again.
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
        $this->dropFollows($viewer, $other);
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

    /** @return array{following: bool, followers: int, following_count: int} */
    public function follow(User $viewer, string $otherId): array
    {
        $other = $this->other($viewer, $otherId);
        if ($this->blockedPair($viewer, $other)) {
            throw ApiException::profileNotFound();
        }
        DB::table('follows')->insertOrIgnore([
            'follower_id' => $viewer->id,
            'followee_id' => $other->id,
            'created_at' => now(),
        ]);

        return $this->followState($viewer, $other);
    }

    /** @return array{following: bool, followers: int, following_count: int} */
    public function unfollow(User $viewer, string $otherId): array
    {
        $other = $this->other($viewer, $otherId);
        DB::table('follows')->where('follower_id', $viewer->id)->where('followee_id', $other->id)->delete();

        return $this->followState($viewer, $other);
    }

    public function follows(User $viewer, User $user): bool
    {
        if ($user->is($viewer)) {
            return false;
        }

        return DB::table('follows')->where('follower_id', $viewer->id)->where('followee_id', $user->id)->exists();
    }

    public function followerCount(User $user): int
    {
        return (int) DB::table('follows')->where('followee_id', $user->id)->count();
    }

    public function followingCount(User $user): int
    {
        return (int) DB::table('follows')->where('follower_id', $user->id)->count();
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

    /** @return array{following: bool, followers: int, following_count: int} */
    private function followState(User $viewer, User $other): array
    {
        return [
            'following' => $this->follows($viewer, $other),
            'followers' => $this->followerCount($other),
            'following_count' => $this->followingCount($viewer),
        ];
    }

    private function dropFollows(User $viewer, User $other): void
    {
        DB::table('follows')->where('follower_id', $viewer->id)->where('followee_id', $other->id)->delete();
        DB::table('follows')->where('follower_id', $other->id)->where('followee_id', $viewer->id)->delete();
    }

    private function blockedPair(User $viewer, User $other): bool
    {
        $row = SocialConnection::between($viewer, $other);

        return $row !== null && $row->status === ConnectionStatus::Blocked;
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
