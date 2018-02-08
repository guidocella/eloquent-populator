<?php

namespace EloquentPopulator;

use EloquentPopulator\Models\Club;
use EloquentPopulator\Models\Post;
use EloquentPopulator\Models\Role;
use EloquentPopulator\Models\Tag;
use EloquentPopulator\Models\User;
use EloquentPopulator\Models\Video;

class PivotTest extends PopulatorTestCase
{
    public function testExecuteDoesntMakeNullableExtraPivotColumnsOptional()
    {
        $this->populator->add(Role::class, 10)->add(User::class, 10)->execute();

        $this->assertFalse($this->app['db']->table('role_user')->whereNull('expires_at')->exists());
    }

    public function testSeedMakesNullableExtraPivotColumnsOptional()
    {
        $this->populator->add(Role::class, 10)->add(User::class, 10)->seed();

        $this->assertTrue($this->app['db']->table('role_user')->whereNull('expires_at')->exists());
        $this->assertTrue($this->app['db']->table('role_user')->whereNotNull('expires_at')->exists());
    }

    public function testSeedAssociatesRandomQuantityOfManyToManyRelated()
    {
        // Will fail if the count of the attached records happens to be 0 or 200.

        $this->populator
            ->add(Role::class, 200)
            // Adds 2 users in case something wrong happens with multiple ones.
            ->add(User::class, 2);

        $user = User::find($this->populator->seed()[User::class][0]);

        $this->assertThat(
            $user->roles()->count(),
            $this->logicalAnd(
                $this->greaterThan(0),
                $this->lessThan(200)
            )
        );
    }

    public function testExecuteAssociatesAllManyToManyRelated()
    {
        $user = $this->populator
            ->add(Role::class, 5)
            ->create(User::class);

        $this->assertSame(5, $user->roles()->count());
    }

    public function testMorphToManyIsAssociated()
    {
        // Will fail if one of the attached quantities happens to be 0.

        $tag = $this->populator
                   ->add(Post::class, 100)
                   ->add(Video::class, 100)
                   ->create(Tag::class, 2)[1];

        $this->assertTrue($tag->posts()->exists());
        $this->assertTrue($tag->videos()->exists());
    }

    public function testAttachQuantities_withExecute()
    {
        $this->populator
            ->add(Role::class, 20)
            ->add(User::class)->attachQuantities([Role::class => 10]);

        $user = $this->populator->execute()[User::class];

        $this->assertSame(10, $user->roles()->count());
    }

    public function testAttachQuantities_withCreate()
    {
        $user = $this->populator
            ->add(Role::class, 20)
            ->add(User::class)->attachQuantities([Role::class => 10])->create();

        $this->assertSame(10, $user->roles()->count());
    }

    public function testCustomPivotAttributes()
    {
        $user = $this->populator
            ->add(Role::class)
            ->add(User::class)->attachQuantities([Role::class => 1])->pivotAttributes([
                Role::class => [
                    'expires_at' => function (User $model, $insertedPKs) {
                        return '2000-01-' . $insertedPKs[Role::class][0] . $model->id;
                    },
                ],
            ])->create();

        $this->assertSame('2000-01-11', $user->roles[0]->pivot->expires_at);
    }

    public function testBelongsToAndBelongsToManyOfSameModel_withExecute()
    {
        $this->populator->create(User::class);
        $this->assertTrue(true);
    }

    public function testBelongsToAndBelongsToManyOfSameModel_withSeed()
    {
        $this->populator->add(User::class)->seed();
        $this->assertTrue(true);
    }

    public function testSeedRunsOneInsertPer500PivotRows()
    {
        $this->app['db']->enableQueryLog();

        $this->populator
            ->add(Role::class, 50)
            ->add(User::class, 50, ['company_id' => null])->attachQuantities([Role::class => 50])
            ->seed();

        // Only the models require an extra query to fetch their IDs.
        $this->assertCount(2 + 2 + (50 * 50 / 500), $this->app['db']->getQueryLog());
    }

    public function testSeedAttachesMultipleBelongsToMany()
    {
        $this->populator
            ->add(Role::class)
            ->add(Club::class)
            ->add(User::class)->attachQuantities([Role::class => 1, Club::class => 1])
            ->seed();

        $this->assertTrue($this->app['db']->table('role_user')->exists());
        $this->assertTrue($this->app['db']->table('club_user')->exists());
    }

    public function testSeedAssociatesMorphToMany()
    {
        $this->populator
            ->add(Post::class, 100)
            ->add(Video::class, 100)
            ->add(Tag::class, 2)
            ->seed();

        $tag = Tag::find(2);

        $this->assertTrue($tag->posts()->exists());
        $this->assertTrue($tag->videos()->exists());
    }
}
