<?php

namespace EloquentPopulator;

use Carbon\Carbon;
use EloquentPopulator\Models\Product;
use EloquentPopulator\Models\ProductTranslation;
use EloquentPopulator\Models\User;
use Illuminate\Database\Eloquent;

class FactoryTest extends PopulatorTestCase
{
    protected function setUp()
    {
        parent::setUp();

        parent::setUpLocales();

        app(Eloquent\Factory::class)
            ->define(Product::class, function () {
                return ['price' => 5, 'name' => 'English name', 'name:es' => 'Spanish name'];
            })
            ->state(Product::class, 'new', function () {
                return ['created_at' => Carbon::today()];
            })
            ->state(Product::class, 'expensive', function () {
                return ['price' => 500];
            })
            ->define(ProductTranslation::class, function () {
                return ['name' => 'Factory definition name'];
            })
            ->state(ProductTranslation::class, 'Laravel', function () {
                return ['name' => 'Laravel'];
            })
            ->state(ProductTranslation::class, 'awesome', function () {
                return ['description' => 'This product is awesome'];
            });
    }

    public function testFactoryDefinitionsAreMerged()
    {
        $this->assertFactoryDefinitionsAreMerged($this->populator->make(Product::class));
    }

    public function testFactoryStateIsMerged()
    {
        $product = $this->populator->make(Product::class, 'new');

        $this->assertEquals(Carbon::today(), $product->created_at);

        $this->assertFactoryDefinitionsAreMerged($product);
    }

    protected function assertFactoryDefinitionsAreMerged(Product $product)
    {
        $this->assertEquals(5, $product->price);

        $this->assertEquals('English name', $product->name);
        $this->assertEquals('Spanish name', $product->{'name:es'});
        $this->assertEquals('Factory definition name', $product->{'name:es-MX'});
        $this->assertEquals('Factory definition name', $product->{'name:es-CO'});
    }

    public function testMultipleFactoryStatesAreMerged()
    {
        $product = $this->populator->add(Product::class)->states('expensive', 'new')
            ->translationStates('Laravel', 'awesome')->make();

        $this->assertEquals(500, $product->price);
        $this->assertEquals(Carbon::today(), $product->created_at);

        $this->assertEquals('English name', $product->name);
        $this->assertEquals('Laravel', $product->{'name:es-MX'});
        $this->assertEquals('This product is awesome', $product->description);
    }

    public function testStateWithoutDefitionIsMerged()
    {
        app(Eloquent\Factory::class)->state(User::class, 'email_state', function () {
            return ['email' => 'state@gmail.com'];
        });

        $user = $this->populator->make(User::class, 'email_state');

        $this->assertEquals('state@gmail.com', $user->email);
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testExceptionIsThrownifNonExistentStateIsApplied()
    {
        $this->populator->make(Product::class, 'foo');
    }
}
