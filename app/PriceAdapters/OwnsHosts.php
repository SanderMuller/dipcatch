<?php declare(strict_types=1);

namespace App\PriceAdapters;

/**
 * An adapter written for particular shops. On one of its own hosts its
 * verdict is final: {@see AdapterResolver} turns a `skip` there into a
 * failure, so a generic reader never prices a page its own adapter could
 * not read.
 */
interface OwnsHosts
{
    /**
     * Normalized hosts, without `www.`; subdomains match too.
     *
     * @return list<string>
     */
    public function ownedHosts(): array;
}
