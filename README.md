# Eloquent Populator

This package provides default attributes for Laravel model factories by guessing the best [Faker](https://github.com/fakerphp/Faker) formatters from columns' names and types.
For example, if a column is called `first_name`, it will use `$faker->firstName()`. If unable to guess by the column's name, it will guess the formatter by the column's type; for example, it will use `$faker->text()` for a `VARCHAR` column, or a Carbon instance for a `TIMESTAMP`. Models of `BelongsTo` relationships that have been defined are created as well.
Furthermore, if you use the [Multilingual](https://github.com/guidocella/laravel-multilingual) package, for translatable attributes Populator will generate arrays with a different value for each configured locale.

Compared to packages that generate factories once, you generally don't have to update your factories as you change their table definitions and they will be very small.

Due to the improvements in Laravel 8's model factories, in version 3 Populator has been rewritten to integrate with them instead of wrapping them. As a result, the convenient syntax to seed the database with bulk inserts while connecting all relationships has been lost, but you no longer have to deviate from Laravel's API and the complexity of the package has been drastically reduced.

## Installation

Install the package with Composer:

```sh
composer require --dev guidocella/eloquent-populator
```

## Model factory integration

Call `Populator::guessFormatters($this->model)` in your factories' `definition` methods to get an array with the guessed formatters. You may merge these with custom attributes whose guessed formatter isn't accurate.

```php
use GuidoCella\EloquentPopulator\Populator;

...

public function definition()
{
    return array_merge(Populator::guessFormatters($this->model), [
            'avatar' => $this->faker->imageUrl()
    ]);
}
```

If you execute `php artisan stub:publish`, you can include the call to Populator in `factory.stub` so that `php artisan make:factory` will add it.

After guessing a model's formatters once, they are cached in a static property even across different tests.

## Seeding

Before seeding your database you'll want to call `setSeeding()`.

```php
public function run()
{
    Populator::setSeeding();
```

Its effect is that nullable columns will have a 50% chance of being set to null or to the guessed formatter.

## Testing

If `setSeeding()` wasn't called nullable columns will always be set to their guessed formatter. The idea is to simplify tests such as this:

```php
public function testSubmitWithoutName() {
    $user = User::factory()->make(['name' => null]);
    // test that submitting a form with $user's attributes causes an error
}

public function testSubmitWithCorrectName() {
    $user = User::factory()->make();
    // no need to specify the correct formatter for name since it can't be null
    // test that submitting a form with $user's attributes is succesful
}
```

On the other hand, seeding the database with null attributes lets you notice `Trying to get property of non-object` errors.
