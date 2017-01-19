<?php

namespace EloquentPopulator;

use EloquentPopulator\Models\User;
use Illuminate\Database\Eloquent;

class HelperTest extends PopulatorTestCase
{
    protected function setUp()
    {
        parent::setUp();

        $this->app[Eloquent\Factory::class]->state(User::class, 'email', function () {
            return ['email' => 'State email'];
        });
    }

    public function testNoArguments()
    {
        $this->assertInstanceOf(Populator::class, populator());
    }

    public function testClass()
    {
        $this->assertInstanceOf(User::class, populator(User::class)->make());
    }

    public function testClassState()
    {
        $user = populator(User::class, 'email')->make();

        $this->assertSame('State email', $user->email);
    }

    public function testClassQuantity() {
        $users = populator(User::class, 5)->make();

        $this->assertCount(5, $users);
    }

    public function testClassStateQuantity()
    {
        $users = populator(User::class, 'email', 5)->make();

        $this->assertSame('State email', $users[0]->email);
        $this->assertCount(5, $users);
    }

    public function testPassingCustomAttributesToMake()
    {
        $user = populator(User::class)->make(['email' => 'Overridden email']);

        $this->assertSame('Overridden email', $user->email);
    }

    public function testPassingCustomAttributesToCreate()
    {
        $user = populator(User::class)->create(['email' => 'Overridden email']);

        $this->assertSame('Overridden email', $user->email);
    }
}
