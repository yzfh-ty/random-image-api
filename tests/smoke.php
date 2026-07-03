<?php

declare(strict_types=1);

require __DIR__ . '/../app/random_image.php';

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'random-image-api-' . bin2hex(random_bytes(4));
mkdir($root . '/images/erciyuan/wallpaper', 0777, true);
mkdir($root . '/images/test', 0777, true);
file_put_contents($root . '/images/erciyuan/001.jpg', 'image');
file_put_contents($root . '/images/erciyuan/links.txt', "https://cdn.example.com/root.jpg\n");
file_put_contents($root . '/images/erciyuan/wallpaper/002.jpg', 'image');
file_put_contents($root . '/images/erciyuan/wallpaper/links.txt', "https://cdn.example.com/wallpaper.webp?width=1200\n");
file_put_contents($root . '/images/test/hidden.jpg', 'hidden');
file_put_contents($root . '/config.json', json_encode([
    'imageRoot' => 'images',
    'folders' => ['erciyuan'],
    'linkFiles' => ['links.txt'],
    'adminPrefix' => '/_api',
    'indexDatabase' => '.runtime/image-index.sqlite',
    'imageExtensions' => ['.jpg', '.webp'],
], JSON_UNESCAPED_SLASHES));

$stats = ri_build_index($root);
ri_assert($stats['total'] === 4, 'index includes parent image, child image, and txt links');
ri_assert($stats['localCount'] === 2, 'index counts local images');
ri_assert($stats['remoteCount'] === 2, 'index counts remote links');

$config = ri_load_config($root);
$index = ri_open_image_index($config, $root);
$all = ri_items_for_all($index, $config, $root);
$parent = ri_items_for_path($index, $config, $root, 'erciyuan');
$child = ri_items_for_path($index, $config, $root, 'erciyuan/wallpaper');
$hidden = ri_items_for_path($index, $config, $root, 'test');

ri_assert(count($all) === 4, 'root pool reads all configured indexed images');
ri_assert(count($parent) === 4, 'parent category includes root links and child category items');
ri_assert(count($child) === 2, 'child category includes child image and txt link');
ri_assert($hidden === [], 'unconfigured top-level folder is ignored');

$localItems = ri_filter_items($parent, 'local');
$remoteItems = ri_filter_items($parent, 'remote');
ri_assert(count($localItems) === 2, 'local source filter works');
ri_assert(count($remoteItems) === 2, 'remote source filter works');

$_SERVER['HTTP_HOST'] = 'example.com';
$_SERVER['HTTPS'] = 'off';
$localUrl = ri_public_url($localItems[0], $config);
$remoteUrl = ri_public_url($remoteItems[0], $config);
ri_assert(str_starts_with($localUrl, 'http://example.com/erciyuan/'), 'local public URL uses category short route');
ri_assert(str_starts_with($remoteUrl, 'http://example.com/erciyuan/'), 'remote public URL uses category short route');
ri_assert(!str_contains($localUrl, '_assets') && !str_contains($remoteUrl, '_remote'), 'legacy prefixes are not exposed');

$foundLocal = ri_find_item_by_index($index, $config, $root, 'erciyuan', $localItems[0]['id'], $localItems[0]['extension']);
ri_assert($foundLocal !== null && $foundLocal['sourceType'] === 'local', 'short local asset route can find item by folder id extension');
$foundRemote = ri_find_item_by_index($index, $config, $root, 'erciyuan', $remoteItems[0]['id'], $remoteItems[0]['extension']);
ri_assert($foundRemote !== null && $foundRemote['sourceType'] === 'remote', 'short remote asset route can find item by folder id extension');

$picked = ri_pick_item($localItems, ri_image_key($localItems[0]));
ri_assert(ri_image_key($picked) !== ri_image_key($localItems[0]), 'refresh avoids the last served image when possible');
ri_assert(ri_is_browser_accept('text/html,application/xhtml+xml'), 'browser accept header is detected');
ri_assert(!ri_is_browser_accept('image/avif,image/webp,*/*'), 'image accept header is not treated as browser page access');
ri_assert(ri_extension_from_url('https://cdn.example.com/a.webp?x=1', $config) === 'webp', 'remote extension is read from URL path');
ri_assert(ri_extension_from_url('https://cdn.example.com/no-extension', $config) === 'jpg', 'remote URL without extension gets default jpg suffix');

$index = null;
ri_delete_tree($root);
echo "PHP smoke tests passed.\n";

function ri_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

function ri_is_browser_accept(string $accept): bool
{
    $_SERVER['HTTP_ACCEPT'] = $accept;
    return ri_is_browser_request();
}

function ri_delete_tree(string $path): void
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
            ri_delete_tree($child);
            continue;
        }

        unlink($child);
    }

    rmdir($path);
}
