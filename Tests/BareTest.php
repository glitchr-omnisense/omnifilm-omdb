<?php

namespace Omnifilm\Omdb\Tests;

use Omnifilm\Testing\BareTestCase;
use Omnifilm\Omdb\OmdbProvider;

/** omnifilm/omdb's classes load with omnisense/omnifilm and Symfony's HTTP client contracts alone. */
final class BareTest extends BareTestCase
{
    protected static function entryClass(): string
    {
        return OmdbProvider::class;
    }

    protected static function requiredPackages(): array
    {
        return ['symfony/http-client-contracts'];
    }
}
