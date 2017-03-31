# Eloquent Populator

This is a package to populate Laravel's Eloquent ORM's models by guessing the best [Faker](https://github.com/fzaninotto/Faker) formatters for their attributes from their columns' names and types. It is based on Faker's other ORMs' adapters, but was released as a stand-alone package because several functionalities were added.

# Table of contents

- [Installation](#installation)
- [Seeding](#seeding)
- [Relationships](#relationships)
    - [Belongs To](#belongs-to)
    - [Morph To](#morph-to)
    - [Belongs To Many](#belongs-to-many)
- [Model Factory integration](#model-factory-integration)
- [Testing](#testing)
- [Laravel-Translatable integration](#laravel-translatable-integration)

## Installation

Require this package with Composer

```bash
$ composer require --dev guidocella/eloquent-populator ^2
```

Or manually add it to the development dependencies in your `composer.json` and run `composer update`. You may want to substitute it for Faker, since it will be required by Populator.

```json
"guidocella/eloquent-populator": "^2"
```

## Seeding

Either type hint `EloquentPopulator\Populator` in your `DatabaseSeeder`'s `run` method to have it dependency injected if you're using Laravel 5.4+

```php
public function run(EloquentPopulator\Populator $populator)
{
```

or call the `populator` helper with no arguments to get a Populator instance.

```php
$populator = populator();
```

### Adding models

Now for each model you want to create, call `add` passing the class and the number of instances to generate. Let's add 10 users and 5 posts.

```php
$populator->add(User::class, 10)
          ->add(Post::class, 5);
```

Populator will try to guess the best Faker formatter to use for each column based on its name. For example, if a column is called `first_name`, it will use `$faker->firstName`. If unable to guess by the column's name, it will guess the formatter by the column's type. For example, it will use `$faker->text` for a `VARCHAR` column, or a Carbon instance for a `TIMESTAMP`.

To customize the values of certain columns, pass them as the 3rd argument. If they're Faker formatters, you'll have to wrap them in a closure to get different values for each row.

```php
$populator->add(Post::class, 5, [
    'content'   => function () use ($faker) {
        return $faker->paragraph;
    }
]);
```

Any closure passed will receive the model instance and the previously inserted primary keys.

```php
$populator->add(Post::class, 5, [
    'user_id'   => function ($post, $insertedPKs) use ($faker) {
        return $faker->randomElement($insertedPKs[User::class]);
    },
    'user_type' => function ($post) {
        return $post->user->type;
    },
]);
```

The model received by the closures will have non-closure attributes and closure attributes of columns that come before in the database already set.
 
You can also pass an array of functions as the 4th argument and they'll be called before the model's insertion.

```php
$populator->add(Post::class, 5, [], [
    function ($post, $insertedPKs) {
        $post->doSomethingBeforeSaving();
    },
]);
```

If only the class name is passed, the number of models to populate defaults to 1.

### Creating the models

Finally, call `seed` to populate the database with the added models.
 
 ```php
$populator->add(User::class, 10)
           ->add(Post::class, 5)
           ->seed();
 ```

If a column that wasn't overridden is nullable, each value inserted by `seed` will have a 50% of being set to null or to the guessed formatter.

`seed` returns the inserted primary keys and runs one insert per 500 rows of every model to speed up the seeding (the chunking in blocks of 500 rows is because SQL limits how many rows you can insert at once).

Even though it bulk inserts the models, timestamps are still populated with random datetimes since their formatters are guessed along those of the other columns, and even mutators and JSON casts will work (internally Populator fills a model and gets its attributes every time to bulk insert them later), however Eloquent events won't fire.

### execute()

If you want the events to be dispatched, you can use `execute()` as an alternative to `seed()`. It creates the added models one by one, and returns all the created models or collections, depending on whether their quantity was 1 or greater, indexed by model class name.

```php
$createdModels = $populator->add(User::class)
                           ->add(Post::class, 5)
                           ->execute();

$userModel = $createdModels[User::class];
$postCollection = $createdModels[Post::class];
```

`execute()` doesn't make nullable columns optional, since it is more likely to be used for testing than for seeding, and when testing creating models with predictable values can be more useful.

## Relationships

If a model shares a relationship with another one that was previously added, Populator will associate them.

### Belongs To

If a model belongs to another one that was added before it, Populator will associate the child model to a random one of its potential owners.

```php
$populator->add(User::class, 5)
          ->add(Phone::class);

$phone = $populator->execute()[Phone::class];

$phone->user; // One of the users that were created.
```

### Morph To

If a model has a Morph To relation to models that were added before it, Populator will associate it to a random one of them.

```php
$populator->add(Post::class, 5)
          ->add(Video::class, 5)
          ->add(Comment::class);

$comment = $populator->execute()[Comment::class];

$comment->commentable; // One of the posts or videos that were created.
```

Associating multiple Morph To relations on a single model is currently not supported.

### Belongs To Many

If a model has a Belongs To Many or inverse Morph To Many relation to another one that has already been added, by default `seed` will attach a number between 0 and the quantity specified for the related model of the related model's instances to it.

```php
$insertedPKs = $populator->add(Role::class, 5)
                         ->add(User::class)
                         ->seed();

$user = User::find($insertedPKs[User::class][0]);

$user->roles->count(); // 0 to 5.
```

#### Customize the quantities attached

To attach a specific number of models, call `attachQuantities` after `add` with an array of the quantities indexed by the class names of the related models.

```php
$populator->add(User::class)->attachQuantites([Role::class => 5, Club::class => 0]);
```

#### Extra attributes

Any extra column on pivot tables will have its formatter guessed and be populated.

```php
$populator->add(Role::class, 5)
          ->add(User::class);

$user = $populator->execute()[User::class];

// Assume that the role_user table has an expires_at timestamp.

$user->roles[0]->pivot->expires_at; // A random datetime.
```

#### Overriding the extra attributes' formatters

You can override the formatters of extra attributes of pivot tables with `pivotAttributes`. It accepts an array with the related models' class names as keys, and the arrays of attributes as values.

```php
$populator->add(User::class)->pivotAttributes([
    Role::class => [
        'expires_at' => Carbon::now(),
    ],
]);
```

## Model Factory integration

In order not to repeat attributes across your seeders and your tests, you can define them in Laravel's Model Factory, and Populator will merge them with the guessed ones. Populator uses the Model Factory only as a convenient place to store custom attributes to reuse. You don't have to define all the other attributes for which the guessed formatters are good enough.

```php
$factory->define(User::class, function (Faker\Generator $faker) {
    return [
        'avatar' => $faker->imageUrl
    ];
});

$user = $populator->add(User::class)->execute()[User::class];

$user->imageUrl; // The URL of a random image.

$user->email; // A random email, since all of the other columns' formatters are guessed as normal.
```

### Factory states

Factory states are supported as well.

```php
$populator->add(User::class)->states('premium', 'delinquent');
```

States will work even if you define them without defining their model in the factory. Populator will create a dummy definition of the model automatically if you do so.

### Closure attributes

Populator will call closure attributes in factory definitions and states together with those of custom attributes and with the same arguments. So like with custom attributes, they will receive the model with non-closure attributes and the return values of closure attributes that come before in the database set. This means that you can do something like this:

```php
$factory->define(Post::class, function (Faker\Generator $faker) {
    return [
        'user_type' => function ($post, $insertedPKs) {
            return $post->user->type;
        },
    ];
});

$post = $populator->add(Post::class, ['user_id' => 1])->execute()[Post::class];

$post->user_type; // The type of user 1.
```

## Testing

When testing you can use `make` and `create`. They call `execute` internally, and return the last added model or collection.

Like with those of the Model Factory, the difference between them is that `create` persists the model to the database, while `make` doesn't. 

You can chain these methods from the `populator` helper, whose arguments, like `factory`, can be `class|class,state|class,quantity|class,state,quantity`. The custom attributes can be passed to `make` and `create`.

```php
populator(User::class)->make(); // Same as $populator->add(User::class)->make()

populator(User::class, 'admin')->create();

populator(User::class, 10)->make(['name' => 'Overridden name']);

populator(User::class, 'admin', 10)->create(['name' => 'Overridden name']);
```

`add` returns an instance of `EloquentPopulator\ModelPopulator` which manages single models and has these `make` and `create` methods that accept only the custom attributes. But you can also call `make` and `create` directly on `EloquentPopulator\Populator` as a shortcut. The `make` and `create` in `EloquentPopulator\Populator` have the same signature as `add`.

```php
$post = $populator->make(Post::class, ['content' => 'Overridden content']);
// Same as $populator->add(Post::class)->make(['content' => 'Overridden content'])

$users = $populator->create(User::class, 10);
```

`$populator->create()` / `make()` can be used as an alternative to calling the helper multiple times for saving single models before or after `seed`, for example the admin user (don't try to add the same model multiple times as it will overwrite the previous one), and for Morph To and Belongs To Many relations, for which we'll see examples later.

When creating a single a model, you can also pass custom attributes or a state as the second argument of `create`, `make` or `add`.

```php
$populator->make(User::class, ['admin' => true]);
```

```php
$populator->create(User::class, 'admin', $otherAttributes);
```

Furthermore, you can call `raw` to `make` a model and convert it to an array.

```php
$user = $populator->raw(User::class, ['name' => 'foo']);

$user['name']; // "foo"
```

#### execute()

`execute` is mostly useful to add a model and its children, and then return the parent model.

```php
$parent = populator()->add(Parent::class)->add(Child::class)->execute()[Parent::class];
```

### Relations

#### Belongs To

Owning models of Belongs To relations are created recursively without having to `add` them.

```php
$post = populator(Post::class)->make();

$post->user; // The created user.

$post->user->company; // The created company.
```

If you don't want an owning model to be created, specify the value of the foreign key.

```php
$post = populator(Post::class)->make(['user_id' => null]);

$post->user; // null


$post = populator(Post::class)->make(['user_id' => 1]);

$post->user->id; // 1
```

#### Morph To

For Morph To relations, unless you pass the foreign key and morph type as custom attributes or define them in the factory, you'll have to add the owning model to be associated before creating the child, since Populator has no way of knowning who its owners are otherwise.

```php
$comment = populator()->add(Post::class)->make(Comment::class);

// Or
$comment = populator(Post::class)->make(Comment::class);
```

Notice how even if `make` and `create` are chained from `add` or the helper with a class passed, if the first argument is a string, Populator interprets it as a different model class to create rather than the one that was just added, and redirects the call to the `EloquentPopulator\Populator` versions of these methods. This way you can easily populate Morph To and Belongs To Many relations in one line, or specify custom attributes of a parent model before creating its child.

#### Belongs To Many

By default Populator will attach all of the added instances of many-to-many related models when using `create` or `execute`.

```php
$user = populator()->add(Role::class, 20)->create(User::class);

$user->roles->count(); // 20.
```

You can call setters before `create` like this:

```php
$user = populator()->add(Role::class, 20)
                   ->add(User::class)->pivotAttributes([
                                         Role::class => [
                                             'expires_at' => Carbon::now(),
                                         ],
                                       ])
                   ->create();
```

## Laravel-Translatable integration

If a model uses the `Dimsav\Translatable\Translatable` trait, Populator will create its translations in all of the configured languages. 

```php
config(['translatable.locales' => ['en', 'es']]);

$product = populator(Product::class)->create();

$product->{'name:en'}; // "Fuga voluptas illo qui voluptates aut ipsam."
$product->{'name:es'}; // "Nulla vero qui magni quo aut quo."
```

When using `make` the translations will still be created, but they won't be saved to the database.

### Choosing the languages

If you don't want to translate in all of your available languages, pass an array with the locales in which you want the translations to be created in to `translateIn`. If you don't want any translation, pass an empty array.

```php
$populator->translateIn(['en']);

$product = populator(Product::class)->make();

$product->translations; // Only the English translation.
```

Calling `translateIn` on Populator sets the default locales, but you can also chain it from `add` to set the locales only for a certain model. 

```php
$populator->translateIn([])
          ->add(Product) // No ProductTranslation will be created.
          ->add(Role::class)->translateIn(['en', 'es']); // Role will be translated in English and Spanish.
          ->execute();
```

### Overriding translation formatters

#### Of one locale

You can override the formatters of one locale with Translatable's attribute:locale syntax from the main model's custom attributes or factory definition/states. Of course, you can omit :locale for the current locale.

```php
$factory->define(Product::class, function () {
    return [
        'name' => 'English name'
    ];
});

$product = populator(Product::class)->make(['name:es' => 'Spanish name']);

$product->name; // "English name"
$product->{'name:es'}; // "Spanish name"
$product->{'name:fr'}; // "Omnis ex soluta omnis."
```

#### Of all locales

You can override the formatters of all the translations with `translationAttributes`, or through the translation model's factory definition/states, which are merged like those of regular models.

```php
$product = populator(Product::class)->translationAttributes(['name' => 'Overridden name'])->make();

$product->{'name:en'}; // "Overridden name"
$product->{'name:es'}; // "Overridden name"
``` 

```php
$factory->define(ProductTranslation::class, function () {
    return ['name' => 'Overridden name'];
});

$product = populator(Product::class)->make();

$product->{'name:en'}; // "Overridden name"
$product->{'name:es'}; // "Overridden name"
```

To apply states to the translation model, use `translationStates`.

```php
$factory->state(ProductTranslation::class, 'laravel', function () {
    return ['name' => 'Laravel'];
});

$product = populator(Product::class)->translationStates('laravel')->make();

$product->name; // "Laravel"
```
