<?php

namespace EloquentPopulator;

use Carbon\Carbon;
use Doctrine\DBAL\Schema\Column;
use Faker\Generator;

class ColumnTypeGuesser
{
    /**
     * Faker's generator.
     *
     * @var Generator
     */
    protected $generator;

    /**
     * ColumnTypeGuesser constructor.
     *
     * @param Generator $generator
     */
    public function __construct(Generator $generator)
    {
        $this->generator = $generator;
    }

    /**
     * Guess a column's formatter based on its type.
     *
     * @param  Column $column
     * @param  string $tableName
     * @return \Closure|null
     *
     * @throws \InvalidArgumentException
     */
    public function guessFormat(Column $column, $tableName)
    {
        switch ($column->getType()->getName()) {
            case 'smallint':
                return function () {
                    return mt_rand(0, 65535);
                };
            case 'integer':
                return function () {
                    return mt_rand(0, intval('2147483647'));
                };
            case 'bigint':
                return function () {
                    return mt_rand(0, intval('18446744073709551615'));
                };
            case 'float':
                return function () {
                    return mt_rand(0, intval('4294967295')) / mt_rand(1, intval('4294967295'));
                };
            case 'decimal':
                $maxDigits = $column->getPrecision();
                $maxDecimalDigits = $column->getScale();

                $max = 10 ** ($maxDigits - $maxDecimalDigits);

                return function () use ($maxDecimalDigits, $max) {
                    $value = $this->generator->randomFloat($maxDecimalDigits, 0, $max);

                    // Prevents "Numeric value out of range" exceptions.
                    if ($value == $max) {
                        return $max - (1 / $maxDecimalDigits);
                    }

                    return $value;
                };
            case 'string':
                $size = $column->getLength() ?: 60;

                // If Faker's text() $maxNbChars argument is greater than 99,
                // the text it generates can have new lines which are ignored by non-textarea inputs
                // and break tests, so we'll limit the size to 99.
                if ($size > 99) {
                    $size = 99;
                }

                return function () use ($size, $column, $tableName) {
                    if ($size >= 5) {
                        return $this->generator->text($size);
                    }

                    $columnName = "$tableName.{$column->getName()}";

                    throw new \InvalidArgumentException(
                        "$columnName is a string shorter than 5 characters,"
                        . " but Faker's text() can only generate text of at least 5 characters." . PHP_EOL
                        . "Please specify a more accurate formatter for $columnName."
                    );

                    // Of course we could just use str_random($size) here,
                    // but for the CHAR columns for which I got this error
                    // I found that it was better to specify a more precise formatter anyway,
                    // e.g. $faker->countryCode for sender_country.
                };
            case 'text':
                return function () {
                    return $this->generator->text;
                };
            case 'guid':
                return function () {
                    return $this->generator->uuid;
                };
            case 'date':
            case 'datetime':
            case 'datetimetz':
            case 'time':
                return function () {
                    return Carbon::instance($this->generator->datetime);
                };
            case 'boolean':
                return function () {
                    return $this->generator->boolean;
                };
                // Sadly Doctrine maps all TINYINT to BooleanType.
            case 'json':
            case 'json_array':
                return function () {
                    return json_encode([$this->generator->word => $this->generator->word]);
                };
            default:
                // No smart way to guess what the user expects here.
                return null;
        }
    }
}
