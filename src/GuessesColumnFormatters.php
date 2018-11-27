<?php

namespace EloquentPopulator;

use Doctrine\DBAL\Schema\Column;
use Faker\Guesser\Name;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

trait GuessesColumnFormatters
{
    /**
     * The model's or pivot table's columns' guessed formatters.
     *
     * @var (\Closure|null)[]
     */
    protected $guessedFormatters = [];

    /**
     * Guess the column formatters based on the columns' names or types or on whether they are a foreign key.
     *
     * @param  Model|BelongsToMany $model
     * @param  bool                $seeding
     * @param  bool                $populateForeignKeys
     * @return array
     */
    protected function getGuessedColumnFormatters($model, $seeding, $populateForeignKeys = false)
    {
        $columns = $this->getColumns($model);

        $formatters = [];

        $nameGuesser = new Name($this->generator);
        $columnTypeGuesser = new ColumnTypeGuesser($this->generator);

        foreach ($columns as $columnName => $column) {
            // Skips autoincremented primary keys.
            if ($model instanceof Model && $columnName === $model->getKeyName() && $column->getAutoincrement()) {
                continue;
            }

            // And deleted_at.
            if (
                method_exists($model, 'getDeletedAtColumn')
                && $columnName === $model->getDeletedAtColumn()
            ) {
                continue;
            }

            if (
                !$seeding && $model instanceof Model &&
                ($columnName === $model->getCreatedAtColumn() || $columnName === $model->getUpdatedAtColumn())
            ) {
                continue;
            }

            // Guesses based on the column's name, and if unable to,
            // guesses based on the column's type.
            $formatter = $nameGuesser->guessFormat($columnName, $column->getLength())
                ?: $columnTypeGuesser->guessFormat($column, $model->getTable());

            if (!$formatter) {
                continue;
            }

            if ($column->getNotnull() || !$seeding) {
                $formatters[$columnName] = $formatter;
            } else {
                $formatters[$columnName] = function () use ($formatter) {
                    return rand(0, 1) ? $formatter() : null;
                };
            }
        }

        return $populateForeignKeys ? $this->populateForeignKeys($formatters, $columns, $seeding) : $formatters;
    }

    /**
     * Get the columns of a model's table.
     *
     * @param  Model|BelongsToMany $model
     * @return Column[]
     */
    protected function getColumns($model)
    {
        $schema = $model->getConnection()->getDoctrineSchemaManager();
        $platform = $schema->getDatabasePlatform();

        // Prevents a DBALException if the table contains an enum.
        $platform->registerDoctrineTypeMapping('enum', 'string');

        list($table, $database) = $this->getTableAndDatabase($model);

        $columns = $model->getConnection()->getDoctrineConnection()->fetchAll(
            $platform->getListTableColumnsSQL($table, $database)
        );

        $columns = $this->rejectVirtualColumns($columns);

        $columns = call_user_func(\Closure::bind(function () use ($table, $database, $columns) {
            return $this->_getPortableTableColumnList($table, $database, $columns);
        }, $schema, $schema));

        return $this->unquoteColumnNames($columns, $platform->getIdentifierQuoteCharacter());
    }

    /**
     * Get the table and database names of a model.
     *
     * @param  Model $model
     * @return array
     */
    protected function getTableAndDatabase($model)
    {
        $table = $model->getConnection()->getTablePrefix() . $model->getTable();

        $database = null;

        if (strpos($table, '.')) {
            list($database, $table) = explode('.', $table);
        }

        return [$table, $database];
    }

    /**
     * If the database driver is MySql/MariaDB, filter out any virtual column.
     *
     * @param  array $columns
     * @return array The columns.
     */
    protected function rejectVirtualColumns(array $columns)
    {
        return array_filter($columns, function ($column) {
            return !isset($column['Extra']) || !str_contains($column['Extra'], 'VIRTUAL');
        });
    }

    /**
     * Unquote column names that have been quoted by Doctrine because they are reserved keywords.
     *
     * @param  array  $columns
     * @param  string $quoteCharacter
     * @return array The columns.
     */
    protected function unquoteColumnNames(array $columns, $quoteCharacter)
    {
        foreach ($columns as $columnName => $columnData) {
            if (starts_with($columnName, $quoteCharacter)) {
                $columns[substr($columnName, 1, -1)] = array_pull($columns, $columnName);
            }
        }

        return $columns;
    }

