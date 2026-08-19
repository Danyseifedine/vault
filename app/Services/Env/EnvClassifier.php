<?php

namespace App\Services\Env;

use App\Enums\Sensitivity;
use Illuminate\Support\Str;

/**
 * Guesses a variable's sensitivity and its group from its key and value.
 *
 * This is the ONE classifier. It used to be two that quietly disagreed - a
 * key-only guess on the server and a different key-only guess in the import
 * preview - so a variable could show up "sensitive" in the parse and land as
 * "critical" in the vault. The rules here are mirrored, line for line, by
 * resources/js/lib/env-classify.ts (the browser preview); the fixture in
 * tests/Feature/Variables/EnvClassifierTest.php pins both to the same answers.
 *
 * Nothing here is authoritative in the security sense - a human sets the real
 * sensitivity and the reveal policy is what actually gates a value. This only
 * has to be a good, safe first guess: when unsure it leans toward MORE
 * protection, never less.
 */
class EnvClassifier
{
    /**
     * Value shapes that mean "this is a live secret" no matter what the key is
     * called. A credential in the value is a credential.
     */
    private const VALUE_CRITICAL = [
        // A private key / certificate block.
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        // scheme://user:password@host - a connection string carrying a password.
        '#^[a-z][a-z0-9+.\-]*://[^/\s:@]+:[^/\s@]+@#i',
        // Named secret token shapes from common providers.
        '/^sk_(live|test)_[A-Za-z0-9]+/',        // Stripe secret
        '/^rk_(live|test)_[A-Za-z0-9]+/',        // Stripe restricted
        '/^whsec_[A-Za-z0-9]+/',                 // Stripe webhook secret
        '/^AKIA[0-9A-Z]{16}$/',                  // AWS access key id
        '/^ASIA[0-9A-Z]{16}$/',                  // AWS temporary key id
        '/^gh[posru]_[A-Za-z0-9]{20,}/',         // GitHub tokens
        '/^github_pat_[A-Za-z0-9_]{20,}/',       // GitHub fine-grained PAT
        '/^xox[baprs]-[A-Za-z0-9-]{10,}/',       // Slack token
        '/^AIza[0-9A-Za-z_\-]{35}$/',            // Google API key
        '/^sk-[A-Za-z0-9\-]{20,}/',              // OpenAI-style secret
        '/^eyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+$/', // JWT
        '/^\$2[aby]\$[0-9]{2}\$/',               // bcrypt hash
    ];

    /** Key words that mean the value is a secret. PUBLIC vetoes this. */
    private const KEY_CRITICAL = '/(SECRET|PASSWORD|PASSWD|PASSPHRASE|PRIVATE|CREDENTIAL|_KEY$|^KEY$|API_?KEY|ACCESS_?KEY|SIGNING|ENCRYPT|CIPHER|SALT|MASTER|AUTH_?TOKEN|ACCESS_?TOKEN|REFRESH_?TOKEN|SERVICE_ACCOUNT|PRIVATE_?KEY)/i';

    /** Key words that mean the value is useful to an attacker, but not a key. */
    private const KEY_SENSITIVE = '/(TOKEN|KEY|DSN|URL|URI|HOST|ENDPOINT|WEBHOOK|CLIENT_?ID|ACCOUNT|BUCKET|USER(NAME)?|DOMAIN|_SID$|APP_?ID|PROJECT_?ID|SENDER|MAILBOX|CONNECTION)/i';

    /** Key words that are plainly configuration, not secrets. */
    private const KEY_PUBLIC = '/^(APP_ENV|APP_NAME|APP_DEBUG|APP_LOCALE|APP_FALLBACK_LOCALE|NODE_ENV|ENVIRONMENT|ENV|DEBUG|LOG_LEVEL|LOG_CHANNEL|LOG_DEPRECATIONS|TIMEZONE|TZ|LOCALE|VERSION|PORT|MAX_[A-Z_]+|MIN_[A-Z_]+|TIMEOUT|RETRIES?|PAGE_SIZE|PER_PAGE|[A-Z_]*_TTL|[A-Z_]+_ENABLED|[A-Z_]+_DISABLED|ENABLE_[A-Z_]+|FEATURE_[A-Z_]+|[A-Z_]+_MODE|DEFAULT_[A-Z_]+|[A-Z_]+_DRIVER|BROADCAST_CONNECTION|QUEUE_CONNECTION|SESSION_DRIVER|SESSION_LIFETIME|FILESYSTEM_DISK|CACHE_STORE)$/i';

