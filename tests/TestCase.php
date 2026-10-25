<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $connection = $app->make('db')->connection();
        $isDedicatedMysqlTestDatabase = $connection->getDriverName() === 'mysql'
            && $connection->getDatabaseName() === 'office_converters_test';
        $isDesktopSqliteTestDatabase = $connection->getDriverName() === 'sqlite'
            && $app['config']->get('app.desktop_mode')
            && $connection->getDatabaseName() === ':memory:';
        if (! $isDedicatedMysqlTestDatabase && ! $isDesktopSqliteTestDatabase) {
            throw new \RuntimeException('Tests require the separate MySQL test database or the isolated desktop SQLite test database. Refusing to run.');
        }

        return $app;
    }
}
