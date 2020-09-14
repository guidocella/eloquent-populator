## v3.0 (2020-09-14)

- Populator has been rewritten to integrate with the improved Laravel 8 model factories instead of wrapping them.
- Removed the integration with Translatable.

## v2.1.5 (2018-11-27)

- Prevented the population of dynamic relationships - inspired by https://reinink.ca/articles/dynamic-relationships-in-laravel-using-subqueries

## v2.1.4 (2018-09-04)

- Added Laravel 5.7 support

## v2.1.3 (2018-02-08)

- Added Laravel 5.6 support

## v2.1.2 (2017-08-30)

- Added Laravel 5.5 support

## v2.1.1 (2017-07-12)

- Avoided inserting virtual columns

## v2.1 (2017-06-20)

- Added Laravel Multilingual support

## v2.0.3 (2017-05-29)

- Prevented "Too many connections" errors when running many tests by closing the Doctrine connections

## v2.0.2 (2017-05-13)

- Prevented error with BelongsTo and BelongsToMany relations to the same model

## v2.0.1 (2017-04-18)

- Prevented previously added models from being recreated

## v2.0 (2017-03-26)

- `seed` sets nullable columns to either null or the guessed formatter
- Owning models that haven't been added explicitly and whose foreign keys haven't been passed as custom or factory attributes are always automatically added with a quantity of 1
- `TIME` columns are populated with random time strings so you can see if the database contains them in tests without having to format them. You can still cast them to Carbon istances by adding them to their models' `$dates` field.
- Removed the `array_insert` helper

## v1.2.1 (2017-02-13)

- Replaced `is_callable()` with `instanceof Closure` when filling the model so values that happen to be function names aren't interpreated as callables

## v1.2 (2017-02-03)

- Added `raw()` method

## v1.1.2 (2017-01-30)

- Removed global scopes when fetching the last inserted IDs with `seed()` so they're all fetched

## v1.1.1 (2017-01-21)

- Added Laravel 5.4 support

## v1.1.0 (2017-01-04)

- Fixed self referential BelongsTo relationships causing infinite recursions
- Prevented new lines in string columns because they broke non-textarea inputs in WebDriver tests
- Added support for DATETIME-TZ, UUID and JSON column types

## v1.0.1 (2017-01-02)

- Fixed decimal columns causing exceptions when randomFloat returns the maximum possible value
