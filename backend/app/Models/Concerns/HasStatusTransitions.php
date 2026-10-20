<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Exceptions\InvalidStatusTransition;
use BackedEnum;
use Illuminate\Database\QueryException;

/**
 * Status changes for tables guarded by the `status_transitions` registry.
 *
 * The database is the single source of truth for which moves are legal
 * (see enforce_status_transition() in 00_foundation.sql). This trait only
 * translates a refused move into a domain exception and restores the model.
 */
trait HasStatusTransitions
{
    /**
     * @param  array<string, mixed>  $attributes  Extra columns written in the same UPDATE
     *                                            (e.g. ['closed_at' => now()]).
     *
     * @throws InvalidStatusTransition
     */
    public function transitionTo(BackedEnum $status, array $attributes = []): static
    {
        $from = $this->getAttribute('status');
        $before = $this->getAttributes();

        try {
            $this->forceFill(['status' => $status] + $attributes)->save();
        } catch (QueryException $e) {
            $this->setRawAttributes($before);   // undo the in-memory change, keep earlier unsaved edits

            if ($e->getCode() === '23514' && str_contains($e->getMessage(), 'status transition')) {
                throw InvalidStatusTransition::for($this, $from, $status, $e);
            }

            throw $e;
        }

        return $this;
    }
}
