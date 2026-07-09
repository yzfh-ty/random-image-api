<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

const RI_TEST_ENV_NAMES = [
    'RI_SERVER_HOST',
    'RI_SERVER_PORT',
    'RI_TRUST_PROXY',
    'RI_ALLOWED_HOSTS',
    'RI_IMAGE_ROOT',
    'RI_FOLDERS',
    'RI_LINK_FILES',
    'RI_ADMIN_PREFIX',
    'RI_ADMIN_ENABLED',
    'RI_ADMIN_TOKEN',
    'RI_ADMIN_ALLOW_QUERY_TOKEN',
    'RI_INDEX_DATABASE',
    'RI_INDEX_LOCK',
    'RI_INDEX_LOG',
    'RI_INDEX_LOG_MAX_BYTES',
    'RI_INDEX_LOG_BACKUPS',
    'RI_IMAGE_EXTENSIONS',
    'RI_ALLOW_SVG',
    'RI_MAX_IMAGE_BYTES',
    'RI_DEFAULT_MODE',
    'RI_REMOTE_REQUIRE_CHECKED',
    'RI_REMOTE_CHECK_MAX_AGE',
    'RI_LINKCHECK_TIMEOUT',
    'RI_LINKCHECK_CONCURRENCY',
    'RI_LINKCHECK_USER_AGENT',
    'RI_HTTP_PROXY',
    'RI_LINKCHECK_VERIFY_TLS',
    'RI_LINKCHECK_ALLOWED_HOSTS',
    'RI_LINKCHECK_BIND_RESOLVED_IP',
    'RI_SENDFILE_MODE',
    'RI_X_ACCEL_PREFIX',
];

$tests = [
    'random selection does not start a PHP session' => 'ri_test_random_selection_has_no_session',
    'weak admin token is rejected' => 'ri_test_weak_admin_token_is_rejected',
    'placeholder admin token is rejected' => 'ri_test_placeholder_admin_token_is_rejected',
    'invalid integer config is rejected' => 'ri_test_invalid_integer_config_is_rejected',
    'remote links are disabled without an allowlist' => 'ri_test_remote_links_require_allowlist',
    'private and unresolved remote hosts are rejected' => 'ri_test_private_and_unresolved_hosts_are_rejected',
    'remote stream fallback requires relaxed guard' => 'ri_test_remote_stream_fallback_requires_relaxed_guard',
    'host allowlist is enforced globally' => 'ri_test_host_allowlist_is_enforced_globally',
    'host allowlist matches ports exactly' => 'ri_test_host_allowlist_matches_ports_exactly',
    'x-accel prefix must be safe' => 'ri_test_x_accel_prefix_must_be_safe',
    'remote checks require image content type' => 'ri_test_remote_checks_require_image_content_type',
    'fake local images are not indexed' => 'ri_test_fake_local_images_are_rejected',
    'generated admin token is strong' => 'ri_test_generated_admin_token_is_strong',
    'health status has required fields' => 'ri_test_health_status_fields_exist',
    'health payload redacts index errors' => 'ri_test_health_payload_redacts_index_errors',
];

$passed = 0;
foreach ($tests as $name => $test) {
    ri_test_clear_env();
    $test();
    $passed++;
    echo "[OK] {$name}\n";
}
ri_test_clear_env();

echo "Security regression tests passed: {$passed}\n";

function ri_test_random_selection_has_no_session(): void
{
    $root = ri_test_temp_root();
    $index = null;
    try {
        ri_test_write_env($root, [
            'RI_FOLDERS=cat',
            'RI_ALLOWED_HOSTS=localhost,127.0.0.1,[::1]',
        ]);
        ri_test_write_png($root . '/images/cat/valid.png');

        ri_build_index($root);
        $config = ri_load_config($root);
        $index = ri_open_image_index($config, $root);
        $item = ri_random_item_for_all($index, $config, $root);

        ri_test_assert($item !== null, 'Expected a local image to be selected.');
        ri_test_assert(session_status() === PHP_SESSION_NONE, 'Random selection must not start a PHP session.');
    } finally {
        $index = null;
        ri_test_delete_tree($root);
    }
}

function ri_test_weak_admin_token_is_rejected(): void
{
    $root = ri_test_temp_root();
    try {
        ri_test_write_env($root, [
            'RI_FOLDERS=cat',
            'RI_ADMIN_ENABLED=true',
            'RI_ADMIN_TOKEN=short',
        ]);

        $thrown = false;
        try {
            ri_load_config($root);
        } catch (RuntimeException $error) {
            $thrown = str_contains($error->getMessage(), 'RI_ADMIN_TOKEN must be at least 32 characters');
        }

        ri_test_assert($thrown, 'Expected weak admin token configuration to fail.');
    } finally {
        ri_test_delete_tree($root);
    }
}

