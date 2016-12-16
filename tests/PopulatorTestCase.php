<?php

namespace EloquentPopulator;

use EloquentPopulator\Models\Video;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Filesystem\ClassFinder;
use Illuminate\Filesystem\Filesystem;
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

        $this->app['config']->set('database.default', 'sqlite');
        $this->app['config']->set('database.connections.sqlite.database', ':memory:');

        $this->migrate();

        Relation::morphMap(['videos' => Video::class]);

        $this->populator = populator();
    }

    /**
     * Run package database migrations.
     *
     * @return void
     */
    protected function migrate()
    {
        $fileSystem = new Filesystem;
        $classFinder = new ClassFinder;

        foreach ($fileSystem->files(__DIR__ . '/Migrations') as $file) {
            $fileSystem->requireOnce($file);
            $migrationClass = $classFinder->findClass($file);

            (new $migrationClass)->up();
        }
    }

    protected function setUpLocales()
    {
        $this->app['config']->set('translatable.locales', ['en', 'es' => ['MX', 'CO']]);
        $this->app['config']->set('translatable.locale_separator', '-');

        // Reinstantiates Populator to test that it defaults to the configured locales.
        $this->populator = populator();
    }
}
