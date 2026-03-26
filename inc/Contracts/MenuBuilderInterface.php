<?php

    namespace Inc\Contracts;


    /**
     * Контракт для классов, строящих конфигурацию меню.
     */
    interface MenuBuilderInterface
    {
        /**
         * Построить массив страниц меню.
         * @return array
         */
        public function buildPages(): array;

        /**
         * Построить массив подстраниц меню.
         * @return array
         */
        public function buildSubPages(): array;
    }