<?php

namespace EloquentPopulator;

use Carbon\Carbon;
use EloquentPopulator\Models\Club;
use EloquentPopulator\Models\Post;
use EloquentPopulator\Models\User;
use EloquentPopulator\Models\Video;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class PopulatorTest extends PopulatorTestCase
{
    public function testExecuteCreatesAndReturnsModels()
    {
        $this->populator
            ->add(User::class, 1)
            ->add(Post::class, 5);

        $models = $this->populator->execute();

        $this->assertInstanceOf(Model::class, $models[User::class]);
        $this->assertInstanceOf(Collection::class, $models[Post::class]);
        $this->assertCount(5, $models[Post::class]);

        $this->assertEquals(1, User::count());
        $this->assertEquals(5, Post::count());
    }

    public function testCreateOne()
    {
        $user = $this->populator->create(User::class);

        $this->assertInstanceOf(User::class, $user);

        $this->assertEquals(1, User::count());
    }

    public function testCreateMany()
    {
        $users = $this->populator->create(User::class, 5);

        $this->assertCount(5, $users);

        $this->assertEquals(5, User::count());
    }

    public function testMakeOne()
    {
        $user = $this->populator->make(User::class);

        $this->assertInstanceOf(User::class, $user);

        $this->assertFalse(User::exists());
    }

    public function testMakeMany()
    {
        $users = $this->populator->make(User::class, 5);

        $this->assertCount(5, $users);

        $this->assertFalse(User::exists());
    }

    public function testColumnTypeGuesser()
    {
        $user = $this->populator->make(User::class);

        $this->assertInternalType('bool', $user->boolean);
        $this->assertInternalType('float', $user->decimal);
        $this->assertInternalType('int', $user->smallint);
        $this->assertInternalType('int', $user->integer);
        $this->assertInternalType('int', $user->bigint);
        $this->assertInternalType('float', $user->float);
        $this->assertTrue(is_string($user->string) && strlen($user->string));
        $this->assertTrue(is_string($user->text) && strlen($user->text));
        $this->assertInstanceOf(Carbon::class, $user->datetime);
        $this->assertInstanceOf(Carbon::class, $user->date);
        $this->assertInstanceOf(Carbon::class, $user->time);
        $this->assertInstanceOf(Carbon::class, $user->timestamp);
    }

    public function testColumnNameGuesser()
    {
        $user = $this->populator->make(User::class);

        $emailPattern = '/^(?!(?:(?:\\x22?\\x5C[\\x00-\\x7E]\\x22?)|(?:\\x22?[^\\x5C\\x22]\\x22?)){255,})(?!(?:(?:\\x22?\\x5C[\\x00-\\x7E]\\x22?)|(?:\\x22?[^\\x5C\\x22]\\x22?)){65,}@)(?:(?:[\\x21\\x23-\\x27\\x2A\\x2B\\x2D\\x2F-\\x39\\x3D\\x3F\\x5E-\\x7E]+)|(?:\\x22(?:[\\x01-\\x08\\x0B\\x0C\\x0E-\\x1F\\x21\\x23-\\x5B\\x5D-\\x7F]|(?:\\x5C[\\x00-\\x7F]))*\\x22))(?:\\.(?:(?:[\\x21\\x23-\\x27\\x2A\\x2B\\x2D\\x2F-\\x39\\x3D\\x3F\\x5E-\\x7E]+)|(?:\\x22(?:[\\x01-\\x08\\x0B\\x0C\\x0E-\\x1F\\x21\\x23-\\x5B\\x5D-\\x7F]|(?:\\x5C[\\x00-\\x7F]))*\\x22)))*@(?:(?:(?!.*[^.]{64,})(?:(?:(?:xn--)?[a-z0-9]+(?:-+[a-z0-9]+)*\\.){1,126}){1,}(?:(?:[a-z][a-z0-9]*)|(?:(?:xn--)[a-z0-9]+))(?:-+[a-z0-9]+)*)|(?:\\[(?:(?:IPv6:(?:(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){7})|(?:(?!(?:.*[a-f0-9][:\\]]){7,})(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,5})?::(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,5})?)))|(?:(?:IPv6:(?:(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){5}:)|(?:(?!(?:.*[a-f0-9]:){5,})(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,3})?::(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,3}:)?)))?(?:(?:25[0-5])|(?:2[0-4][0-9])|(?:1[0-9]{2})|(?:[1-9]?[0-9]))(?:\\.(?:(?:25[0-5])|(?:2[0-4][0-9])|(?:1[0-9]{2})|(?:[1-9]?[0-9]))){3}))\\]))$/iD';

        $this->assertRegExp($emailPattern, $user->email);
    }

    public function testCustomAttributesPassedToAdd()
    {
        $this->populator->add(Video::class);

        $user = $this->populator->make(User::class, 1, [
            // Tests that the right arguments are passed the closures.
            'integer' => function (User $model, $insertedPKs) {
                return $insertedPKs[Video::class][0];
            },
            'bigint'  => function ($model) {
                return $model->integer;
            },
        ]);

        $this->assertEquals(1, $user->integer);
        $this->assertEquals($user->integer, $user->bigint);
    }

    public function testModifiers()
    {
        $mock = $this->getMockBuilder('stdClass')->setMethods(['stringMethod', 'intMethod'])->getMock();

        $mock->expects($this->once())->method('stringMethod')->with($this->equalTo('Overridden string'));
        $mock->expects($this->once())->method('intMethod')->with($this->equalTo(1));

        $this->populator->add(Video::class);

        $this->populator->make(User::class, 1, ['string' => 'Overridden string'], [
            function ($model) use ($mock) {
                $mock->stringMethod($model->string);
            },
            function ($model, $insertedPKs) use ($mock) {
                $mock->intMethod($insertedPKs[Video::class][0]);
            },
        ]);
    }

    public function testMakeDoesntSetTimestamps()
    {
        $post = $this->populator->make(Post::class);

        $this->assertNull($post->created_at);
        $this->assertNull($post->updated_at);
    }

    public function testDeletedAtIsNotSet()
    {
        $club = $this->populator->make(Club::class);

        $this->assertNull($club->deleted_at);
    }

    public function testSeedRunsOneInsertPer500Rows()
    {
        // The first two query insert the rows while the third fetches the IDs that were just inserted.
        // Doctrine's queries to introspect the tables aren't logged by Laravel.

        $this->app['db']->enableQueryLog();

        $this->populator->add(User::class, 501);

        $this->populator->seed();

        $this->assertCount(3, $this->app['db']->getQueryLog());
    }

    public function testSeedReturnsPrimaryKeys()
    {
        $this->populator
            ->add(User::class)
            ->add(Video::class, 2);

        $this->assertEquals(
            [
                User::class  => [1],
                Video::class => [2, 1],
            ],
            $this->populator->seed()
        );
    }
}
