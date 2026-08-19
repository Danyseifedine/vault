import type { Sensitivity } from '@/components/vault/data/sensitivity';

/**
 * The import preview's copy of app/Services/Env/EnvClassifier.php.
 *
 * These rules are mirrored line for line so the parse and the vault agree - a
 * variable that previews as "critical / Payments" is created that way. The
 * server re-classifies authoritatively on import; this only has to match it, so
 * when a rule changes it changes in BOTH files. The PHP fixture in
 * tests/Feature/Variables/EnvClassifierTest.php is the shared source of truth.
 */

const VALUE_CRITICAL: RegExp[] = [
    /-----BEGIN [A-Z ]*PRIVATE KEY-----/,
    /^[a-z][a-z0-9+.-]*:\/\/[^/\s:@]+:[^/\s@]+@/i,
    /^sk_(live|test)_[A-Za-z0-9]+/,
    /^rk_(live|test)_[A-Za-z0-9]+/,
    /^whsec_[A-Za-z0-9]+/,
    /^AKIA[0-9A-Z]{16}$/,
    /^ASIA[0-9A-Z]{16}$/,
    /^gh[posru]_[A-Za-z0-9]{20,}/,
    /^github_pat_[A-Za-z0-9_]{20,}/,
    /^xox[baprs]-[A-Za-z0-9-]{10,}/,
    /^AIza[0-9A-Za-z_-]{35}$/,
    /^sk-[A-Za-z0-9-]{20,}/,
    /^eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/,
    /^\$2[aby]\$[0-9]{2}\$/,
];

const KEY_CRITICAL =
    /(SECRET|PASSWORD|PASSWD|PASSPHRASE|PRIVATE|CREDENTIAL|_KEY$|^KEY$|API_?KEY|ACCESS_?KEY|SIGNING|ENCRYPT|CIPHER|SALT|MASTER|AUTH_?TOKEN|ACCESS_?TOKEN|REFRESH_?TOKEN|SERVICE_ACCOUNT|PRIVATE_?KEY)/i;

const KEY_SENSITIVE =
    /(TOKEN|KEY|DSN|URL|URI|HOST|ENDPOINT|WEBHOOK|CLIENT_?ID|ACCOUNT|BUCKET|USER(NAME)?|DOMAIN|_SID$|APP_?ID|PROJECT_?ID|SENDER|MAILBOX|CONNECTION)/i;

const KEY_PUBLIC =
    /^(APP_ENV|APP_NAME|APP_DEBUG|APP_LOCALE|APP_FALLBACK_LOCALE|NODE_ENV|ENVIRONMENT|ENV|DEBUG|LOG_LEVEL|LOG_CHANNEL|LOG_DEPRECATIONS|TIMEZONE|TZ|LOCALE|VERSION|PORT|MAX_[A-Z_]+|MIN_[A-Z_]+|TIMEOUT|RETRIES?|PAGE_SIZE|PER_PAGE|[A-Z_]*_TTL|[A-Z_]+_ENABLED|[A-Z_]+_DISABLED|ENABLE_[A-Z_]+|FEATURE_[A-Z_]+|[A-Z_]+_MODE|DEFAULT_[A-Z_]+|[A-Z_]+_DRIVER|BROADCAST_CONNECTION|QUEUE_CONNECTION|SESSION_DRIVER|SESSION_LIFETIME|FILESYSTEM_DISK|CACHE_STORE)$/i;

