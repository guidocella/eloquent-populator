<?php

namespace EloquentPopulator;

use Faker\Generator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class PivotPopulator
{
    use GuessesColumnFormatters;

    /**
     * The ModelPopulator of the relation's parent.
     *
     * @var ModelPopulator
     */
    protected $modelPopulator;

    /**
     * Faker's generator.
     *
     * @var Generator
     */
    protected $generator;

    /**
     * The relation being populated.
     *
     * @var BelongsToMany
     */
    protected $relation;

    /**
     * The class name of the related model.
     *
     * @var string
     */
    protected $relatedClass;

    /**
     * The number of models to attach.
     *
     * @var int|string|null
     */
    protected $quantity;

    /**
     * Custom attributes for the pivot table.
     *
     * @var array
     */
    protected $customAttributes = [];

    /**
     * PivotPopulator constructor.
     *
     * @param ModelPopulator $modelPopulator
     * @param BelongsToMany  $relation
     * @param Generator      $generator
     */
    public function __construct(ModelPopulator $modelPopulator, BelongsToMany $relation, Generator $generator)
    {
        $this->modelPopulator = $modelPopulator;
        $this->relation = $relation;
        $this->generator = $generator;
        $this->relatedClass = get_class($relation->getRelated());
    }

    /**
     * Set the number of related models to attach.
     *
     * @param int $quantity
     */
    public function setQuantity($quantity)
    {
        $this->quantity = $quantity;
    }

    /**
     * Attach a random quantity of the related model,
     * unless the developer hasn't already specified an exact number.
     *
     * @param string|int $quantity
     */
    public function attachRandomQuantity()
    {
        if (!$this->quantity) {
            $this->quantity = 'random';
        }
    }

    /**
     * Set custom attributes that will override the pivot formatters.
     *
     * @param array $attributes
     */
    public function setCustomAttributes($attributes)
    {
        $this->customAttributes = $attributes;
    }

    /**
     * Set the guessed column formatters for extra attributes of the pivot table.
     *
     * @param  bool $seeding
     * @return void
     */
    public function setGuessedColumnFormatters($seeding)
    {
        $this->guessedFormatters = $this->unsetForeignKeys(
            $this->getGuessedColumnFormatters($this->relation, $seeding)
        );
    }

    /**
     * Unset the closures that have been associated to the foreign keys by ColumnTypeGuesser,
     * so that attach() will automatically set them to the correct values.
     *
     * @param  array $guessedFormatters
     * @return array The formatters.
     */
    protected function unsetForeignKeys(array $guessedFormatters)
    {
        unset($guessedFormatters[$this->getForeignKeyName()], $guessedFormatters[$this->getRelatedKeyName()]);

        // If we're dealing with an inverse MorphToMany relation, we'll unset the morph type as well.
        if ($this->relation instanceof MorphToMany) {
            unset($guessedFormatters[$this->relation->getMorphType()]);
        }

        return $guessedFormatters;
    }

    /**
     * Get the foreign key for the relation.
     *
     * @return string
     */
    protected function getForeignKeyName()
    {
        if (method_exists($this->relation, 'getQualifiedForeignPivotKeyName')) {
            $method = 'getQualifiedForeignPivotKeyName'; // Laravel 5.5
        } elseif (method_exists($this->relation, 'getQualifiedForeignKeyName')) {
            $method = 'getQualifiedForeignKeyName'; // Laravel 5.4
        } else {
            $method = 'getForeignKey'; // Laravel 5.3
        };

        return last(explode('.', $this->relation->$method()));
    }

    /**
     * Get the "related key" for the relation.
     *
     * @return string
     */
    protected function getRelatedKeyName()
    {
        if (method_exists($this->relation, 'getQualifiedRelatedPivotKeyName')) {
            $method = 'getQualifiedRelatedPivotKeyName'; // Laravel 5.5
        } elseif (method_exists($this->relation, 'getQualifiedRelatedKeyName')) {
            $method = 'getQualifiedRelatedKeyName'; // Laravel 5.4
        } else {
            $method = 'getOtherKey'; // Laravel 5.3
        };

        return last(explode('.', $this->relation->$method()));
    }

    /**
     * Populate the pivot table.
     *
     * @param  Model   $currentParent
     * @param  array[] $insertedPKs
     * @return void
     */
    public function execute(Model $currentParent, array $insertedPKs)
    {
        if (!isset($insertedPKs[$this->relatedClass])) {
            return;
        }

        $this->updateParentKey($currentParent);

        $this->relation->attach(
            collect($this->pickRelatedIds($insertedPKs))
                ->mapWithKeys(function ($relatedId) use ($insertedPKs, $currentParent) {
                    return [$relatedId => $this->getExtraAttributes($insertedPKs, $currentParent)];
                })
                ->all()
        );
    }

    /**
     * Set the primary key of the parent model to the one of the model being built.
     * This is necessary because the relation was instantiated on ModelPopulator's construction.
     *
     * @param  Model $currentParent
     * @return void
     */
    protected function updateParentKey(Model $currentParent)
    {
        $parentModel = $this->relation->getParent();

        $keyName = $parentModel->getKeyName();

        $parentModel->$keyName = $currentParent->$keyName;
    }

    /**
     * Select the related ids to attach.
     *
     * @param  array[] $insertedPKs
     * @return array
     */
    protected function pickRelatedIds(array $insertedPKs)
    {
        return $this->generator->randomElements($insertedPKs[$this->relatedClass], $this->getQuantity($insertedPKs));
    }

    /**
     * Get the number of models to attach.
     *
     * @param  array[] $insertedPKs
     * @return int
     */
    protected function getQuantity(array $insertedPKs)
    {
        if (is_int($this->quantity)) {
            return $this->quantity;
        }

        if ($this->quantity === 'random') {
            return mt_rand(0, count($insertedPKs[$this->relatedClass]));
        }

        return count($insertedPKs[$this->relatedClass]);
    }

    /**
     * Get the extra attributes.
     *
     * @param  array[] $insertedPKs
     * @param  Model   $currentParent
     * @return array
     */
    protected function getExtraAttributes(array $insertedPKs, Model $currentParent)
    {
        if (!$this->guessedFormatters) {
            return [];
        }

        $extra = array_merge($this->guessedFormatters, $this->customAttributes);

        return $this->evaluateClosureFormatters($extra, $insertedPKs, $currentParent);
    }

    /**
     * Evaluate closure formatters.
     *
     * @param  array   $extra
     * @param  array[] $insertedPKs
     * @param  Model   $currentParent
     * @return array The attributes.
     */
    protected function evaluateClosureFormatters(array $extra, array $insertedPKs, Model $currentParent)
    {
        return array_map(function ($formatter) use ($insertedPKs, $currentParent) {
            return is_callable($formatter) ? $formatter($currentParent, $insertedPKs) : $formatter;
        }, $extra);
    }

    /**
     * Get the records to bulk insert.
     *
     * @param  mixed   $currentParent
     * @param  array[] $insertedPKs
     * @return array
     */
    public function getInsertRecords(Model $currentParent, array $insertedPKs)
    {
        $table = $this->relation->getTable();

        $bulkInsertRecords = [];

        foreach ($this->pickRelatedIds($insertedPKs) as $relatedId) {
            $relatedKeyArray = [$this->getRelatedKeyName() => $relatedId];

            if ($this->relation instanceof MorphToMany) {
                $relatedKeyArray[$this->relation->getMorphType()] = $this->relation->getRelated()->getMorphClass();
            }

            $bulkInsertRecords[] = array_merge($relatedKeyArray, $this->getExtraAttributes($insertedPKs, $currentParent));
        }

        return [$table, $bulkInsertRecords, $this->getForeignKeyName()];
    }
}
