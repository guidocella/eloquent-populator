# Eloquent Populator

This is a package to populate Laravel's Eloquent ORM's models by guessing the best [Faker](https://github.com/fzaninotto/Faker) formatters for their attributes from their columns' names and types. It is based on Faker's other ORMs' adapters, but was released as a stand-alone package because several functionalities were added.

# Table of contents

- [Installation](#installation)
- [Usage](#usage)
- [Relationships](#relationships)
    - [Belongs To](#belongs-to)
    - [Morph To](#morph-to)
    - [Belongs To Many](#belongs-to-many)
- [Seed fast](#seed-fast)
- [Model Factory integration](#model-factory-integration)
- [Testing](#testing)
- [Custom generator](#testing)
- [Laravel-Translatable integration](#laravel-translatable-integration)

## Installation

Add this package to the development dependencies in your `composer.json`. You may want to substitute it for Faker, since it will be required by Populator.

```json
"guidocella/eloquent-populator": "^1",
```

Then run

```sh
composer update
```

## Usage

Populator ships with a `populator` helper. Call it with no arguments to get a Populator instance.

```php
$populator = populator();
```

### Adding models

Now for each model you want to create, call `add` passing the class and the number of instances to generate. Let's add 10 users and 5 posts.

```php
$populator
    ->add(User::class, 10)
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

The model received by the closures will have non-callable attributes and callable attributes of columns that come before in the database already set.
 
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

Finally, call `execute` to populate the database with the added models. It will return all the created models or collections, depending on whether their quantity was 1 or greater, indexed by model class name.

```php
$createdModels = $populator->execute();

$userModel = $createdModels[User::class];
$postCollection = $createdModels[Post::class];
```

## Relationships

If a model shares a relationship with another one that was previously added, Populator will associate them.

### Belongs To

If a model belongs to another one that was added before it, Populator will associate the child model to a random one of its potential owners.

```php
$populator
    ->add(User::class, 5)
    ->add(Phone::class);

$phone = $populator->execute()[Phone::class];

$phone->user; // One of the users that were created.
```

#### Optional relations

If a foreign key is nullable, Populator will make its formatter optional.

```php
// Assume that posts.user_id is nullable.

$populator
    ->add(User::class, 5)
    ->add(Post::class);

$post = $populator->execute()[Post::class];

$post->user; // null or one of the the users that were created.
```

You can still force the association by explicitly setting the foreign key:

```php
$populator->add(Post::class, function ($faker, $insertedPKs) {
   return [
       'user_id' => $faker->randomElement($insertedPKs[User::class]),
   )];
});
```

### Morph To

If a model has a Morph To relation to models that were added before it, Populator will associate it to a random one of them.

```php
$populator
    ->add(Post::class, 5)
    ->add(Video::class, 5)
    ->add(Comment::class);

$comment = $populator->execute()[Comment::class];

$comment->commentable; // One of the posts or videos that were created.
```

Associating multiple Morph To relations on a single model is currently not supported.

### Belongs To Many

If a model has a Belongs To Many or inverse Morph To Many relation to another one that has already been added, by default Populator will attach a number between 0 and the related model's quantity of the related model's instances to it.

```php
$populator
    ->add(Role::class, 5)
    ->add(User::class);

$user = $populator->execute()[User::class];

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
$populator
    ->add(Role::class, 5)
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

## Seed fast

To run only one insert per 500 rows of every model, call `seed` instead of `execute` (the chunking in blocks of 500 rows is because SQL limits how many rows you can insert at once).

```php
$populator
   ->add(User::class, 10000)
   ->add(Post::class, 10000)
   ->seed();
```

`seed` returns the inserted primary keys.

Timestamps are still populated with random datetimes since their formatters are guessed along those of the other columns, and even mutators and JSON casts will work (internally Populator fills a model and gets its attributes every time to bulk insert them later), however Eloquent events won't fire.

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

Populator will call closure attributes in factory definitions and states together with those of custom attributes and with the same arguments. So like with custom attributes, they will receive the model with non-callable attributes and the return values of callable attributes that come before in the database set. This means that you can do something like this:

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

populator(User::class, 10)->create(['name' => 'Overridden name']);

populator(User::class, 'admin', 10)->make(['name' => 'Overridden name']);
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

### Relations

`create` and `make` change how relations are associated.

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

By default, when calling `create` or `make` owning models are always created even if the foreign key is nullable. This way models created for tests have predictable foreign key values, and overriding the default behaviour by passing a null foreign key is easier than manually creating the owning model.

#### Morph To

For Morph To relations, unless you pass the foreign key and morph type as custom attributes or define them in the factory, you'll have to add the owning model to be associated before creating the child, since Populator has no way of knowning who its owners are otherwise.

```php
$populator = populator();

$populator->add(Post::class);

$comment = $populator->make(Comment::class);
```

Or with method chaining:

```php
$comment = populator()->add(Post::class)->make(Comment::class);
```

Or even:

```php
$comment = populator(Post::class)->make(Comment::class);
```

Notice how even if `make` and `create` are chained from `add` or the helper with a class passed, if the first argument is a string, Populator interprets it as a different model class to create rather than the one that was just added, and redirects the call to the `EloquentPopulator\Populator` versions of these methods. This way you can easily populate Morph To and Belongs To Many relations in one line.

#### Belongs To Many

If a model with a Belongs To Many relation to the model on which `create` is called was added, by default Populator will attach all of the instances of the related model.

```php
$user = populator()->add(Role::class, 20)->create(User::class);

$user->roles->count(); // 20.
```

You can call setters before `create` like this:

```php
$user = populator()
    ->add(Role::class, 20)
    ->add(User::class)
        ->pivotAttributes([
            Role::class => [
                'expires_at' => Carbon::now()
            ]
        ])
        ->create();
```

## Custom generator

The `Faker\Generator` instance used by Populator and the Model Factory is resolved out of Laravel's service container. To use a non-English locale or custom providers, redefine the generator in a service provider. For example, in your `AppServiceProvider`: 

```php
public function register()
    $this->app->singleton(\Faker\Generator::class, function () {
        return \Faker\Factory::create('es_ES');
    });
}
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
$populator
    ->translateIn([])
    ->add(Product) // No ProductTranslation will be created.
    ->add(Role::class)
        ->translateIn(['en', 'es']); // Role will be translated in English and Spanish.
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
