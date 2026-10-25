<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $connection = $app->make('db')->connection();
        if ($connection->getDriverName() !== 'mysql' || $connection->getDatabaseName() !== 'office_converters_test') {
            throw new \RuntimeException('Tests require the separate office_converters_test MySQL database. Refusing to run.');
        }

        return $app;
    }
}
