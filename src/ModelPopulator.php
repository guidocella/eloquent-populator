<?php

namespace EloquentPopulator;

use Doctrine\DBAL\Schema\Column;
use Faker\Generator;
use Faker\Guesser\Name;
use Illuminate\Database\Eloquent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * @mixin Populator
 */
class ModelPopulator
{
    use PopulatesTranslations;

    /**
     * The Populator instance.
     *
     * @var Populator
     */
    protected $populator;

    /**
     * Faker's generator.
     *
     * @var Generator
     */
    protected $generator;

    /**
     * The model being built.
     *
     * @var Model
     */
    protected $model;

    /**
     * The model's relations.
     *
     * @var Relation[]
     */
    protected $relations;

    /**
     * The model's columns' guessed formatters.
     *
     * @var (\Closure|null)[]
     */
    protected $guessedFormatters = [];

    /**
     * Custom attributes for the model.
     *
     * @var array
     */
    protected $customAttributes;

    /**
     * Functions to call before the model is saved.
     *
     * @var callable[]
     */
    protected $modifiers = [];

    /**
     * Whether Populator is in testing mode.
     * true when calling create(), make() or raw().
     *
     * @var bool
     */
    protected $testing;

    /**
     * The owning class of the Morph To relation of the current model instance, if it has one.
     *
     * @var string|null
     */
    protected $morphOwner;

    /**
     * The factory states to apply.
     *
     * @var string[]
     */
    protected $states = [];

    /**
     * The PivotPopulator instances of the model's BelongsToMany relations.
     *
     * @var PivotPopulator[]
     */
    protected $pivotPopulators = [];

    /**
     * ModelPopulator constructor.
     *
     * @param Populator  $populator
     * @param Model      $model
     * @param Generator  $generator
     * @param array      $customAttributes
     * @param callable[] $modifiers
     * @param bool       $testing
     * @param array|null $locales
     */
    public function __construct(
        Populator $populator,
        Model $model,
        Generator $generator,
        array $customAttributes,
        array $modifiers,
        $testing,
        $locales
    ) {
        $this->populator = $populator;
        $this->model = $model;
        $this->generator = $generator;
        $this->bootstrapRelations();
        $this->testing = $testing;
        $this->guessedFormatters = $this->guessColumnFormatters($model);
        $this->customAttributes = $customAttributes;
        $this->modifiers = $modifiers;
        $this->locales = $locales;
    }

    /**
     * Set the states to be applied to the model.
     *
     * @param  mixed ...$states
     * @return static
     */
    public function states(...$states)
    {
        $this->states = $states;

        return $this;
    }

    /**
     * Set the model's relations and do some processing with them.
     *
     * @return void
     */
    protected function bootstrapRelations()
    {
        $this->relations = $this->getRelations();

        foreach ($this->relations as $relation) {
            if ($relation instanceof BelongsToMany) {
                $this->setPivotPopulator($relation);
            } elseif ($relation instanceof MorphOneOrMany) {
                $this->addMorphClass($relation);
            }
        }
    }

    /**
     * Get the model's relations.
     *
     * @return Relation[]
     */
    protected function getRelations()
    {
        // Based on Barryvdh\LaravelIdeHelper\Console\ModelsCommand::getPropertiesFromMethods().
        return collect(get_class_methods($this->model))
            ->reject(function ($methodName) {
                return method_exists(Model::class, $methodName);
            })
            ->filter(function ($methodName) {
                $methodCode = $this->getMethodCode($methodName);

                return collect([
                    'belongsTo',
                    'morphTo',
                    'morphOne',
                    'morphMany',
                    'belongsToMany',
                    'morphedByMany',
                ])->contains(function ($relationName) use ($methodCode) {
                    return stripos($methodCode, '$this->' . $relationName . '(');
                });
            })
            ->map(function ($methodName) {
                return $this->model->$methodName();
            })
            ->filter(function ($relation) {
                return $relation instanceof Relation;
            })
            ->all();
    }

