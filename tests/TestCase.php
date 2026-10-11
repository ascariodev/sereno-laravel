<?php

namespace Sereno\Laravel\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Sereno\Laravel\SerenoServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [SerenoServiceProvider::class];
    }
}
