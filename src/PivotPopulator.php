<?php

namespace EloquentPopulator;

use Faker\Generator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class PivotPopulator
{
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
     * @var int|null
     */
    protected $quantity;

    /**
     * The guessed formatters of the extra attributes of the pivot table.
     *
     * @var (\Closure|null)[]
     */
    protected $guessedFormatters = [];
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
     * @param array          $guessedFormatters
     */
    public function __construct(
        ModelPopulator $modelPopulator,
        BelongsToMany $relation,
        Generator $generator,
        array $guessedFormatters
    ) {
        $this->modelPopulator = $modelPopulator;
        $this->relation = $relation;
        $this->relatedClass = get_class($relation->getRelated());
        $this->generator = $generator;
        $this->guessedFormatters = $this->unsetForeignKeys($guessedFormatters);
    }

    /**
     * Unset the closures that have been associated to the foreign keys by ColumnTypeGuesser,
     * so that attach() will set them to the correct values automatically.
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
        $method = method_exists($this->relation, 'getQualifiedForeignKeyName')
            ? 'getQualifiedForeignKeyName' // Laravel >=5.4
            : 'getForeignKey';

        return last(explode('.', $this->relation->$method()));
    }

    /**
     * Get the "related key" for the relation.
     *
     * @return string
     */
    protected function getRelatedKeyName()
    {
        $method = method_exists($this->relation, 'getQualifiedRelatedKeyName')
            ? 'getQualifiedRelatedKeyName' // Laravel >=5.4
            : 'getOtherKey';

        return last(explode('.', $this->relation->$method()));
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
     * Override the formatters.
     *
     * @param array $attributes
     */
    public function setCustomAttributes($attributes)
    {
        $this->customAttributes = $attributes;
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
        $this->updateParentKey($currentParent);

        $values = [];

        foreach ($this->pickRelatedIds($insertedPKs) as $relatedId) {
            $values[$relatedId] = $this->getExtraAttributes($insertedPKs, $currentParent);
        }

        $this->relation->attach($values);
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

        if ($this->modelPopulator->isTesting()) {
            return count($insertedPKs[$this->relatedClass]);
        }

        return mt_rand(0, count($insertedPKs[$this->relatedClass]));
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

        // A model's inverse MorphToMany relations use the same pivot table,
        // so we have to return the related class as well to differentiate them.
        return [$this->relatedClass, $table, $bulkInsertRecords, $this->getForeignKeyName()];
    }
}
