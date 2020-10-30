<?php

namespace GuidoCella\EloquentPopulator;

use Faker\Generator;
use Faker\Guesser\Name;
use GuidoCella\Multilingual\Translatable;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ModelPopulator
{
    protected Model $model;

    protected Generator $generator;

    /**
     * @var \Doctrine\DBAL\Schema\Column[]
     */
    protected array $columns = [];

    public function __construct(string $modelClass)
    {
        $this->model = new $modelClass();
        $this->generator = Container::getInstance()->make(Generator::class);
        $this->setColumns();
    }

    protected function setColumns(): void
    {
        $schema = $this->model->getConnection()->getDoctrineSchemaManager();
        $platform = $schema->getDatabasePlatform();

        // Prevent a DBALException if the table contains an enum.
        $platform->registerDoctrineTypeMapping('enum', 'string');

        [$table, $database] = $this->getTableAndDatabase();

        $this->columns = $this->model->getConnection()->getDoctrineConnection()->fetchAll(
            $platform->getListTableColumnsSQL($table, $database)
        );

        $this->rejectVirtualColumns();

        $columns = $this->columns;
        $this->columns = (fn () => $this->_getPortableTableColumnList($table, $database, $columns))->call($schema);

        $this->unquoteColumnNames($platform->getIdentifierQuoteCharacter());
    }

    protected function getTableAndDatabase(): array
    {
        $table = $this->model->getConnection()->getTablePrefix().$this->model->getTable();

        if (strpos($table, '.')) {
            [$database, $table] = explode('.', $table);
        }

        return [$table, $database ?? null];
    }

    // For MySql and MariaDB
    protected function rejectVirtualColumns()
    {
        $this->columns = array_filter($this->columns, fn ($column) => !isset($column['Extra']) || !Str::contains($column['Extra'], 'VIRTUAL'));
    }

    /**
     * Unquote column names that have been quoted by Doctrine because they are reserved keywords.
     */
    protected function unquoteColumnNames(string $quoteCharacter): void
    {
        foreach ($this->columns as $columnName => $columnData) {
            if (Str::startsWith($columnName, $quoteCharacter)) {
                $this->columns[substr($columnName, 1, -1)] = Arr::pull($this->columns, $columnName);
            }
        }
    }

    public function guessFormatters(): array
    {
        $formatters = [];

        $nameGuesser = new Name($this->generator);
        $columnTypeGuesser = new ColumnTypeGuesser($this->generator);

        foreach ($this->columns as $columnName => $column) {
            if ($columnName === $this->model->getKeyName() && $column->getAutoincrement()) {
                continue;
            }

            if (
                method_exists($this->model, 'getDeletedAtColumn')
                && $columnName === $this->model->getDeletedAtColumn()
            ) {
                continue;
            }

            if (
                !Populator::$seeding &&
                in_array($columnName, [$this->model->getCreatedAtColumn(), $this->model->getUpdatedAtColumn()])
            ) {
                continue;
            }

            $formatter = $nameGuesser->guessFormat(
                $columnName,
                $column->getLength()
            ) ?? $columnTypeGuesser->guessFormat(
                $column,
                $this->model->getTable()
            );

            if (!$formatter) {
                continue;
            }

            if ($column->getNotnull() || !Populator::$seeding) {
                $formatters[$columnName] = $formatter;
            } else {
                $formatters[$columnName] = fn () => rand(0, 1) ? $formatter() : null;
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
                    ->mapWithKeys(fn ($locale) => [$locale => $this->generator->sentence])
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

        // Ignore dynamic relations in which the foreign key is selected with a subquery.
        // https://reinink.ca/articles/dynamic-relationships-in-laravel-using-subqueries
        if (!isset($this->columns[$foreignKey])) {
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

        if ($this->columns[$foreignKey]->getNotnull() || !Populator::$seeding) {
            $formatters[$foreignKey] = $relatedFactory;
        } else {
            $formatters[$foreignKey] = fn () => rand(0, 1) ? $relatedFactory : null;
        }
    }
}