    /**
     * Get the source code of a method of the model.
     *
     * @param  string $method
     * @return string
     */
    protected function getMethodCode($method)
    {
        $reflection = new \ReflectionMethod($this->model, $method);

        $file = new \SplFileObject($reflection->getFileName());
        $file->seek($reflection->getStartLine() - 1);

        $methodCode = '';

        while ($file->key() < $reflection->getEndLine()) {
            $methodCode .= $file->current();

            $file->next();
        }

        $methodCode = trim(preg_replace('/\s\s+/', '', $methodCode));

        $begin = strpos($methodCode, 'function(');
        $length = strrpos($methodCode, '}') - $begin + 1;

        return substr($methodCode, $begin, $length);
    }

    /**
     * Get the model's Belongs To relations.
     *
     * @return BelongsTo[]
     */
    protected function belongsToRelations()
    {
        return array_filter($this->relations, function ($relation) {
            return $relation instanceof BelongsTo && !$relation instanceof MorphTo;
        });
    }

    /**
     * Set the PivotPopulator of a BelongsToMany relation.
     *
     * @param  BelongsToMany $relation
     * @return void
     */
    protected function setPivotPopulator(BelongsToMany $relation)
    {
        $relatedClass = get_class($relation->getRelated());

        // If the many-to-many related model has not yet been added,
        // the pivot table will be populated along with it if it will be added.
        if (!$this->populator->wasAdded($relatedClass)) {
            return;
        }

        $this->pivotPopulators[$relatedClass] = new PivotPopulator(
            $this,
            $relation,
            $this->generator,
            $this->guessColumnFormatters($relation)
        );
    }

    /**
     * Set the number of many-to-many related models to attach.
     * They default to a random number between 0 and the count of the inserted primary keys of the related models
     * with execute() and seed(), and to the count with create().
     *
     * @param  array $manyToManyQuantites The quantities indexed by related model class name.
     * @return static
     */
    public function attachQuantities(array $manyToManyQuantites)
    {
        foreach ($manyToManyQuantites as $relatedClass => $quantity) {
            $this->pivotPopulators[$relatedClass]->setQuantity($quantity);
        }

        return $this;
    }

    /**
     * Override the formatters of the extra attributes of pivot tables.
     *
     * @param  array $pivotAttributes The attribute arrays indexed by related model class name.
     * @return static
     */
    public function pivotAttributes(array $pivotAttributes)
    {
        foreach ($pivotAttributes as $relatedClass => $attributes) {
            $this->pivotPopulators[$relatedClass]->setCustomAttributes($attributes);
        }

        return $this;
    }

