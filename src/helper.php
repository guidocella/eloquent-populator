<?php

use EloquentPopulator\ModelPopulator;
use EloquentPopulator\Populator;

if (!function_exists('populator')) {
    /**
     * Create a Populator instance, optionally adding a model.
     *
     * @param  mixed $arguments class|class,state|class,quantity|class,state,quantity
     * @return Populator|ModelPopulator
     */
    function populator(...$arguments)
    {
        $populator = app(Populator::class);

        if (isset($arguments[2])) {
            return $populator->add($arguments[0], $arguments[2])->states($arguments[1]);
        }

        if (isset($arguments[1])) {
            return $populator->add($arguments[0], $arguments[1]);
        }

        if (isset($arguments[0])) {
            return $populator->add($arguments[0]);
        }

        return $populator;
    }
}
