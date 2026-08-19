<?php

namespace Tests\Feature\Variables;

use App\Enums\Sensitivity;
use App\Services\Env\EnvClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The shared classifier's contract. resources/js/lib/env-classify.ts mirrors
 * these rules for the import preview; when a rule moves, it moves in both and
 * this fixture proves the server half.
 */
class EnvClassifierTest extends TestCase
{
    private EnvClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new EnvClassifier;
    }

    /**
     * @return array<string, array{string, string, Sensitivity}>
     */
    public static function sensitivityCases(): array
    {
        return [
            // Value betrays a secret regardless of the key.
            'connection string with password' => ['DATA', 'postgres://user:s3cr3t@db.local/app', Sensitivity::Critical],
            'private key block' => ['CERT', "-----BEGIN RSA PRIVATE KEY-----\nabc", Sensitivity::Critical],
            'stripe secret value' => ['ANY', 'sk_live_abc123DEF456ghi789', Sensitivity::Critical],
            'aws access key id value' => ['ANYTHING', 'AKIAIOSFODNN7EXAMPLE', Sensitivity::Critical],
            'jwt value' => ['ID', 'eyJhbGci.eyJzdWIiOiIx.SflKxwRJ', Sensitivity::Critical],
            'high-entropy blob' => ['THING', 'a1B2c3D4e5F6g7H8i9J0k1L2m3N4o5P6', Sensitivity::Critical],

            // Key names a secret.
            'password key' => ['DB_PASSWORD', 'anything', Sensitivity::Critical],
            'secret key' => ['APP_SECRET', 'x', Sensitivity::Critical],
            'api key' => ['STRIPE_API_KEY', 'x', Sensitivity::Critical],
            'suffix _KEY' => ['MAILGUN_KEY', 'x', Sensitivity::Critical],
            'private key key' => ['SSH_PRIVATE_KEY', 'x', Sensitivity::Critical],

            // PUBLIC vetoes critical.
            'public key is not critical' => ['STRIPE_PUBLIC_KEY', 'pk_live_abc', Sensitivity::Sensitive],
            'vite public key' => ['VITE_PUBLIC_KEY', 'x', Sensitivity::Sensitive],

            // Value is an endpoint / identity -> sensitive.
            'plain url' => ['APP_ENDPOINT', 'https://api.acme.test/v1', Sensitivity::Sensitive],
            'email value' => ['MAIL_FROM', 'noreply@acme.test', Sensitivity::Sensitive],

            // Config -> public.
            'app env' => ['APP_ENV', 'production', Sensitivity::Public],
            'log level' => ['LOG_LEVEL', 'debug', Sensitivity::Public],
            'debug flag' => ['APP_DEBUG', 'false', Sensitivity::Public],
            'port' => ['PORT', '8080', Sensitivity::Public],
            'feature flag' => ['FEATURE_BILLING_ENABLED', 'true', Sensitivity::Public],
            'boolean value neutral key' => ['SOMETHING_TOGGLE', 'true', Sensitivity::Public],

            // Key hints at something worth protecting.
            'host key' => ['DB_HOST', 'db.internal', Sensitivity::Sensitive],
            'client id' => ['OAUTH_CLIENT_ID', 'abc.apps.example', Sensitivity::Sensitive],

            // Unknown key + unremarkable value -> protect it.
            'unknown leans sensitive' => ['CUSTOM_THING', 'whatever', Sensitivity::Sensitive],
        ];
    }

    #[DataProvider('sensitivityCases')]
    public function test_sensitivity(string $key, string $value, Sensitivity $expected): void
    {
        $this->assertSame(
            $expected,
            $this->classifier->sensitivity($key, $value),
            "sensitivity({$key}, {$value})",
        );
    }

    public function test_sensitivity_works_key_only(): void
    {
        // The new-variable dialog has no value yet, so key-only must still work.
        $this->assertSame(Sensitivity::Critical, $this->classifier->sensitivity('DB_PASSWORD'));
        $this->assertSame(Sensitivity::Public, $this->classifier->sensitivity('APP_ENV'));
        $this->assertSame(Sensitivity::Sensitive, $this->classifier->sensitivity('GREETING'));
    }

    public function test_groups_map_known_providers(): void
    {
        $groups = $this->classifier->groups([
            'DB_HOST', 'DATABASE_URL', 'REDIS_URL', 'STRIPE_SECRET',
            'MAIL_HOST', 'AWS_ACCESS_KEY_ID', 'OPENAI_API_KEY', 'APP_ENV',
        ]);

        $this->assertSame('Database', $groups['DB_HOST']);
        $this->assertSame('Database', $groups['DATABASE_URL']);
        $this->assertSame('Cache', $groups['REDIS_URL']);
        $this->assertSame('Payments', $groups['STRIPE_SECRET']);
        $this->assertSame('Mail', $groups['MAIL_HOST']);
        $this->assertSame('AWS', $groups['AWS_ACCESS_KEY_ID']);
        $this->assertSame('AI', $groups['OPENAI_API_KEY']);
        $this->assertSame('App', $groups['APP_ENV']);
    }

    public function test_groups_cluster_shared_prefix(): void
    {
        $groups = $this->classifier->groups(['SHOPIFY_KEY', 'SHOPIFY_SECRET', 'LONELY_FLAG']);

        // Two SHOPIFY_* keys cluster; the lone one stays ungrouped.
        $this->assertSame('Shopify', $groups['SHOPIFY_KEY']);
        $this->assertSame('Shopify', $groups['SHOPIFY_SECRET']);
        $this->assertNull($groups['LONELY_FLAG']);
    }

    public function test_single_unknown_key_is_ungrouped(): void
    {
        $groups = $this->classifier->groups(['NAKEDKEY']);

        $this->assertNull($groups['NAKEDKEY']);
    }
}
