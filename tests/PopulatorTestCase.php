<?php

namespace EloquentPopulator;

use EloquentPopulator\Models\Video;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\TestCase;

abstract class PopulatorTestCase extends TestCase
{
    /**
     * @var Populator
     */
    protected $populator;

    /**
     * Creates the application.
     *
     * @return \Symfony\Component\HttpKernel\HttpKernelInterface
     */
    public function createApplication()
    {
        $app = require __DIR__ . '/../vendor/laravel/laravel/bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    /**
     * Setup the test environment.
     *
     * @return void
     */
    protected function setUp()
    {
        parent::setUp();

        $this->app['config']->set([
            'database.default'                     => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);

        $this->migrate();

        Relation::morphMap(['videos' => Video::class]);

        $this->populator = $this->app[Populator::class];
    }

    /**
     * Run package database migrations.
     *
     * @return void
     */
    protected function migrate()
    {
        $migrator = $this->app['migrator'];

        foreach ($migrator->getMigrationFiles(__DIR__ . '/Migrations') as $file) {
            require_once $file;

            ($migrator->resolve($migrator->getMigrationName($file)))->up();
        }
    }

    protected function setUpLocales()
    {
        $this->app['config']->set([
            'translatable.locales'          => ['en', 'es' => ['MX', 'CO']],
            'translatable.locale_separator' => '-',
        ]);

        // Reinstantiates Populator to test that it defaults to the configured locales.
        $this->populator = $this->app[Populator::class];
    }
}