function ri_test_placeholder_admin_token_is_rejected(): void
{
    $root = ri_test_temp_root();
    try {
        ri_test_write_env($root, [
            'RI_FOLDERS=cat',
            'RI_ADMIN_ENABLED=true',
            'RI_ADMIN_TOKEN=replace-with-at-least-32-random-characters',
        ]);

        $thrown = false;
        try {
            ri_load_config($root);
        } catch (RuntimeException $error) {
            $thrown = str_contains($error->getMessage(), 'RI_ADMIN_TOKEN must be at least 32 characters');
        }

        ri_test_assert($thrown, 'Expected placeholder admin token configuration to fail.');
    } finally {
        ri_test_delete_tree($root);
    }
}

function ri_test_invalid_integer_config_is_rejected(): void
{
    $root = ri_test_temp_root();
    try {
        ri_test_write_env($root, [
            'RI_FOLDERS=cat',
            'RI_MAX_IMAGE_BYTES=abc',
        ]);

        $thrown = false;
        try {
            ri_load_config($root);
        } catch (RuntimeException $error) {
            $thrown = str_contains($error->getMessage(), 'RI_MAX_IMAGE_BYTES must be an integer');
        }

        ri_test_assert($thrown, 'Expected invalid integer configuration to fail.');
    } finally {
        ri_test_delete_tree($root);
    }
}

function ri_test_remote_links_require_allowlist(): void
{
    $root = ri_test_temp_root();
    try {
        ri_test_write_env($root, [
            'RI_FOLDERS=cat',
            'RI_ALLOWED_HOSTS=localhost,127.0.0.1,[::1]',
        ]);
        ri_test_write_file($root . '/images/cat/links.txt', "https://example.com/image.jpg\n");

        $stats = ri_build_index($root);
        $config = ri_load_config($root);

        ri_test_assert($stats['remoteCount'] === 0, 'Remote links should not be indexed without an allowlist.');
        ri_test_assert(
            !ri_is_safe_remote_url('https://example.com/image.jpg', $config, true),
            'Remote URL should be rejected when RI_LINKCHECK_ALLOWED_HOSTS is empty.'
        );
    } finally {
        ri_test_delete_tree($root);
    }
}

function ri_test_private_and_unresolved_hosts_are_rejected(): void
{
    $root = ri_test_temp_root();
    try {
        ri_test_write_env($root, [
            'RI_FOLDERS=cat',
            'RI_LINKCHECK_ALLOWED_HOSTS=localhost,unresolved.invalid',
        ]);
        $config = ri_load_config($root);

        ri_test_assert(!ri_is_safe_remote_url('http://localhost/image.jpg', $config, true), 'Localhost must be rejected.');
        ri_test_assert(!ri_is_safe_remote_url('https://unresolved.invalid/image.jpg', $config, true), 'Unresolved hosts must be rejected.');
    } finally {
        ri_test_delete_tree($root);
    }
}

function ri_test_remote_stream_fallback_requires_relaxed_guard(): void
{
    $config = [
        'linkCheck' => [
            'bindResolvedIp' => true,
            'proxy' => '',
        ],
    ];

    ri_test_assert(
        !ri_remote_stream_fallback_allowed($config),
        'Stream fallback must be blocked when resolved-IP binding is required without a proxy.'
    );

    $config['linkCheck']['proxy'] = 'http://127.0.0.1:8080';
    ri_test_assert(
        ri_remote_stream_fallback_allowed($config),
        'Stream fallback may run when a proxy is explicitly configured.'
    );

    $config['linkCheck']['proxy'] = '';
    $config['linkCheck']['bindResolvedIp'] = false;
    ri_test_assert(
        ri_remote_stream_fallback_allowed($config),
        'Stream fallback may run when resolved-IP binding is explicitly disabled.'
    );
}

function ri_test_host_allowlist_is_enforced_globally(): void
{
    $root = ri_test_temp_root();
    try {
        ri_test_write_env($root, [
            'RI_FOLDERS=cat',
            'RI_ALLOWED_HOSTS=example.com',
        ]);
        $config = ri_load_config($root);
        $_SERVER['HTTP_HOST'] = 'evil.example';

        $thrown = false;
        try {
            ri_enforce_request_host($config);
        } catch (RuntimeException $error) {
            $thrown = str_contains($error->getMessage(), 'Host is not allowed');
        }

        ri_test_assert($thrown, 'Every HTTP route should enforce RI_ALLOWED_HOSTS.');
    } finally {
        unset($_SERVER['HTTP_HOST']);
        ri_test_delete_tree($root);
    }
}

