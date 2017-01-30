<?php

namespace EloquentPopulator;

use Faker\Generator;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class Populator
{
    /**
     * Faker's generator.
     *
     * @var Generator
     */
    protected $generator;

    /**
     * The ModelPopulator instances indexed by model class name.
     *
     * @var ModelPopulator[]
     */
    protected $modelPopulators = [];

    /**
     * The number of models to create indexed by class name.
     *
     * @var int[]
     */
    protected $quantities = [];

    /**
     * The classes with a one-to-one or many polymorphic relation.
     *
     * @var array[]
     */
    protected $morphClasses = [];

    /**
     * The locales in which to create translations of models using the Translatable trait.
     * Defaults to all supported locales.
     *
     * @var array|null
     */
    protected $locales;

    /**
     * Populator constructor.
     *
     * @param Generator $generator
     */
    public function __construct(Generator $generator)
    {
        $this->generator = $generator;

        $this->locales = config('translatable.locales');
    }

    /**
     * Add an order for the generation of $quantity records for $modelClass.
     * The second argument can be either the quantity, custom attributes or a state.
     *
     * @param  string           $modelClass       The class name of the Eloquent model to create.
     * @param  int|array|string $quantity         The number of models to create.
     * @param  array            $customAttributes Custom attributes that will override the guessed formatters.
     * @param  array            $modifiers        Functions to call before the model is saved.
     * @param  bool             $testing          Whether Populator is in testing mode. For internal use.
     * @return ModelPopulator
     */
    public function add(
        $modelClass,
        $quantity = 1,
        array $customAttributes = [],
        array $modifiers = [],
        $testing = false
    ) {
        if (is_array($quantity)) {
            $customAttributes = $quantity;
            $quantity = 1;
        } elseif (is_string($quantity)) {
            $state = $quantity;
            $quantity = 1;
        }

        $modelPopulator = new ModelPopulator(
            $this,
            new $modelClass,
            $this->generator,
            $customAttributes,
            $modifiers,
            $testing,
            $this->locales
        );

        if (isset($state)) {
            $modelPopulator->states($state);
        }

        if ($testing) {
            // Adds the owners before the model that was passed to make() or create(),
            // and in reverse order, so that the children will be associated to their parents.
            $this->addOwners($modelPopulator->getOwners());

            $this->modelPopulators = array_insert($this->modelPopulators, [$modelClass => $modelPopulator], -1);
            $this->quantities = array_insert($this->quantities, [$modelClass => $quantity], -1);
        } else {
            $this->modelPopulators[$modelClass] = $modelPopulator;
            $this->quantities[$modelClass] = $quantity;
        }

        return $modelPopulator;
    }

    /**
     * Recursively add the owners of a model.
     *
     * @param  array $owners The class names of the owners.
     * @return void
     */
    public function addOwners(array $owners)
    {
        foreach ($owners as $owner) {
            $this->add($owner, 1, [], [], true);
        }
    }

    /**
     * Determine if a model was added.
     *
     * @param  string $modelClass
     * @return bool
     */
    public function wasAdded($modelClass)
    {
        return isset($this->modelPopulators[$modelClass]);
    }

    /**
     * Save a possible owning class for a child class in a many-to-one
     * or one-to-one polymorphic relation with it,
     * so that the child model will be associated to one of its owners when it is populated.
     *
     * @param  string $owningClass
     * @param  string $childClass
     * @return void
     */
    public function addMorphClass($owningClass, $childClass)
    {
        $this->morphClasses[$childClass][] = $owningClass;
    }

    /**
     * Get the owners of a class in a many-to-one or one-to-one polymorphic relationship with them.
     *
     * @param  string $childClass
     * @return string[]
     */
    public function getMorphToClasses($childClass)
    {
        return isset($this->morphClasses[$childClass]) ? $this->morphClasses[$childClass] : [];
    }

    /**
     * Create the added models.
     *
     * @param  bool $persistLastModel Whether to persist the last added model in the database.
     * @return array The inserted models or collections indexed by class name.
     */
    public function execute($persistLastModel = true)
    {
        $insertedPKs = [];
        $createdModels = [];

        end($this->quantities);
        $lastModel = key($this->quantities);

        foreach ($this->quantities as $modelClass => $quantity) {
            $persist = $modelClass === $lastModel ? $persistLastModel : true;

            if ($quantity > 1) {
                $createdModels[$modelClass] = (new $modelClass)->newCollection();
            }

            for ($i = 0; $i < $quantity; $i++) {
                $createdModel = $this->modelPopulators[$modelClass]->run($insertedPKs, $persist);

                $insertedPKs[$modelClass][] = $createdModel->getKey();

                if ($quantity === 1) {
                    $createdModels[$modelClass] = $createdModel;
                } else {
                    $createdModels[$modelClass]->add($createdModel);
                }
            }
        }

        // We'll unset the previously added models so that if, for example, the developer calls
        // $populator->create(App\Foo::class), and then $populator->create(App\Bar::class),
        // Foo won't be created twice.
        $this->forgetAddedModels();

        return $createdModels;
    }

    /**
     * Remove the previously added models.
     *
     * @return void
     */
    protected function forgetAddedModels()
    {
        $this->quantities = $this->modelPopulators = [];
    }

    /**
     * Populate a database minimizing the number of queries.
     *
     * @return array The inserted primary keys indexed by model class name.
     */
    public function seed()
    {
        $insertedPKs = [];

        foreach ($this->quantities as $model => $quantity) {
            $attributes = [];
            $pivotRecords = [];
            $translations = [];

            for ($i = 0; $i < $quantity; $i++) {
                list($createdModel, $pivotTables, $currentPivotRecords, $foreignKeys) =
                    $this->modelPopulators[$model]->getInsertRecords($insertedPKs);

                // If the primary key is not auto incremented, it will be among the inserted attributes.
                if ($primaryKey = $createdModel->getKey()) {
                    $insertedPKs[$model][] = $primaryKey;
                }

                $attributes[] = $createdModel->getAttributes();

                if ($currentPivotRecords) {
                    $pivotRecords[] = $currentPivotRecords;
                }

                if ($createdModel->relationLoaded('translations')) {
                    // We're not gonna use \Illuminate\Support\Collection::toArray(), because the
                    // translation model could have accessors to append to its array form.
                    foreach ($createdModel->translations as $translation) {
                        $translations[$i][] = $translation->getAttributes();
                    }
                }
            }

            // SQL limits how many rows you can insert at once, so we'll chunk the records.
            foreach (array_chunk($attributes, 500) as $chunk) {
                $createdModel->insert($chunk);
            }

            if (!isset($insertedPKs[$model])) {
                $insertedPKs[$model] = $this->getInsertedPKs($createdModel, count($attributes));
            }

            $this->insertPivotRecords(
                $createdModel->getConnection(),
                $pivotTables,
                $pivotRecords,
                $foreignKeys,
                $insertedPKs[$model]
            );

            $this->insertTranslations($createdModel, $translations, $insertedPKs[$model]);
        }

        $this->forgetAddedModels();

        return $insertedPKs;
    }

    /**
     * Get the auto increment primary keys that were inserted by seed().
     *
     * @param  Model $createdModel
     * @param  int   $insertedCount
     * @return array
     */
    protected function getInsertedPKs(Model $createdModel, $insertedCount)
    {
        $primaryKeyName = $createdModel->getKeyName();

        return $createdModel->withoutGlobalScopes()
                            ->take($insertedCount)
                            ->latest($primaryKeyName)
                            ->pluck($primaryKeyName)
                            ->all();
    }

    /**
     * Insert pivot records for seed().
     *
     * @param  Connection $connection
     * @param  string[]   $pivotTables
     * @param  array[]    $pivotRecords
     * @param  array      $foreignKeys
     * @param  array      $insertedParentPKs
     */
    protected function insertPivotRecords(
        Connection $connection,
        array $pivotTables,
        array $pivotRecords,
        array $foreignKeys,
        array $insertedParentPKs
    ) {
        if (!$pivotRecords) {
            return;
        }

        // $pivotRecords is structured like this, with one base level element per parent model:
        // [
        //     'App\Role' => [$row, $row, $row], 'App\Club' => [$row, $row, $row],
        //     'App\Role' => [$row, $row, $row], 'App\Club' => [$row, $row, $row],
        // ]

        // The IDs of the parent model weren't known when preparing the bulk insert,
        // so we'll set the values of the foreign key referring to the parent now.
        $idKey = 0;

        foreach ($pivotRecords as &$recordsOfOneParent) {
            foreach ($recordsOfOneParent as $relatedClass => &$recordsOfOneRelation) {
                foreach ($recordsOfOneRelation as &$record) {
                    $record[$foreignKeys[$relatedClass]] = $insertedParentPKs[$idKey];
                }
            }

            $idKey++;
        }
        unset($recordsOfOneParent, $recordsOfOneRelation, $record);

        foreach (array_keys($pivotRecords[0]) as $relatedClass) {
            $recordsOfCurrentRelation = array_column($pivotRecords, $relatedClass);

            // We'll flatten the records since they're grouped by parent model.
            $recordsOfCurrentRelation = array_flatten($recordsOfCurrentRelation, 1);

            foreach (array_chunk($recordsOfCurrentRelation, 500) as $chunk) {
                $connection->table($pivotTables[$relatedClass])->insert($chunk);
            }
        }
    }

    /**
     * Insert translations for seed().
     *
     * @param  Model   $mainModel
     * @param  array[] $translations
     * @param  array   $insertedParentPKs
     * @return void
     */
    protected function insertTranslations(Model $mainModel, array $translations, array $insertedParentPKs)
    {
        if (!$translations) {
            return;
        }

        // The IDs of the model weren't known when preparing the bulk insert,
        // so we'll set the values of the foreign key now.
        $foreignKeyName = $mainModel->getRelationKey();

        $idKey = 0;

        foreach ($translations as &$translationsOfOneModel) {
            foreach ($translationsOfOneModel as &$translation) {
                $translation[$foreignKeyName] = $insertedParentPKs[$idKey];
            }

            $idKey++;
        }
        unset($translationsOfOneModel, $translation);

        // We'll flatten the records since they're grouped by model.
        $translations = array_flatten($translations, 1);

        foreach (array_chunk($translations, 500) as $chunk) {
            call_user_func([$mainModel->getTranslationModelName(), 'insert'], $chunk);
        }
    }

    /**
     * Create $quantity instances of the given model.
     * The second argument can be either the quantity, custom attributes or a state.
     *
     * @param  string           $modelClass       The class name of the Eloquent model to create.
     * @param  int|array|string $quantity         The number of models to populate.
     * @param  array            $customAttributes Custom attributes that will override the guessed formatters.
     * @param  array            $modifiers        Functions to call before the model is saved.
     * @return Model|Collection
     */
    public function make(
        $modelClass,
        $quantity = 1,
        array $customAttributes = [],
        array $modifiers = []
    ) {
        return $this->add($modelClass, $quantity, $customAttributes, $modifiers)->make();
    }

    /**
     * Create $quantity instances of the given model and persist them to the database.
     * The second argument can be either the quantity, custom attributes or a state.
     *
     * @param  string           $modelClass       The class name of the Eloquent model to create.
     * @param  int|array|string $quantity         The number of models to populate.
     * @param  array            $customAttributes Custom attributes that will override the guessed formatters.
     * @param  array            $modifiers        Functions to call before the model is saved.
     * @return Model|Collection
     */
    public function create(
        $modelClass,
        $quantity = 1,
        array $customAttributes = [],
        array $modifiers = []
    ) {
        return $this->add($modelClass, $quantity, $customAttributes, $modifiers)->create();
    }

    /**
     * Set the locales in which to create translations.
     *
     * @param  array $locales
     * @return static
     */
    public function translateIn(array $locales)
    {
        $this->locales = $locales;

        return $this;
    }
}
