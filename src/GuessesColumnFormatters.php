<?php

namespace EloquentPopulator;

use Doctrine\DBAL\Schema\Column;
use Faker\Guesser\Name;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

trait GuessesColumnFormatters
{
    /**
     * The model's or pivot table's columns.
     *
     * @var Column[]
     */
    protected $columns = [];

    /**
     * The model's or pivot table's columns' guessed formatters.
     *
     * @var (\Closure|null)[]
     */
    protected $guessedFormatters = [];

    /**
     * Get the columns of a model's table.
     *
     * @param  Model|BelongsToMany $model
     * @return Column[]
     */
    protected function setColumns($model)
    {
        $schema = $model->getConnection()->getDoctrineSchemaManager();
        $platform = $schema->getDatabasePlatform();

        // Prevents a DBALException if the table contains an enum.
        $platform->registerDoctrineTypeMapping('enum', 'string');

        list($table, $database) = $this->getTableAndDatabase($model);

        $this->columns = $model->getConnection()->getDoctrineConnection()->fetchAll(
            $platform->getListTableColumnsSQL($table, $database)
        );

        $this->rejectVirtualColumns();

        $columns = $this->columns;
        $this->columns = call_user_func(\Closure::bind(function () use ($table, $database, $columns) {
            return $this->_getPortableTableColumnList($table, $database, $columns);
        }, $schema, $schema));

        return $this->unquoteColumnNames($platform->getIdentifierQuoteCharacter());
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
     * @return void
     */
    protected function rejectVirtualColumns()
    {
        $this->columns = array_filter($this->columns, function ($column) {
            return !isset($column['Extra']) || !Str::contains($column['Extra'], 'VIRTUAL');
        });
    }

    /**
     * Unquote column names that have been quoted by Doctrine because they are reserved keywords.
     *
     * @param  string $quoteCharacter
     * @return array void
     */
    protected function unquoteColumnNames($quoteCharacter)
    {
        foreach ($this->columns as $columnName => $columnData) {
            if (Str::startsWith($columnName, $quoteCharacter)) {
                $this->columns[substr($columnName, 1, -1)] = Arr::pull($this->columns, $columnName);
            }
        }
    }

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
        $this->setColumns($model);

        $formatters = [];

        $nameGuesser = new Name($this->generator);
        $columnTypeGuesser = new ColumnTypeGuesser($this->generator);

        foreach ($this->columns as $columnName => $column) {
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

        return $populateForeignKeys ? $this->populateForeignKeys($formatters, $seeding) : $formatters;
    }

    /**
     * Set the closures that will be used to populate the foreign keys
     * using the previously added related models.
     *
     * @param  array    $formatters
     * @param  bool     $seeding
     * @return array The formatters.
     */
    protected function populateForeignKeys(array $formatters, $seeding)
    {
        foreach ($this->relations as $relation) {
            if ($relation instanceof MorphTo) {
                $this->associateMorphTo($formatters, $relation, $seeding);
            } elseif ($relation instanceof BelongsTo) {
                $this->associateBelongsTo($formatters, $relation, $seeding);
            }
        }

        return $formatters;
    }

    /**
     * Set the closure that will be used to populate the foreign key of a Belongs To relation.
     *
     * @param  array     $formatters
     * @param  BelongsTo $relation
     * @param  bool      $seeding
     * @return void
     */
    protected function associateBelongsTo(array &$formatters, BelongsTo $relation, $seeding) {
        $relatedClass = get_class($relation->getRelated());
        $foreignKey = $relation->{$this->getBelongsToForeignKeyNameMethod()}();

        // Skips dynamic relationships.
        if (!isset($this->columns[$foreignKey])) {
            return;
        }

        $alwaysAssociate = $this->columns[$foreignKey]->getNotnull() || !$seeding;

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
     * @param  bool     $seeding
     * @return void
     */
    protected function associateMorphTo(array &$formatters, MorphTo $relation, $seeding) {
        // Removes the table names from the foreign key and the morph type.
        $foreignKey = last(explode('.', $relation->{$this->getBelongsToForeignKeyNameMethod()}()));
        $morphType = last(explode('.', $relation->getMorphType()));

        $alwaysAssociate = $this->columns[$foreignKey]->getNotnull() || !$seeding;

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

    /**
     * Get the name of the BelongsTo method to get the foreign key name,
     * which changed in Laravel 5.8.
     *
     * @return string
     */
    protected function getBelongsToForeignKeyNameMethod()
    {
        return method_exists(BelongsTo::class, 'getForeignKeyName') ? 'getForeignKeyName' : 'getForeignKey';
    }
}
