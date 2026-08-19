<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Str;

trait FormatsInitials
{
    /** "Dany Seifeddine" -> "DS"; a single name uses its first two letters' worth. */
    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return Str::upper(Str::substr($parts[0] ?? '?', 0, 1).Str::substr($parts[count($parts) - 1] ?? '', 0, 1));
    }
}
