<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    /** Lets controllers call authorize(), which delegates to our policies. */
    use AuthorizesRequests;

    /**
     * The dashboard tab named in the URL, or the default when absent or
     * unknown. Resolved here, server-side, so the FIRST render already stands
     * on the right tab - a client that reads the URL after mounting corrects
     * itself in view of the user.
     *
     * @param  list<string>  $valid
     */
    protected function screen(array $valid, string $default): string
    {
        $asked = request()->query('screen');

        return in_array($asked, $valid, true) ? $asked : $default;
    }
}
