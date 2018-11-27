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
     * The custom translatable attributes.
     *
     * @var array
     */
    protected $customTranslatableAttributes = [];

    /**
     * The factory states to apply to the translation models.
     *
     * @var string[]
     */
    protected $translationStates = [];

    /**
     * Override the formatters of the translable attributes.
     *
     * @param  array $translatedAttributes
     * @return static
     */
    public function translatedAttributes(array $translatedAttributes)
    {
        $this->customTranslatableAttributes = $translatedAttributes;

        return $this;
    }

    /**
     * Override the formatters of the translation model.
     *
     * @param  array $translationAttributes
     * @return static
     */
    public function translationAttributes(array $translationAttributes)
    {
        return $this->translatedAttributes($translationAttributes);
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
     * Determine if the model should be Dimsav-translated.
     *
     * @return bool
     */
    protected function dimsavTranslatable()
    {
        return $this->getLocales() && in_array(Translatable::class, class_uses($this->model));
    }

    /**
     * Set the guessed formatters of the translated attributes.
     *
     * @return void
     */
    public function guessMultilingualFormatters()
    {
        if (!isset($this->model->translatable)) {
            return;
        }

        foreach ($this->model->translatable as $translatableAttributeKey) {
            $this->guessedFormatters[$translatableAttributeKey] = function () use ($translatableAttributeKey) {
                return collect($this->getRawLocales())
                    ->mapWithKeys(function ($locale) use ($translatableAttributeKey) {
                        return [$locale => $this->getTranslatableAttribute($translatableAttributeKey, $locale)];
                    })
                    ->all();
            };
        }
    }

    /**
     * Get the value for a translated attribute in one locale.
     *
     * @param  string $translatableAttributeKey
     * @param  string $locale
     * @return mixed
     */
    public function getTranslatableAttribute($translatableAttributeKey, $locale)
    {
        if (array_key_exists($translatableAttributeKey, $this->customTranslatableAttributes)) {
            return $this->customTranslatableAttributes[$translatableAttributeKey];
        }

        if (array_key_exists("$translatableAttributeKey->$locale", $this->customAttributes)) {
            return $this->customAttributes["$translatableAttributeKey->$locale"];
        }

        return $this->generator->sentence;
    }

    /**
     * Set the guessed formatters of the translation model.
     *
     * @param  bool $seeding
     * @return void
     */
    protected function guessDimsavFormatters($seeding)
    {
        // Don't use Translatable::getNewTranslation()
        // because it runs a query every first time it accesses $model->translations.
        $translationModelName = $this->model->getTranslationModelName();
        $translationModel = new $translationModelName;

        $this->guessedTranslationFormatters = $this->getGuessedColumnFormatters($translationModel, $seeding);

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
     * Determine if a model is the main model or one of its translation.
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

        $locales = [];

        foreach ($this->getRawLocales() as $key => $value) {
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

    /**
     * Get the locales to translate in without any further processing.
     * e.g. ['en', 'es' => ['MX', 'CO']] without expanding the country based locales.
     *
     * @return array
     */
    protected function getRawLocales()
    {
        return $this->locales ?: $this->populator->getLocales() ?: [];
    }
}
