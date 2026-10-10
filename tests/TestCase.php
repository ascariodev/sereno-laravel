<?php

namespace Ascario\Sereno\Tests;

use Ascario\Sereno\SerenoServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [SerenoServiceProvider::class];
    }
}
