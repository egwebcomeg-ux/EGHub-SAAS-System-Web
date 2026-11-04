<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/** The one password rule of every account form: at least $min characters with a letter and a digit in them. */
final class Passwords
{
    public const MIN = 8;

    /** Platform admins hold every restaurant: a longer one. */
    public const ADMIN_MIN = 10;

    public static function rule(int $min = self::MIN): Password
    {
        return Password::min($min)->letters()->numbers();
    }
}
