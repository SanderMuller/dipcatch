<?php declare(strict_types=1);

namespace App\Services\BolApi;

use RuntimeException;

/** The bol.com Catalog API could not answer: down, refused, or rate limited. */
final class BolApiFailed extends RuntimeException
{
    public function rateLimited(): bool
    {
        return $this->getCode() === 429;
    }
}
