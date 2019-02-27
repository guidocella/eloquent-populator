<?php

namespace EloquentPopulator;

use EloquentPopulator\Models\Planet;
use EloquentPopulator\Models\PlanetTranslation;
use EloquentPopulator\Models\User;

class MultilingualTest extends PopulatorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('multilingual.locales', ['en', 'es']);

        // Reinstantiates Populator to test that it defaults to the configured locales.
        $this->populator = $this->app[Populator::class];
    }

    public function testTranslatableColumns()
    {
        $planet = $this->populator->make(Planet::class);

        $this->assertTrue(strlen($planet->name) > 1);
        $this->assertNotNull(strlen($planet->nameTranslations->es) > 1);

        $this->assertNotNull(strlen($planet->order) > 1);
        $this->assertNotNull(strlen($planet->orderTranslations->es) > 1);
    }

    public function testPopulatorTranslateIn()
    {
        $planet = $this->populator->translateIn(['en'])->make(Planet::class);

        $this->assertTrue(strlen($planet->name) > 1);
        $this->assertSame('', $planet->nameTranslations->es);
    }

    public function testModelPopulatorTranslateIn()
    {
        $planet = $this->populator->add(Planet::class)->translateIn(['en'])->make();

        $this->assertTrue(strlen($planet->name) > 1);
        $this->assertSame('', $planet->nameTranslations->es);
    }

    public function testNoLocales()
    {
        $this->assertSame('', $this->populator->translateIn([])->make(Planet::class)->name);
    }

    public function testCustomTranslationAttributes_forOneLocale()
    {
        $product = $this->populator->make(Planet::class, ['name->en' => 'English name']);

        $this->assertSame('English name', $product->name);
        $this->assertNotSame('', $product->nameTranslations->es);
    }

    public function testCustomTranslatedAttribute_forAllLocales()
    {
        $this->populator->add(User::class);

        $planet = $this->populator->add(Planet::class)->translatedAttributes([
            'name'  => 'custom name',
            'order' => 'custom order',
        ])->make();

        $this->assertSame('custom name', $planet->name);
        $this->assertSame('custom name', $planet->nameTranslations->es);
        $this->assertSame('custom order', $planet->order);
    }
}
