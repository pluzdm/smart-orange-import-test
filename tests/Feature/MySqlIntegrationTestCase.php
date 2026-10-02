<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

abstract class MySqlIntegrationTestCase extends TestCase
{
    private const TEST_DATABASE = 'applications_import_test';

    protected function setUp(): void
    {
        parent::setUp();

        $mysql = config('database.connections.mysql');
        $this->assertSame('mysql', $mysql['driver']);
        $this->assertNotSame(self::TEST_DATABASE, $mysql['database']);

        config([
            'database.connections.import_test' => array_replace($mysql, [
                'database' => self::TEST_DATABASE,
                'url' => null,
            ]),
            'database.default' => 'import_test',
        ]);

        DB::purge('import_test');
        $this->assertTestDatabase();
        Artisan::call('migrate', ['--database' => 'import_test', '--force' => true]);

        $this->assertTestDatabase();
        DB::connection('import_test')->table('applications')->delete();
    }

    protected function tearDown(): void
    {
        DB::disconnect('import_test');
        parent::tearDown();
    }

    private function assertTestDatabase(): void
    {
        $connection = DB::connection('import_test');
        $this->assertSame(self::TEST_DATABASE, $connection->getDatabaseName());
        $this->assertSame(self::TEST_DATABASE, $connection->selectOne('SELECT DATABASE() AS database_name')->database_name);
    }
}