function ri_test_host_allowlist_matches_ports_exactly(): void
{
    ri_test_assert(ri_is_allowed_host('example.com', ['example.com']), 'Exact host should be allowed.');
    ri_test_assert(!ri_is_allowed_host('example.com:8443', ['example.com']), 'Unlisted ports must not be allowed.');
    ri_test_assert(ri_is_allowed_host('example.com:8443', ['example.com:8443']), 'Explicitly listed ports should be allowed.');
    ri_test_assert(!ri_is_allowed_host('[::1]:3001', ['[::1]', '[::1]:3000']), 'IPv6 ports must also match exactly.');
}

function ri_test_x_accel_prefix_must_be_safe(): void
{
    ri_test_assert(ri_is_safe_x_accel_prefix('/_protected_images'), 'A simple absolute internal prefix should be accepted.');
    ri_test_assert(ri_is_safe_x_accel_prefix('/internal/images-v1'), 'Nested safe prefixes should be accepted.');

    foreach (['', '/', '../secret', '/..', '/internal/../secret', '/internal images', '/internal?x=1', '/internal\\images'] as $prefix) {
        ri_test_assert(!ri_is_safe_x_accel_prefix($prefix), 'Unsafe x-accel prefix should be rejected: ' . $prefix);
    }

    $root = ri_test_temp_root();
    try {
        ri_test_write_env($root, [
            'RI_FOLDERS=cat',
            'RI_SENDFILE_MODE=x-accel',
            'RI_X_ACCEL_PREFIX=/../secret',
        ]);

        $thrown = false;
        try {
            ri_load_config($root);
        } catch (RuntimeException $error) {
            $thrown = str_contains($error->getMessage(), 'RI_X_ACCEL_PREFIX must be an absolute internal path prefix');
        }

        ri_test_assert($thrown, 'Unsafe RI_X_ACCEL_PREFIX configuration should fail.');
    } finally {
        ri_test_delete_tree($root);
    }
}

function ri_test_remote_checks_require_image_content_type(): void
{
    ri_test_assert(ri_remote_image_content_type_error('image/jpeg') === null, 'image/jpeg should be accepted.');
    ri_test_assert(ri_remote_image_content_type_error('image/svg+xml') === null, 'image/svg+xml should be accepted as an image MIME type.');
    ri_test_assert(
        ri_remote_image_content_type_error(null) === 'Remote response did not include an image Content-Type.',
        'Missing Content-Type should fail.'
    );
    ri_test_assert(
        ri_remote_image_content_type_error('text/html') === 'Remote response Content-Type is not an image.',
        'Non-image Content-Type should fail.'
    );

    $result = ri_public_remote_result([
        'ok' => true,
        'statusCode' => 200,
        'error' => null,
        'durationMs' => 12,
        'contentType' => 'text/html',
    ]);
    ri_test_assert($result['ok'] === false, 'Public remote result should reject non-image responses.');
    ri_test_assert($result['error'] === 'Remote response Content-Type is not an image.', 'Non-image error should be surfaced.');

    ri_test_assert(
        ri_should_retry_remote_head_with_get('HEAD', [
            'ok' => false,
            'statusCode' => 200,
            'error' => 'Remote response did not include an image Content-Type.',
        ]),
        'HEAD checks without Content-Type should retry with GET.'
    );

    $state = [
        'item' => ['url' => 'https://example.com/image.jpg'],
        'startedAt' => microtime(true),
        'done' => false,
        'checked' => null,
    ];
    $nextRequests = [];
    ri_update_remote_link_state_from_response($state, [
        'state' => 0,
        'url' => 'https://example.com/image.jpg',
        'method' => 'GET',
        'redirectsRemaining' => 5,
        'ok' => true,
        'statusCode' => 200,
        'error' => null,
        'contentType' => 'text/html',
        'response' => "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\n\r\n",
    ], $nextRequests, ['linkCheck' => ['allowedHosts' => ['example.com']]]);
    ri_test_assert($state['done'] === true, 'Multi-check state should finish non-image responses.');
    ri_test_assert($state['checked']['ok'] === false, 'Multi-check state should reject non-image responses.');
}

