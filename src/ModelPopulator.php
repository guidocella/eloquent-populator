<?php

namespace EloquentPopulator;

use Faker\Generator;
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
    use GuessesColumnFormatters, PopulatesTranslations;

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
     * The owning class of the Morph To relation of the current model instance, if it has one.
     *
     * @var string|bool
     */
    protected $morphOwner = false;

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
     */
    public function __construct(
        Populator $populator,
        Model $model,
        Generator $generator,
        array $customAttributes,
        array $modifiers
    ) {
        $this->populator = $populator;
        $this->model = $model;
        $this->generator = $generator;
        $this->customAttributes = $customAttributes;
        $this->modifiers = $modifiers;
        $this->bootstrapRelations();
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
    public function bootstrapRelations()
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
                    return stripos($methodCode, "\$this->$relationName(");
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

        $start = strpos($methodCode, 'function(');
        $length = strrpos($methodCode, '}') - $start + 1;

        return substr($methodCode, $start, $length);
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
            $this->generator
        );
    }

    /**
     * Set the number of many-to-many related models to attach.
     * They default to a random number between 0 and the count of the inserted primary keys of the related models
     * with seed(), and to the count with the other methods.
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
     * Set custom attributes that will override the formatters of the extra attributes of pivot tables.
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
     * Save a potential owning class for a child class in a many-to-one
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
     * Set the guessed column formatters for the model being built.
     *
     * @param  bool $makeNullableColumnsOptionalAndKeepTimestamps
     * @return void
     */
    public function setGuessedColumnFormatters($makeNullableColumnsOptionalAndKeepTimestamps)
    {
        $this->guessedFormatters = $this->getGuessedColumnFormatters(
            $this->model,
            $makeNullableColumnsOptionalAndKeepTimestamps
        );

        if ($this->shouldTranslate()) {
            $this->guessTranslationFormatters($makeNullableColumnsOptionalAndKeepTimestamps);
        }

        $this->setGuessedPivotFormatters($makeNullableColumnsOptionalAndKeepTimestamps);
    }

    /**
     * Set the guessed column formatters for the extra columns of the pivot tables
     * of the BelongsToMany relations of the model being built.
     *
     * @param  bool $makeNullableColumnsOptionalAndKeepTimestamps
     * @return void
     */
    public function setGuessedPivotFormatters($makeNullableColumnsOptionalAndKeepTimestamps)
    {
        foreach ($this->pivotPopulators as $pivotPopulator) {
            $pivotPopulator->setGuessedColumnFormatters($makeNullableColumnsOptionalAndKeepTimestamps);

            if ($makeNullableColumnsOptionalAndKeepTimestamps) {
                $pivotPopulator->attachRandomQuantity();
            }
        }
    }

    /**
     * Create an instance of the given model.
     *
     * @param  array[] $insertedPKs
     * @param  bool    $persist
     * @param  bool    $keepTimestamps
     * @return Model
     */
    public function run(array $insertedPKs, $persist)
    {
        // This method has a different name just to allow chaning execute() after add().

        $this->model = new $this->model;

        // We'll create the translations before filling the model to allow setting
        // custom attributes for the main model in the form of attribute:locale,
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
     * Fill a model using the available attributes.
     *
     * @param  Model   $model
     * @param  array[] $insertedPKs
     * @return void
     */
    protected function fillModel(Model $model, array $insertedPKs)
    {
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

        // We'll set the remaining attributes as we evaluate the closures,
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
     */
    protected function getFactoryAttributes(Model $model)
    {
        // This package allows developers to specify attributes for single locales
        // in their factory definitions with the attribute:locale syntax,
        // but the Eloquent\FactoryBuilder's raw() method interprets them as regular attributes,
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

        $modelIsDefined = call_user_func(\Closure::bind(function () use ($modelClass) {
            return isset($this->definitions[$modelClass]);
        }, $factory, $factory));

        if (!$modelIsDefined) {
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

        return $factory->raw($modelClass, $this->getStateAttributes($factory, $states, $modelClass));
    }

    /**
     * Get the factory state attributes of the model being built.
     *
     * @param  Eloquent\Factory $factory
     * @param  string[]         $states
     * @param  string           $modelClass
     * @return array
     * @throws \InvalidArgumentException
     */
    protected function getStateAttributes($factory, $states, $modelClass)
    {
        return collect($states)
            ->flatMap(function ($state) use ($factory, $modelClass) {
                $stateClosure = call_user_func(
                    \Closure::bind(function () use ($modelClass, $state) {
                        if (!isset($this->states[$modelClass][$state])) {
                            throw new \InvalidArgumentException("Unable to locate [{$state}] state for [{$modelClass}].");
                        }

                        return $this->states[$modelClass][$state];
                    }, $factory, $factory)
                );

                return $stateClosure($this->generator);
            })
            ->all();
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
        $this->run($insertedPKs, false);

        $tables = [];
        $pivotRecords = [];
        $foreignKeys = [];

        foreach ($this->pivotPopulators as $relatedClass => $pivotPopulator) {
            if (!isset($insertedPKs[$relatedClass])) {
                continue;
            }

            list($table, $pivotRecordsOfOneRelation, $foreignKey) = $pivotPopulator->getInsertRecords(
                $this->model,
                $insertedPKs
            );

            // A model's inverse MorphToMany relations use the same pivot table,
            // so we have to use the related class as index to differentiate them.
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
        // If the first argument is a string, we'll assume it's the class name
        // of a different model to create.
        if (is_string($customAttributes)) {
            return $this->populator->raw(...func_get_args());
        }

        // We need to make() the model first since closures attributes receive the model instance.
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

        // This condition prevents the overwriting of the custom attributes
        // thay may have been passed to add().
        if ($customAttributes) {
            $this->customAttributes = $customAttributes;
        }

        return last($this->populator->execute($persist));
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
