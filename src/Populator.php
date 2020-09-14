<?php

namespace GuidoCella\EloquentPopulator;

class Populator
{
    protected static array $guessedFormatters = [];

    public static bool $seeding = false;

    public static function setSeeding(): void
    {
        self::$seeding = true;
    }

    public static function guessFormatters(string $modelClass): array
    {
        return self::$guessedFormatters[$modelClass] ??= (new ModelPopulator($modelClass))->guessFormatters();
    }
}
