<?php

declare(strict_types=1);

namespace App\Exceptions;

use BackedEnum;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/** A status change refused by the status_transitions registry (SQLSTATE 23514). */
final class InvalidStatusTransition extends DomainException
{
    public static function for(Model $model, mixed $from, BackedEnum $to, ?Throwable $previous = null): self
    {
        $fromValue = $from instanceof BackedEnum ? $from->value : (string) $from;

        return new self(
            sprintf('%s %s cannot move from "%s" to "%s".', class_basename($model), $model->getKey(), $fromValue, $to->value),
            0,
            $previous,
        );
    }
}
