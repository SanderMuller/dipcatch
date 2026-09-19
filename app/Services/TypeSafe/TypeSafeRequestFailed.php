<?php declare(strict_types=1);

namespace App\Services\TypeSafe;

use RuntimeException;
use Throwable;

final class TypeSafeRequestFailed extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** A rejected key: every later request with the same key fails the same way. */
    public function isRejectedKey(): bool
    {
        return $this->status === 401;
    }
}
