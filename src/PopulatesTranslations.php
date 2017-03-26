<?php

namespace EloquentPopulator;

use Dimsav\Translatable\Translatable;
use Illuminate\Database\Eloquent\Model;

trait PopulatesTranslations
{
    /**
     * The locales in which to create translations. If not set, Populator's locales are used.
     *
     * @var array|null
     */
    protected $locales;

    /**
     * The translation model's guessed column formatters.
     *
     * @var (\Closure|null)[]
     */
    protected $guessedTranslationFormatters = [];

    /**
     * Custom attributes for the translations.
     *
     * @var array
     */
    protected $customTranslationAttributes = [];

    /**
     * The factory states to apply to the translations.
     *
     * @var string[]
     */
    protected $translationStates = [];

    /**
     * Override the formatters of the translation model.
     *
     * @param  array $translationAttributes
     * @return static
     */
    public function translationAttributes(array $translationAttributes)
    {
        $this->customTranslationAttributes = $translationAttributes;

        return $this;
    }

    /**
     * Set the locales in which to create translations.
     * Overwrites the defaults set in Populator for the model being built.
     *
     * @param  array $locales
     * @return static
     */
    public function translateIn(array $locales)
    {
        $this->locales = $locales;

        return $this;
    }

    /**
     * Set the states to be applied to the model's translations.
     *
     * @param  string[] ...$states
     * @return static
     */
    public function translationStates(...$states)
    {
        $this->translationStates = $states;

        return $this;
    }

    /**
     * Determine if the model should be translated.
     *
     * @return bool
     */
    protected function shouldTranslate()
    {
        return $this->getLocales() && in_array(Translatable::class, class_uses($this->model));
    }

    /**
     * Set the guessed formatters of the translation model.
     *
     * @param bool $makeNullableColumnsOptionalAndKeepTimestamps
     */
    protected function guessTranslationFormatters($makeNullableColumnsOptionalAndKeepTimestamps)
    {
        // Don't use Translatable::getNewTranslation()
        // because it runs a query every first time it accesses $model->translations.
        $translationModelName = $this->model->getTranslationModelName();
        $translationModel = new $translationModelName;

        $this->guessedTranslationFormatters = $this->getGuessedColumnFormatters(
            $translationModel,
            $makeNullableColumnsOptionalAndKeepTimestamps
        );

        // We'll unset the foreign key formatter so the attribute won't be set to
        // a random number which would never be overwritten when make() is used.
        unset($this->guessedTranslationFormatters[$this->model->getRelationKey()]);
    }

    /**
     * Add translations to the model.
     *
     * @param  array[] $insertedPKs
     * @param  bool    $persist
     * @return void
     */
    protected function translate(array $insertedPKs)
    {
        $translationModelName = $this->model->getTranslationModelName();
        $translationModel = new $translationModelName;

        $this->model->setRelation('translations', $translationModel->newCollection());

        foreach ($this->getLocales() as $locale) {
            $translationModel = new $translationModelName;

            $this->fillModel($translationModel, $insertedPKs);

            $translationModel->{$this->model->getLocaleKey()} = $locale;

            $this->model->translations->add($translationModel);
        }
    }

    /**
     * Determine if a model is the main model or its translation.
     *
     * @param  Model $model
     * @return bool
     */
    protected function isTranslation(Model $model)
    {
        return $model !== $this->model;
    }

    /**
     * Get the locales to translate in.
     *
     * @return array
     */
    protected function getLocales()
    {
        if ($this->locales === []) {
            return [];
        }

        $rawLocales = $this->locales ?: $this->populator->getLocales() ?: [];

        $locales = [];

        foreach ($rawLocales as $key => $value) {
            if (is_string($value)) {
                $locales[] = $value;
            } else {
                $locales[] = $key;

                foreach ($value as $countryLocale) {
                    $locales[] = $key . config('translatable.locale_separator') . $countryLocale;
                }
            }
        }

        return $locales;
    }
}
