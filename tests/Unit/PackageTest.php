<?php

namespace Ascario\Sereno\Tests\Unit;

use Ascario\Sereno\Tests\TestCase;

class PackageTest extends TestCase
{
    public function test_the_laravel_application_boots(): void
    {
        $this->assertSame('13', explode('.', $this->app->version())[0]);
    }
}