    /** Provider and concern prefixes, most specific first. */
    private const GROUP_MAP = [
        'Database' => '/^(DB|DATABASE|POSTGRES|POSTGRESQL|PG|MYSQL|MARIADB|MONGO(DB)?|SQLITE|SQL|PLANETSCALE|COCKROACH|TURSO)_/i',
        'Cache' => '/^(REDIS|CACHE|MEMCACHED?|VALKEY)_/i',
        'Queue' => '/^(QUEUE|RABBITMQ|AMQP|KAFKA|SQS|HORIZON|BEANSTALK)_/i',
        'Mail' => '/^(MAIL|SMTP|SENDGRID|POSTMARK|MAILGUN|MAILCHIMP|SES|RESEND|SENDINBLUE|BREVO)_/i',
        'Payments' => '/^(STRIPE|PAYPAL|PADDLE|BRAINTREE|SQUARE|BILLING|PAYMENT|LEMON_?SQUEEZY)_/i',
        'Auth' => '/^(AUTH|JWT|OAUTH|SESSION|SANCTUM|PASSPORT|CLERK|AUTH0|OKTA|KEYCLOAK|SAML|LDAP|WORKOS)_/i',
        'AI' => '/^(OPENAI|ANTHROPIC|CLAUDE|HUGGINGFACE|COHERE|REPLICATE|GEMINI|MISTRAL|GROQ|OLLAMA)_/i',
        'Messaging' => '/^(TWILIO|VONAGE|NEXMO|PLIVO|MESSAGEBIRD)_/i',
        'Realtime' => '/^(PUSHER|ABLY|SOKETI|CENTRIFUGO|WEBSOCKET|WS)_/i',
        'Observability' => '/^(SENTRY|BUGSNAG|DATADOG|NEW_?RELIC|HONEYBADGER|ROLLBAR|TELEMETRY|OTEL|FLARE|AXIOM)_/i',
        'Search' => '/^(ALGOLIA|ELASTIC(SEARCH)?|MEILISEARCH|TYPESENSE|OPENSEARCH)_/i',
        'Analytics' => '/^(MIXPANEL|SEGMENT|AMPLITUDE|POSTHOG|GA|GTM|PLAUSIBLE|FATHOM)_/i',
        'Storage' => '/^(S3|MINIO|CLOUDINARY|UPLOADCARE|BUNNY|WASABI|BACKBLAZE|FILESYSTEM)_/i',
        'AWS' => '/^AWS_/i',
        'Google Cloud' => '/^(GCP|GCLOUD|GOOGLE|FIREBASE|GCS)_/i',
        'Azure' => '/^AZURE_/i',
        'Version control' => '/^(GITHUB|GITLAB|BITBUCKET)_/i',
        'App' => '/^(APP|VITE|NEXT_PUBLIC|LOG|SERVER|NODE|PHP)_/i',
    ];

    public function sensitivity(string $key, string $value = ''): Sensitivity
    {
        $value = trim($value);
        $public = (bool) preg_match('/PUBLIC/i', $key);

        // 1. The value itself betrays a live secret - trumps everything.
        if (! $public && $this->valueLooksCritical($value)) {
            return Sensitivity::Critical;
        }

        // 2. The key names a secret (and does not say "public").
        if (! $public && preg_match(self::KEY_CRITICAL, $key)) {
            return Sensitivity::Critical;
        }

        // 3. The value is an endpoint, address or identity - useful, not a key.
        if ($this->valueLooksSensitive($value)) {
            return Sensitivity::Sensitive;
        }

        // 4. Plainly configuration - only when the value does not look secret.
        if (preg_match(self::KEY_PUBLIC, $key) || $this->valueLooksPublic($value)) {
            return Sensitivity::Public;
        }

        // 5. The key hints at something worth protecting.
        if (preg_match(self::KEY_SENSITIVE, $key)) {
            return Sensitivity::Sensitive;
        }

        // 6. Unknown key, unremarkable value: protect it. In a secrets manager
        //    an unknown is more safely over-protected than exposed.
        return Sensitivity::Sensitive;
    }

    /**
     * A group name per key, provider-mapped first, then clustered by a shared
     * prefix so a custom provider (SHOPIFY_KEY, SHOPIFY_SECRET) still lands
     * together. Keys with no confident home stay null (ungrouped).
     *
     * @param  array<int, string>  $keys
     * @return array<string, string|null>
     */
    public function groups(array $keys): array
    {
        $result = [];
        $leftover = [];

        foreach ($keys as $key) {
            $group = $this->knownGroup($key);

            if ($group !== null) {
                $result[$key] = $group;

                continue;
            }

            $result[$key] = null;
            $token = $this->prefixToken($key);

            if ($token !== null) {
                $leftover[$token][] = $key;
            }
        }

        // A shared prefix only becomes a group when at least two keys share it -
        // a lone FOO_BAR is not worth a group of one.
        foreach ($leftover as $token => $group) {
            if (count($group) < 2) {
                continue;
            }

            foreach ($group as $key) {
                $result[$key] = Str::title(strtolower($token));
            }
        }

        return $result;
    }

    private function valueLooksCritical(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        foreach (self::VALUE_CRITICAL as $pattern) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }

        // A single long, mixed-case-and-digit token with no structure reads as a
        // random secret. UUIDs are identifiers, not secrets, so they are exempt.
        return preg_match('/^[A-Za-z0-9+\/=_\-]{32,}$/', $value)
            && preg_match('/[A-Za-z]/', $value)
            && preg_match('/[0-9]/', $value)
            && ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }

    private function valueLooksSensitive(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        return (bool) (
            preg_match('#^[a-z][a-z0-9+.\-]*://#i', $value)          // a URL/URI
            || preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $value)     // an email
            || preg_match('/^\d{1,3}(\.\d{1,3}){3}(:\d+)?$/', $value) // an IPv4[:port]
        );
    }

    private function valueLooksPublic(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        $lower = strtolower($value);

        return in_array($lower, [
            'true', 'false', 'null', 'none', 'yes', 'no', 'on', 'off',
            'production', 'staging', 'development', 'local', 'testing', 'test',
            'debug', 'info', 'notice', 'warning', 'error', 'critical',
            'sync', 'database', 'redis', 'file', 'array', 'daily', 'stack',
        ], true) || preg_match('/^\d+$/', $value) === 1;
    }

    private function knownGroup(string $key): ?string
    {
        foreach (self::GROUP_MAP as $name => $pattern) {
            if (preg_match($pattern, $key)) {
                return $name;
            }
        }

        return null;
    }

    /** The first SNAKE_CASE token, when a key has more than one. */
    private function prefixToken(string $key): ?string
    {
        if (! str_contains($key, '_')) {
            return null;
        }

        // Everything before the first underscore. A key that starts with one
        // (_FOO) has an empty prefix and clusters with nothing.
        $token = explode('_', $key, 2)[0];

        return $token === '' ? null : $token;
    }
}
