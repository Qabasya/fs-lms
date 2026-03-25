<?php

    namespace Inc\Core;

    class Activate
    {
        public static function activate(): void {
            flush_rewrite_rules();
        }
    }