<?php

namespace GuidoCella\EloquentPopulator;

use Faker\Generator;
use GuidoCella\Multilingual\Translatable;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;

class ModelPopulator
{
    protected Model $model;

    protected Generator $generator;

    protected array $columns = [];

    public function __construct(string $modelClass)
    {
        $this->model = new $modelClass();
        $this->generator = Container::getInstance()->make(Generator::class);
        $this->columns = array_filter(
            $this->model->getConnection()->getSchemaBuilder()->getColumns($this->model->getTable()),
            fn ($column) => $column['generation'] === null, // filter out virtual columns
        );
    }

    public function guessFormatters(): array
    {
        $formatters = [];

        $nameGuesser = new Name($this->generator);
        $columnTypeGuesser = new ColumnTypeGuesser($this->generator);

        foreach ($this->columns as $column) {
            if ($column['name'] === $this->model->getKeyName() && $column['auto_increment']) {
                continue;
            }

            if (
                method_exists($this->model, 'getDeletedAtColumn')
                && $column['name'] === $this->model->getDeletedAtColumn()
            ) {
                continue;
            }

            if (
                !Populator::$seeding
                && in_array($column['name'], [$this->model->getCreatedAtColumn(), $this->model->getUpdatedAtColumn()])
            ) {
                continue;
            }

            $formatter = $nameGuesser->guessFormat($column) ?? $columnTypeGuesser->guessFormat($column);

            if (!$formatter) {
                continue;
            }

            if (!$column['nullable'] || !Populator::$seeding) {
                $formatters[$column['name']] = $formatter;
            } else {
                $formatters[$column['name']] = fn () => rand(0, 1) ? $formatter() : null;
            }
        }

        $this->setFormattersForTranslatableAttributes($formatters);

        foreach ($this->getBelongsToRelations() as $relation) {
            $this->associateBelongsTo($formatters, $relation);
        }

        return $formatters;
    }

    protected function setFormattersForTranslatableAttributes(array &$formatters): void
    {
        if (!in_array(Translatable::class, class_uses($this->model)) || !$this->model->translatable) {
            return;
        }

        foreach ($this->model->translatable as $translatableAttributeKey) {
            $formatters[$translatableAttributeKey] = function () {
                return collect(config('multilingual.locales'))
                    ->mapWithKeys(fn ($locale) => [$locale => $this->generator->sentence()])
                    ->all()
                ;
            };
        }
    }

    protected function getBelongsToRelations(): array
    {
        // Based on Barryvdh\LaravelIdeHelper\Console\ModelsCommand::getPropertiesFromMethods().
        return collect(get_class_methods($this->model))
            ->reject(fn ($methodName) => method_exists(Model::class, $methodName))
            ->filter(fn ($methodName) => stripos($this->getMethodCode($methodName), '$this->belongsTo('))
            ->map(fn ($methodName) => $this->model->$methodName())
            ->filter(fn ($relation) => $relation instanceof BelongsTo)
            ->all()
        ;
    }

    protected function getMethodCode(string $method): string
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

    protected function associateBelongsTo(array &$formatters, BelongsTo $relation): void
    {
        $relatedClass = get_class($relation->getRelated());
        $foreignKey = $relation->getForeignKeyName();
        $foreignKeyColumn = Arr::first($this->columns, fn ($column) => $column['name'] === $foreignKey);

        // Ignore dynamic relations in which the foreign key is selected with a subquery.
        // https://reinink.ca/articles/dynamic-relationships-in-laravel-using-subqueries
        // (superseded by Has One Of Many relationships)
        if (!$foreignKeyColumn) {
            return;
        }

        // Skip foreign keys for relations of the model to itself to prevent infinite recursion.
        if ($this->model instanceof $relatedClass) {
            $formatters[$foreignKey] = null;

            return;
        }

        $relatedFactoryClass = Factory::resolveFactoryName($relatedClass);
        if (!class_exists($relatedFactoryClass)) {
            $formatters[$foreignKey] = null;

            return;
        }

        $relatedFactory = $relatedFactoryClass::new();

        if (!$foreignKeyColumn['nullable'] || !Populator::$seeding) {
            $formatters[$foreignKey] = $relatedFactory;
        } else {
            $formatters[$foreignKey] = fn () => rand(0, 1) ? $relatedFactory : null;
        }
    }
}
