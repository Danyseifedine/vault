<?php

namespace App\Services\Env;

use Dotenv\Dotenv;
use Dotenv\Repository\Adapter\ArrayAdapter;
use Dotenv\Repository\RepositoryBuilder;

/**
 * Parses pasted .env text.
 *
 * Delegates to vlucas/phpdotenv - the same parser Laravel itself uses - so
 * quoting, escapes and interpolation behave exactly as they do at runtime,
 * rather than however a hand-rolled regex happened to feel that day.
 */
class EnvParser
{
    /**
     * @return array<string, string> Later duplicates win, as dotenv does.
     */
    public function parse(string $contents): array
    {
        $adapter = ArrayAdapter::create()->get();
        $repository = RepositoryBuilder::createWithNoAdapters()
            ->addWriter($adapter)
            ->addReader($adapter)
            ->allowList([])
            ->make();

        $parsed = Dotenv::parse($contents);

        return array_map(static fn ($value) => (string) $value, $parsed);
    }
}