    /**
     * Set the closures that will be used to populate the foreign keys
     * using the previously added related models.
     *
     * @param  array    $formatters
     * @param  Column[] $columns
     * @param  bool     $makeNullableColumnsOptional
     * @return array The formatters.
     */
    protected function populateForeignKeys(array $formatters, array $columns, $makeNullableColumnsOptional)
    {
        foreach ($this->relations as $relation) {
            if ($relation instanceof MorphTo) {
                $this->associateMorphTo($formatters, $relation, $columns, $makeNullableColumnsOptional);
            } elseif ($relation instanceof BelongsTo) {
                $this->associateBelongsTo($formatters, $relation, $columns, $makeNullableColumnsOptional);
            }
        }

        return $formatters;
    }

    /**
     * Set the closure that will be used to populate the foreign key of a Belongs To relation.
     *
     * @param  array     $formatters
     * @param  BelongsTo $relation
     * @param  Column[]  $columns
     * @param  bool      $makeNullableColumnsOptional
     * @return void
     */
    protected function associateBelongsTo(
        array &$formatters,
        BelongsTo $relation,
        array $columns,
        $makeNullableColumnsOptional
    ) {
        $relatedClass = get_class($relation->getRelated());
        $foreignKey = $relation->getForeignKey();

        $alwaysAssociate = $columns[$foreignKey]->getNotnull() || !$makeNullableColumnsOptional;

        $formatters[$foreignKey] = function ($model, $insertedPKs) use ($relatedClass, $alwaysAssociate) {
            if (!isset($insertedPKs[$relatedClass])) {
                return null;
            }

            if ($alwaysAssociate) {
                return $this->generator->randomElement($insertedPKs[$relatedClass]);
            }

            return $this->generator->optional()->randomElement($insertedPKs[$relatedClass]);
        };
    }

    /**
     * Set the closure that will be used to populate the foreign key of a Morph To relation.
     *
     * @param  array    $formatters
     * @param  MorphTo  $relation
     * @param  Column[] $columns
     * @param  bool     $makeNullableColumnsOptional
     * @return void
     */
    protected function associateMorphTo(
        array &$formatters,
        MorphTo $relation,
        array $columns,
        $makeNullableColumnsOptional
    ) {
        // Removes the table names from the foreign key and the morph type.
        $foreignKey = last(explode('.', $relation->getForeignKey()));
        $morphType = last(explode('.', $relation->getMorphType()));

        $alwaysAssociate = $columns[$foreignKey]->getNotnull() || !$makeNullableColumnsOptional;

        $formatters[$foreignKey] = function ($model, $insertedPKs) use ($alwaysAssociate) {
            if (!($morphOwner = $this->pickMorphOwner($insertedPKs, $alwaysAssociate))) {
                return null;
            }

            $randomElement = $this->generator->randomElement($insertedPKs[$morphOwner]);

            return $randomElement;
        };

        $formatters[$morphType] = function ($model, $insertedPKs) use ($alwaysAssociate) {
            if (!($morphOwner = $this->pickMorphOwner($insertedPKs, $alwaysAssociate))) {
                return null;
            }

            return (new $morphOwner)->getMorphClass();
        };
    }

    /**
     * Select a random owning class for a Morph To relation.
     *
     * @param  array[] $insertedPKs
     * @param  bool    $alwaysAssociate
     * @return string|null
     */
    protected function pickMorphOwner(array $insertedPKs, $alwaysAssociate)
    {
        $owners = $this->populator->getMorphToClasses(get_class($this->model));

        // We'll share the chosen owner between the foreign key and morph type closures
        // by saving it in $this until the next call to this method.
        if ($this->morphOwner === false) {
            if (!$alwaysAssociate && rand(0, 1)) {
                return $this->morphOwner = null;
            }

            // Filters the owning classes that have been added to Populator.
            $owners = array_filter($owners, function ($owner) use ($insertedPKs) {
                return isset($insertedPKs[$owner]);
            });

            return $this->morphOwner = $this->generator->randomElement($owners);
        }

        return tap($this->morphOwner, function () {
            // We'll unset the picked owner,
            // so it will be picked again randomly when the next model is created.
            $this->morphOwner = false;
        });
    }
}
