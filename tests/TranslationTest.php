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

        $this->assertSame(['en', 'es', 'es-MX', 'es-CO'], $product->translations->pluck('locale')->all());
    }

    public function testExecuteDoesntMakeNullableColumnsOptional()
    {
        $this->assertTrue($this->populator->make(Product::class, 50)->every('description', '!==', null));
    }

    public function testSeedMakesNullableColumnsOptional()
    {
        $this->populator->add(Product::class, 50)->seed();

        $this->assertTrue(ProductTranslation::whereNull('description')->exists());
        $this->assertTrue(ProductTranslation::whereNotNull('description')->exists());
    }

    public function testTranslateIn()
    {
        $this->populator
            ->translateIn(['en'])
            ->add(Product::class)->translateIn(['en', 'es-MX',])
            ->add(Role::class);

        $models = $this->populator->execute();

        $this->assertSame(['en', 'es-MX'], $models[Product::class]->translations()->pluck('locale')->all());

        $this->assertSame(['en'], $models[Role::class]->translations()->pluck('locale')->all());
    }

    public function testEmptyModelPopulatorLocalesAndNonEmptyPopulatorLocales()
    {
        $product = $this->populator->add(Product::class)->translateIn([])->make();

        $this->assertSame([], $product->translations->pluck('locale')->all());
    }

    public function testCustomTranslationAttributes_forOneLocale()
    {
        $product = $this->populator->make(Product::class, ['name' => 'English name', 'name:es' => 'Spanish name']);

        $this->assertSame('English name', $product->name);
        $this->assertSame('Spanish name', $product->{'name:es'});
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

        $this->assertSame(1, $product->name);
        $this->assertSame(1, $product->{'name:es'});
        $this->assertSame($product->name, $product->description);
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
            ->add(User::class, ['company_id' => null])
            ->seed();

        $this->assertCount(2 + (int)ceil(150 * 4 / 500) + 2, $this->app['db']->getQueryLog());

        $this->assertTrue(ProductTranslation::where('product_id', 2)->exists());

        $this->assertTrue(User::exists());
    }
}
