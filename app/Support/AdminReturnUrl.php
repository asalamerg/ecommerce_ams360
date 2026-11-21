<?php

namespace App\Support;

/**
 * Where an admin form goes back to after a save: the list page it was opened
 * from, with its search, filter and page. Only that list's own address is
 * accepted, so the value can never send the browser to another site.
 */
class AdminReturnUrl
{
    public static function for(string $list, mixed $back): string
    {
        $back = is_string($back) ? $back : '';

        return preg_match('~^'.preg_quote($list, '~').'(\?[^\s#]*)?\z~', $back) === 1 ? $back : $list;
    }
}
