<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('compiles Phase 2 schema identifiers within the MySQL 64 character limit', function (): void {
    $originalConnection = DB::getDefaultConnection();
    $originalSchema = Schema::getFacadeRoot();
    config(['database.connections.consumer_schema_preview' => [
        'driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'schema_preview',
        'username' => 'unused', 'password' => '', 'prefix' => '', 'version' => '8.4.0',
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
    ]]);
    DB::setDefaultConnection('consumer_schema_preview');
    Schema::swap(DB::connection()->getSchemaBuilder());

    try {
        $queries = DB::pretend(function (): void {
            foreach (glob(database_path('migrations/2026_10_26_110*_create_consumer_*.php')) as $path) {
                (require $path)->up();
            }
        });
        $violations = [];
        foreach ($queries as $query) {
            preg_match_all('/`([^`]+)`|CREATE TRIGGER ([a-z_]+)/i', $query['query'], $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $name = $match[1] !== '' ? $match[1] : $match[2];
                if (strlen($name) > 64) {
                    $violations[$name] = strlen($name);
                }
            }
        }

        expect($queries)->not->toBeEmpty();
        expect($violations)->toBe([]);
    } finally {
        DB::setDefaultConnection($originalConnection);
        Schema::swap($originalSchema);
        DB::purge('consumer_schema_preview');
    }
});
