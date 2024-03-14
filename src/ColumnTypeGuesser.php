<?php

namespace GuidoCella\EloquentPopulator;

use Closure;
use Faker\Generator;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ColumnTypeGuesser
{
    protected Generator $generator;

    public function __construct(Generator $generator)
    {
        $this->generator = $generator;
    }

    public function guessFormat(array $column): ?Closure
    {
        switch ($column['type_name']) {
            case 'smallint':
                return fn () => rand(0, 32767);

            case 'int':
            case 'integer':
                return fn () => rand(0, 2147483647);

            case 'bigint':
                return fn () => rand(0, 9223372036854775807);

            case 'float':
            case 'double':
                return fn () => $this->generator->randomFloat();

            case 'decimal':
            case 'numeric':
                // The precision is unavailable with SQLite.
                if ($column['type_name'] === 'numeric') {
                    $maxDigits = 3;
                    $maxDecimalDigits = 1;
                } else {
                    [$maxDigits, $maxDecimalDigits] = explode(',', Str::before(Str::after($column['type'], '('), ')'));
                }

                $max = 10 ** ($maxDigits - $maxDecimalDigits);

                return function () use ($maxDecimalDigits, $max) {
                    $value = $this->generator->randomFloat($maxDecimalDigits, 0, $max);

                    // Prevents "Numeric value out of range" exceptions.
                    if ($value == $max) {
                        return $max - (1 / $maxDecimalDigits);
                    }

                    return $value;
                };

            case 'varchar':
            case 'char':
                $size = Str::before(Str::after($column['type'], '('), ')');
                if ($size === 'varchar') { // SQLite
                    $size = 5;
                }

                // If Faker's text() $maxNbChars argument is greater than 99,
                // the text it generates can have new lines which are ignored by non-textarea inputs
                // and break WebDriver tests, so we'll limit the size to 99.
                if ($size > 99) {
                    $size = 99;
                }

                return function () use ($size) {
                    if ($size >= 5) {
                        return $this->generator->text($size);
                    }

                    return Str::random($size);
                };

            case 'text':
                return fn () => $this->generator->text();

            case 'uuid':
                return fn () => $this->generator->uuid();

            case 'date':
            case 'datetime':
            case 'datetimetz':
            case 'timestamp':
                return fn () => $this->generator->datetime();

            case 'time':
                return fn () => $this->generator->time();

            case 'tinyint':
                if ($column['type'] === 'tinyint(1)') {
                    return fn () => $this->generator->boolean();
                }

                return fn () => rand(0, 127);

            case 'json':
            case 'json_array':
            case 'longtext': // MariaDB
                return fn () => json_encode([$this->generator->word() => $this->generator->word()]);

            case 'enum':
                return fn () => Arr::random(explode(',', str_replace("'", '', substr($column['type'], 5, -1))));

            default:
                return null;
        }
    }
}
