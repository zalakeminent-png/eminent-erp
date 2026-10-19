<?php

namespace App\Support;

class Search
{
    /** Case-insensitive LIKE pattern with wildcards escaped; use with LOWER(column). */
    public static function like(string $term): string
    {
        return '%'.mb_strtolower(addcslashes($term, '%_\\')).'%';
    }
}