// Most specific first - the first match wins, exactly as in PHP.
const GROUP_MAP: [string, RegExp][] = [
    ['Database', /^(DB|DATABASE|POSTGRES|POSTGRESQL|PG|MYSQL|MARIADB|MONGO(DB)?|SQLITE|SQL|PLANETSCALE|COCKROACH|TURSO)_/i],
    ['Cache', /^(REDIS|CACHE|MEMCACHED?|VALKEY)_/i],
    ['Queue', /^(QUEUE|RABBITMQ|AMQP|KAFKA|SQS|HORIZON|BEANSTALK)_/i],
    ['Mail', /^(MAIL|SMTP|SENDGRID|POSTMARK|MAILGUN|MAILCHIMP|SES|RESEND|SENDINBLUE|BREVO)_/i],
    ['Payments', /^(STRIPE|PAYPAL|PADDLE|BRAINTREE|SQUARE|BILLING|PAYMENT|LEMON_?SQUEEZY)_/i],
    ['Auth', /^(AUTH|JWT|OAUTH|SESSION|SANCTUM|PASSPORT|CLERK|AUTH0|OKTA|KEYCLOAK|SAML|LDAP|WORKOS)_/i],
    ['AI', /^(OPENAI|ANTHROPIC|CLAUDE|HUGGINGFACE|COHERE|REPLICATE|GEMINI|MISTRAL|GROQ|OLLAMA)_/i],
    ['Messaging', /^(TWILIO|VONAGE|NEXMO|PLIVO|MESSAGEBIRD)_/i],
    ['Realtime', /^(PUSHER|ABLY|SOKETI|CENTRIFUGO|WEBSOCKET|WS)_/i],
    ['Observability', /^(SENTRY|BUGSNAG|DATADOG|NEW_?RELIC|HONEYBADGER|ROLLBAR|TELEMETRY|OTEL|FLARE|AXIOM)_/i],
    ['Search', /^(ALGOLIA|ELASTIC(SEARCH)?|MEILISEARCH|TYPESENSE|OPENSEARCH)_/i],
    ['Analytics', /^(MIXPANEL|SEGMENT|AMPLITUDE|POSTHOG|GA|GTM|PLAUSIBLE|FATHOM)_/i],
    ['Storage', /^(S3|MINIO|CLOUDINARY|UPLOADCARE|BUNNY|WASABI|BACKBLAZE|FILESYSTEM)_/i],
    ['AWS', /^AWS_/i],
    ['Google Cloud', /^(GCP|GCLOUD|GOOGLE|FIREBASE|GCS)_/i],
    ['Azure', /^AZURE_/i],
    ['Version control', /^(GITHUB|GITLAB|BITBUCKET)_/i],
    ['App', /^(APP|VITE|NEXT_PUBLIC|LOG|SERVER|NODE|PHP)_/i],
];

const PUBLIC_VALUES = new Set([
    'true', 'false', 'null', 'none', 'yes', 'no', 'on', 'off',
    'production', 'staging', 'development', 'local', 'testing', 'test',
    'debug', 'info', 'notice', 'warning', 'error', 'critical',
    'sync', 'database', 'redis', 'file', 'array', 'daily', 'stack',
]);

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

function valueLooksCritical(value: string): boolean {
    if (value === '') {
return false;
}

    if (VALUE_CRITICAL.some((re) => re.test(value))) {
return true;
}

    return (
        /^[A-Za-z0-9+/=_-]{32,}$/.test(value) &&
        /[A-Za-z]/.test(value) &&
        /[0-9]/.test(value) &&
        !UUID.test(value)
    );
}

function valueLooksSensitive(value: string): boolean {
    if (value === '') {
return false;
}

    return (
        /^[a-z][a-z0-9+.-]*:\/\//i.test(value) ||
        /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(value) ||
        /^\d{1,3}(\.\d{1,3}){3}(:\d+)?$/.test(value)
    );
}

function valueLooksPublic(value: string): boolean {
    if (value === '') {
return false;
}

    return PUBLIC_VALUES.has(value.toLowerCase()) || /^\d+$/.test(value);
}

/** Sensitivity from a key and (optionally) its value. Mirrors EnvClassifier. */
export function classifySensitivity(key: string, value = ''): Sensitivity {
    const v = value.trim();
    const isPublic = /PUBLIC/i.test(key);

    if (!isPublic && valueLooksCritical(v)) {
return 'critical';
}

    if (!isPublic && KEY_CRITICAL.test(key)) {
return 'critical';
}

    if (valueLooksSensitive(v)) {
return 'sensitive';
}

    if (KEY_PUBLIC.test(key) || valueLooksPublic(v)) {
return 'public';
}

    if (KEY_SENSITIVE.test(key)) {
return 'sensitive';
}

    return 'sensitive';
}

function knownGroup(key: string): string | null {
    for (const [name, pattern] of GROUP_MAP) {
        if (pattern.test(key)) {
return name;
}
    }

    return null;
}

function prefixToken(key: string): string | null {
    if (!key.includes('_')) {
return null;
}

    const token = key.slice(0, key.indexOf('_'));

    return token === '' ? null : token;
}

/**
 * A group name per key: provider-mapped first, then clustered by a shared
 * prefix (two or more), else null. Mirrors EnvClassifier::groups.
 */
export function classifyGroups(keys: string[]): Record<string, string | null> {
    const result: Record<string, string | null> = {};
    const leftover: Record<string, string[]> = {};

    for (const key of keys) {
        const group = knownGroup(key);
        result[key] = group;

        if (group === null) {
            const token = prefixToken(key);

            if (token !== null) {
(leftover[token] ??= []).push(key);
}
        }
    }

    for (const [token, group] of Object.entries(leftover)) {
        if (group.length < 2) {
continue;
}

        const name = token.charAt(0).toUpperCase() + token.slice(1).toLowerCase();

        for (const key of group) {
result[key] = name;
}
    }

    return result;
}