function ri_test_fake_local_images_are_rejected(): void
{
    $root = ri_test_temp_root();
    try {
        ri_test_write_env($root, [
            'RI_FOLDERS=cat',
            'RI_ALLOWED_HOSTS=localhost,127.0.0.1,[::1]',
        ]);
        ri_test_write_file($root . '/images/cat/fake.jpg', 'not an image');

        $stats = ri_build_index($root);

        ri_test_assert($stats['localCount'] === 0, 'Unreadable image content must not be indexed.');
        ri_test_assert($stats['warnings'] !== [], 'Unreadable image content should produce an index warning.');
    } finally {
        ri_test_delete_tree($root);
    }
}

function ri_test_generated_admin_token_is_strong(): void
{
    $token = ri_generate_admin_token();

    ri_test_assert(strlen($token) >= 32, 'Generated token must satisfy the admin token length requirement.');
    ri_test_assert(ri_is_strong_admin_token($token), 'Generated token must pass admin token validation.');
    ri_test_assert(preg_match('/^[A-Za-z0-9_-]+$/', $token) === 1, 'Generated token should be URL-safe.');
}

function ri_test_health_status_fields_exist(): void
{
    $root = ri_test_temp_root();
    $index = null;
    try {
        ri_test_write_env($root, [
            'RI_FOLDERS=cat',
            'RI_ALLOWED_HOSTS=localhost,127.0.0.1,[::1]',
        ]);
        ri_test_write_png($root . '/images/cat/valid.png');

        ri_build_index($root);
        $config = ri_load_config($root);
        $index = ri_open_image_index($config, $root);
        $status = ri_index_status($index, $config);

        ri_test_assert($status['schemaVersion'] === RI_IMAGE_INDEX_SCHEMA_VERSION, 'Health status needs the current schema version.');
        ri_test_assert($status['lastIndexedError'] === null, 'Health status should have no index error after a successful index.');
        ri_test_assert(isset($status['remoteLinkChecks']['serviceable']), 'Health status needs remote serviceable count.');
    } finally {
        $index = null;
        ri_test_delete_tree($root);
    }
}

function ri_test_health_payload_redacts_index_errors(): void
{
    $payload = ri_health_payload([
        'schemaVersion' => RI_IMAGE_INDEX_SCHEMA_VERSION,
        'lastIndexedAt' => null,
        'lastIndexedError' => 'C:\\secret\\images\\cat\\bad.jpg failed',
        'total' => 0,
        'remoteLinkChecks' => [
            'serviceable' => 0,
        ],
    ]);

    ri_test_assert($payload['ok'] === false, 'Health payload should report unhealthy when an index error exists.');
    ri_test_assert($payload['hasIndexError'] === true, 'Health payload should expose only an index-error flag.');
    ri_test_assert(!array_key_exists('lastIndexedError', $payload), 'Health payload must not expose raw index errors.');
    ri_test_assert(!str_contains((string)json_encode($payload), 'secret'), 'Health payload must not leak path fragments.');
}

function ri_test_temp_root(): string
{
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'random-image-api-test-' . bin2hex(random_bytes(6));
    if (!mkdir($root . '/images/cat', 0777, true) && !is_dir($root . '/images/cat')) {
        throw new RuntimeException('Cannot create test directory.');
    }

    return $root;
}

function ri_test_write_env(string $root, array $lines): void
{
    ri_test_write_file($root . '/.env', implode("\n", $lines) . "\n");
}

function ri_test_write_png(string $path): void
{
    $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=';
    ri_test_write_file($path, (string)base64_decode($png, true));
}

function ri_test_write_file(string $path, string $content): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create directory: ' . $dir);
    }

    file_put_contents($path, $content);
}

function ri_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function ri_test_clear_env(): void
{
    unset(
        $_SERVER['HTTP_HOST'],
        $_SERVER['HTTP_X_FORWARDED_HOST'],
        $_SERVER['HTTP_X_FORWARDED_PROTO'],
        $_SERVER['HTTPS']
    );

    foreach (RI_TEST_ENV_NAMES as $name) {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }

    foreach (array_keys($_ENV) as $name) {
        if (str_starts_with((string)$name, 'RI_')) {
            putenv((string)$name);
            unset($_ENV[$name]);
        }
    }

    foreach (array_keys($_SERVER) as $name) {
        if (str_starts_with((string)$name, 'RI_')) {
            putenv((string)$name);
            unset($_SERVER[$name]);
        }
    }
}

function ri_test_delete_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $items = scandir($path);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $child = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($child)) {
            ri_test_delete_tree($child);
            continue;
        }

        unlink($child);
    }

    rmdir($path);
}
