<?php

namespace EloquentPopulator;

use EloquentPopulator\Models\Product;
use EloquentPopulator\Models\ProductTranslation;
use EloquentPopulator\Models\Role;
use EloquentPopulator\Models\User;

class TranslationTest extends PopulatorTestCase
{
    protected function setUp()
    {
        parent::setUp();

        parent::setUpLocales();
    }

    public function testTranslation()
    {
        // This also tests that the translation's columns are automatically populated,
        // because if they weren't an exception would be thrown since they have no default value.
        $product = $this->populator->make(Product::class);

        $this->assertEquals(['en', 'es', 'es-MX', 'es-CO'], $product->translations->pluck('locale')->all());
    }

    public function testTranslateIn()
    {
        $this->populator
            ->translateIn(['en'])
            ->add(Product::class)->translateIn(['en', 'es-MX',])
            ->add(Role::class);

        $models = $this->populator->execute();

        $this->assertEquals(['en', 'es-MX'], $models[Product::class]->translations()->pluck('locale')->all());

        $this->assertEquals(['en'], $models[Role::class]->translations()->pluck('locale')->all());
    }

    public function testCustomTranslationAttributes_forOneLocale()
    {
        $product = $this->populator->make(Product::class, ['name' => 'English name', 'name:es' => 'Spanish name']);

        $this->assertEquals('English name', $product->name);
        $this->assertEquals('Spanish name', $product->{'name:es'});
        $this->assertNotEquals('English name', $product->{'name:es-MX'});
    }


    public function testCustomTranslationAttributes_forAllLocales()
    {
        $this->populator->add(User::class);

        $product = $this->populator->add(Product::class)->translationAttributes([
            'name'        => function (ProductTranslation $model, $insertedPKs) {
                return $insertedPKs[User::class][0];
            },
            'description' => function ($model) {
                return $model->name;
            },
        ])->create();

        $this->assertEquals(1, $product->name);
        $this->assertEquals(1, $product->{'name:es'});
        $this->assertEquals($product->name, $product->description);
    }

    public function testMakeDoesntSetForeignKeysOfTranslations()
    {
        $product = $this->populator->make(Product::class);

        $this->assertNull($product->translations[0]->product_id);
    }

    public function testSeedRunsOneInsertPer500TranslationRows()
    {
        $this->app['db']->enableQueryLog();

        // Tests that models with and without translations added together are all inserted.
        $this->populator
            ->add(Product::class, 150)
            ->add(User::class)
            ->seed();

        $this->assertCount(2 + (int)ceil(150 * 4 / 500) + 2, $this->app['db']->getQueryLog());

        $this->assertTrue(ProductTranslation::where('product_id', 2)->exists());

        $this->assertTrue(User::exists());
    }
}