    /**
     * Save a possible owning class for a child class in a many-to-one
     * or one-to-one polymorphic relation with it,
     * so that the child model will be associated to one of its owners when it is populated.
     *
     * @param  MorphOneOrMany $relation
     * @return void
     */
    protected function addMorphClass(MorphOneOrMany $relation)
    {
        $this->populator->addMorphClass(get_class($this->model), get_class($relation->getRelated()));
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

        return $this->unquoteColumnNames(
            $schema->listTableColumns($table, $database),
            $platform->getIdentifierQuoteCharacter()
        );
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
     * Get the column formatters based on the columns' names or types or on whether they are a foreign key.
     *
     * @param  Model|BelongsToMany $model
     * @return array
     */
    protected function guessColumnFormatters($model)
    {
        $formatters = [];

        $columns = $this->getColumns($model);

        $nameGuesser = new Name($this->generator);
        $columnTypeGuesser = new ColumnTypeGuesser($this->generator);

        foreach ($columns as $columnName => $column) {
            // Skips autoincremented primary keys.
            if ($columnName === $this->model->getKeyName() && $column->getAutoincrement()) {
                continue;
            }

            // And deleted_at.
            if (
                method_exists($this->model, 'getDeletedAtColumn')
                && $columnName === $this->model->getDeletedAtColumn()
            ) {
                continue;
            }

            // Guesses based on the column's name.
            if ($formatter = $nameGuesser->guessFormat($columnName, $column->getLength())) {
                $formatters[$columnName] = $formatter;
                continue;
            }

            // If unable to guess by the column's name, guesses based on the column's type.
            if ($formatter = $columnTypeGuesser->guessFormat($column, $model->getTable())) {
                $formatters[$columnName] = $formatter;
            }
        }

        // Pivot tables and translations shouldn't have their foreign keys associated here.
        return $model instanceof $this->model ? $this->populateForeignKeys($formatters, $columns) : $formatters;
    }

    /**
     * Set the closures that will be used to populate the foreign keys
     * using the previously added related models.
     *
     * @param  array    $formatters
     * @param  Column[] $columns
     * @return array The formatters.
     */
    protected function populateForeignKeys(array $formatters, array $columns)
    {
        foreach ($this->relations as $relation) {
            if ($relation instanceof MorphTo) {
                $formatters = $this->associateMorphTo($formatters, $relation);
            } elseif ($relation instanceof BelongsTo) {
                $formatters = $this->associateBelongsTo($formatters, $relation, $columns);
            }
        }

        return $formatters;
    }

    /**
     * Set the closure that will be used to populate the foreign key of a Belongs To relation.
     *
     * @param  array         $formatters
     * @param  BelongsTo     $relation
     * @param  Column[]|null $columns
     * @return array The formatters.
     */
    protected function associateBelongsTo(array &$formatters, BelongsTo $relation, array $columns = null)
    {
        $relatedClass = get_class($relation->getRelated());
        $foreignKey = $relation->getForeignKey();

        $alwaysAssociate = $this->testing || $columns[$foreignKey]->getNotnull();

        $formatters[$foreignKey] = function ($model, $insertedPKs) use ($relatedClass, $alwaysAssociate) {
            if (!isset($insertedPKs[$relatedClass])) {
                return null;
            }

            if ($alwaysAssociate) {
                return $this->generator->randomElement($insertedPKs[$relatedClass]);
            }

            return $this->generator->optional()->randomElement($insertedPKs[$relatedClass]);
        };

        return $formatters;
    }

    /**
     * Set the closure that will be used to populate the foreign key of a Morph To relation.
     *
     * @param  array   $formatters
     * @param  MorphTo $relation
     * @return array The formatters.
     */
    protected function associateMorphTo(array &$formatters, MorphTo $relation)
    {
        // Removes the table names from the foreign key and the morph type.
        $foreignKey = last(explode('.', $relation->getForeignKey()));
        $morphType = last(explode('.', $relation->getMorphType()));

        $formatters[$foreignKey] = function ($model, $insertedPKs) {
            if (null === $morphOwner = $this->pickMorphOwner($insertedPKs)) {
                return null;
            }

            return $this->generator->randomElement($insertedPKs[$morphOwner]);
        };

        $formatters[$morphType] = function ($model, $insertedPKs) {
            if (null === $morphOwner = $this->pickMorphOwner($insertedPKs)) {
                return null;
            }

            return (new $morphOwner)->getMorphClass();
        };

        return $formatters;
    }

    /**
     * Select a random owning class for a Morph To relation.
     *
     * @param  array[] $insertedPKs
     * @return string
     */
    protected function pickMorphOwner(array $insertedPKs)
    {
        $owners = $this->populator->getMorphToClasses(get_class($this->model));

        // We'll share the information of the chosen owner between the 2 closures by setting it in $this.

        if ($this->morphOwner) {
            return tap($this->morphOwner, function () {
                // We'll set the picked owner to null, so it will be picked again randomly on the next iteration.
                $this->morphOwner = null;
            });
        }

        // Filters the owning classes that have been added to Populator.
        $owners = array_filter($owners, function ($owner) use ($insertedPKs) {
            return isset($insertedPKs[$owner]);
        });

        return $this->morphOwner = $this->generator->randomElement($owners);
    }

    /**
     * Create an instance of the given model.
     *
     * @param  array[] $insertedPKs
     * @param  bool    $persist
     * @param  bool    $keepTimestamps
     * @return Model
     */
    public function run(array $insertedPKs, $persist, $keepTimestamps = false)
    {
        // This method has a different name just to allow chaning execute() after add().

        if (!$keepTimestamps) {
            $this->unsetTimestamps();
        }

        $this->model = new $this->model;

        // Creating the translations before filling the model allows setting custom
        // attributes for the main model in the form of attribute:locale,
        // e.g. name:de, without having them overwritten.
        if ($this->shouldTranslate()) {
            $this->translate($insertedPKs);
        }

        $this->fillModel($this->model, $insertedPKs);

        $this->callModifiers($insertedPKs);

        if ($persist) {
            $this->model->save();

            foreach ($this->pivotPopulators as $pivotPopulator) {
                $pivotPopulator->execute($this->model, $insertedPKs);
            }
        }

        return $this->model;
    }

    /**
     * Unset the timestamps' guessed formatters.
     *
     * @return void
     */
    protected function unsetTimestamps()
    {
        // Note that we can't just avoid setting the timestamps' formatters because they are needed by seed(),
        // and when the models are added it's still unknown what method will be called.
        unset(
            $this->guessedFormatters[$this->model->getCreatedAtColumn()],
            $this->guessedFormatters[$this->model->getUpdatedAtColumn()]
        );
    }

    /**
     * Fill a model using the available attributes.
     *
     * @param  Model   $model
     * @param  array[] $insertedPKs
     * @return void
     */
    protected function fillModel(
        Model $model,
        array $insertedPKs
    ) {
        $attributes = $this->mergeAttributes($model);

        // To maximize the number of attributes already set in the
        // model when accessing it from closure custom attributes,
        // we'll first set all the non-closure attributes.
        $closureAttributes = [];

        foreach ($attributes as $key => $value) {
            // Don't use is_callable() here since it returns true for values
            // that happen to be function names, e.g. country code "IS".
            if ($value instanceof \Closure) {
                $closureAttributes[$key] = $value;
            } else {
                $model->$key = $value;
            }
        }

        // We'll set the remaining attributes while evaluating the closures,
        // so that the model will have the return values of previous closures already set.
        foreach ($closureAttributes as $key => $value) {
            $model->$key = $value($model, $insertedPKs);
        }
    }

    /**
     * Merge the guessed, factory and custom attributes.
     *
     * @param  Model $model
     * @return array
     */
    protected function mergeAttributes(Model $model)
    {
        $factoryAttributes = $this->getFactoryAttributes($model);

        $isTranslation = $this->isTranslation($model);

        return array_merge(
            $isTranslation ? $this->guessedTranslationFormatters : $this->guessedFormatters,
            $factoryAttributes,
            $isTranslation ? $this->customTranslationAttributes : $this->customAttributes
        );
    }

    /**
     * Get the model factory attributes of the model being built.
     *
     * @param  Model $model
     * @return array
     * @throws \InvalidArgumentException
     */
    protected function getFactoryAttributes(Model $model)
    {
        // We want to allow developers to specify attributes for single locales in their factory definitions
        // with the attribute:locale syntax, but the factory has no rawWithStates() method,
        // and if we were to make() the model and then call getAttribute() everytime,
        // such attributes would cause Translatable to run a query for each model instance.
        // We'll resort to binding a closure to the factory to get the defined states,
        // and pass them as custom attributes to raw().
        // This has the added bonus over make()->getAttributes() of delaying the calling
        // of any closure in factory definitions/states, allowing them to receive the model
        // with more attributes set and the inserted primary keys as arguments,
        // like the closure custom attributes passed to Populator.
        $factory = app(Eloquent\Factory::class);

        $states = $this->isTranslation($model) ? $this->translationStates : $this->states;

        $modelClass = get_class($model);

        $isDefined = call_user_func(\Closure::bind(function () use ($modelClass) {
            return isset($this->definitions[$modelClass]);
        }, $factory, $factory));

        if (!$isDefined) {
            // If the model is not defined in the factory and the developer didn't apply any state,
            // that means he doesn't need the factory for this model, so we'll return an empty array.
            if (!$states) {
                return [];
            }

            // If the model doesn't have a factory definition,
            // but the developer still wants to apply states,
            // we'll create a dummy definition of the model to allow it.
            $factory->define($modelClass, function () {
                return [];
            });
        }

        $stateAttributes = [];

        foreach ($states as $state) {
            $stateClosure = call_user_func(
                \Closure::bind(function () use ($modelClass, $state) {
                    if (!isset($this->states[$modelClass][$state])) {
                        throw new \InvalidArgumentException("Unable to locate [{$state}] state for [{$modelClass}].");
                    }

                    return $this->states[$modelClass][$state];
                }, $factory, $factory)
            );

            $stateAttributes = array_merge($stateAttributes, $stateClosure($this->generator));
        }

        return $factory->raw($modelClass, $stateAttributes);
    }

    /**
     * Call the modifiers.
     *
     * @param array[] $insertedPKs
     */
    protected function callModifiers(array $insertedPKs)
    {
        foreach ($this->modifiers as $modifier) {
            $modifier($this->model, $insertedPKs);
        }
    }

    /**
     * Get the records to bulk insert.
     *
     * @param  array[] $insertedPKs
     * @return array
     */
    public function getInsertRecords(array $insertedPKs)
    {
        $this->run($insertedPKs, false, true);

        $tables = [];
        $pivotRecords = [];
        $foreignKeys = [];

        foreach ($this->pivotPopulators as $pivotPopulator) {
            list($relatedClass, $table, $pivotRecordsOfOneRelation, $foreignKey) = $pivotPopulator->getInsertRecords(
                $this->model,
                $insertedPKs
            );

            $tables[$relatedClass] = $table;
            $pivotRecords[$relatedClass] = $pivotRecordsOfOneRelation;
            $foreignKeys[$relatedClass] = $foreignKey;
        }

        return [$this->model, $tables, $pivotRecords, $foreignKeys];
    }

    /**
     * Create the given model and convert it to an array.
     *
     * @param  array $customAttributes Custom attributes that will override the guessed formatters.
     * @return array
     */
    public function raw($customAttributes = [])
    {
        // We actually need to make() the model first since closures attributes receive the model instance.
        return $this->make($customAttributes)->toArray();
    }

    /**
     * Create an instance of the given model and persist it to the database.
     *
     * @param  array $customAttributes Custom attributes that will override the guessed formatters.
     * @return Model|Collection
     */
    public function create($customAttributes = [])
    {
        // If the first argument is a string, we'll assume it's the class name
        // of a different model to create.
        if (is_string($customAttributes)) {
            return $this->populator->create(...func_get_args());
        }

        return $this->make($customAttributes, true);
    }

    /**
     * Create an instance of the given model.
     *
     * @param  array $customAttributes Custom attributes that will override the guessed formatters.
     * @param  bool  $persist
     * @return Model|Collection
     */
    public function make($customAttributes = [], $persist = false)
    {
        if (is_string($customAttributes)) {
            return $this->populator->make(...func_get_args());
        }

        $this->testing = true;

        // This condition prevents the overwriting of the custom attributes
        // thay may have been passed to add().
        if ($customAttributes) {
            $this->customAttributes = $customAttributes;
        }

        $this->associateNullableForeignKeys();

        $this->populator->addOwners($this->getOwners());

        return last($this->populator->execute($persist));
    }

    /**
     * Create owning models even if their foreign keys are nullable.
     *
     * @return void
     */
    protected function associateNullableForeignKeys()
    {
        // When calling create(), make() or raw(), foreign keys are populated even if nullable,
        // so models created for testing have predictable foreign key values.
        // However, when the formatters are guessed it's still unknown whether create(), make(), execute() or seed()
        // will be called, so we'll override the foreign key formatters in question now.
        // There's no need to do this for the ModelPopulators of owning models,
        // since they will be passed $testing = true on construction.
        foreach ($this->belongsToRelations() as $relation) {
            $this->associateBelongsTo($this->guessedFormatters, $relation);
        }
    }

    /**
     * Get the model's owners' class names.
     *
     * @return string[]
     */
    public function getOwners()
    {
        return collect($this->belongsToRelations())
            ->reject(function ($relation) {
                // Rejects the relations whose foreign keys have been passed as custom attributes.
                return array_key_exists($relation->getForeignKey(), $this->customAttributes)
                    || array_key_exists($relation->getForeignKey(), $this->getFactoryAttributes($this->model))

                    // And the relations of the model to itself to prevent infinite recursion.
                    || $relation->getRelated() instanceof $this->model;
            })
            ->map(function ($relation) {
                return get_class($relation->getRelated());
            })
            ->all();
    }

    /**
     * Determine if Populator is in testing mode.
     *
     * @return bool
     */
    public function isTesting()
    {
        return $this->testing;
    }

    /**
     * Handle dynamic method calls.
     *
     * @param  string $method
     * @param  array  $arguments
     * @return mixed
     *
     * @throws \BadMethodCallException
     */
    public function __call($method, $arguments)
    {
        if (method_exists($this->populator, $method)) {
            return $this->populator->$method(...$arguments);
        }

        throw new \BadMethodCallException("Call to undefined method EloquentPopulator\\ModelPopulator::$method()");
    }
}
