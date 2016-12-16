<?php

namespace EloquentPopulator;

use Dimsav\Translatable\Translatable;
use Illuminate\Database\Eloquent\Model;

trait PopulatesTranslations
{
    /**
     * The locales in which to create translations.
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
     * Add translations to the model.
     *
     * @param  array[] $insertedPKs
     * @param  bool    $persist
     * @return void
     */
    protected function translate(array $insertedPKs)
    {
        if (!$this->shouldTranslate()) {
            return;
        }

        // We'll first set the translations relation to a new collection
        // through an instance of the translation model, just in case its newCollection() method is overridden,
        // but we're not gonna use Translatable::getNewTranslation()
        // because it runs a query every first time it accesses $model->translations.
        $translationModelName = $this->model->getTranslationModelName();
        $translationModel = new $translationModelName;

        $this->model->setRelation('translations', $translationModel->newCollection());

        // If translate() is being called for the first time, sets the guessed formatters of the translation model.
        if ($this->guessedTranslationFormatters === []) {
            $this->guessedTranslationFormatters = $this->guessColumnFormatters($translationModel);

            // We'll unset the foreign key formatter so the attribute won't be
            // set to random number which would remain the model with make().
            unset($this->guessedTranslationFormatters[$this->model->getRelationKey()]);
        }

        foreach ($this->getLocales() as $locale) {
            $translationModel = new $translationModelName;

            $this->fillModel($translationModel, $insertedPKs);

            $translationModel->{$this->model->getLocaleKey()} = $locale;

            $this->model->translations->add($translationModel);
        }
    }

    /**
     * Determine if the model should be translated.
     *
     * @return bool
     */
    protected function shouldTranslate()
    {
        return $this->locales && in_array(Translatable::class, class_uses($this->model));
    }

    /**
     * Get the locales to translate in.
     *
     * @return array
     */
    protected function getLocales()
    {
        $locales = [];

        $separator = config('translatable.locale_separator');

        foreach ($this->locales as $key => $value) {
            if (is_string($value)) {
                $locales[] = $value;
            } else {
                $locales[] = $key;

                foreach ($value as $countryLocale) {
                    $locales[] = $key . $separator . $countryLocale;
                }
            }
        }

        return $locales;
    }
}
