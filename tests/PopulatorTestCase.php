<?php

namespace GuidoCella\EloquentPopulator;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Schema;

abstract class PopulatorTestCase extends TestCase
{
    public function createApplication()
    {
        $app = require __DIR__.'/../vendor/laravel/laravel/bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            // 'database.default' => 'mariadb',
            // 'database.connections.mariadb.host' => 'localhost',
            // 'database.connections.mariadb.database' => 'populator',
            'multilingual.locales' => ['en', 'es'],
        ]);

        $this->migrate();

        Factory::guessFactoryNamesUsing(fn ($modelClass) => 'GuidoCella\EloquentPopulator\\Factories\\'.class_basename($modelClass).'Factory');
    }

    protected function migrate()
    {
        Schema::dropAllTables();

        $migrator = $this->app['migrator'];

        foreach ($migrator->getMigrationFiles(__DIR__.'/Migrations') as $file) {
            require_once $file;

            $migrator->resolve($migrator->getMigrationName($file))->up();
        }
    }
}
