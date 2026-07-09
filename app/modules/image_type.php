<?php

declare(strict_types=1);

function ri_detect_local_image_type(string $path): string
{
    $size = @getimagesize($path);
    if (!is_array($size)) {
        return RI_IMAGE_TYPE_UNKNOWN;
    }

    $width = (int)($size[0] ?? 0);
    $height = (int)($size[1] ?? 0);
    return ri_image_type_from_dimensions($width, $height);
}

function ri_image_type_from_dimensions(int $width, int $height): string
{
    if ($width <= 0 || $height <= 0) {
        return RI_IMAGE_TYPE_UNKNOWN;
    }

    if ($width === $height) {
        return RI_IMAGE_TYPE_SQUARE;
    }

    return $width > $height ? RI_IMAGE_TYPE_PC : RI_IMAGE_TYPE_MOBILE;
}

function ri_normalize_image_type(string $value): string
{
    return match (strtolower(trim($value))) {
        RI_IMAGE_TYPE_PC => RI_IMAGE_TYPE_PC,
        RI_IMAGE_TYPE_MOBILE => RI_IMAGE_TYPE_MOBILE,
        RI_IMAGE_TYPE_SQUARE => RI_IMAGE_TYPE_SQUARE,
        default => RI_IMAGE_TYPE_UNKNOWN,
    };
}

function ri_requested_image_type(): ?string
{
    return ri_requested_image_type_filter()['type'];
}

function ri_requested_image_type_filter(): array
{
    foreach (['type', 'device', 'orientation'] as $key) {
        $value = $_GET[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            continue;
        }

        $type = ri_normalize_requested_image_type($value);
        if ($type === null) {
            ri_send_error(400, 'invalid_type', 'type must be pc or mobile.');
        }

        return [
            'type' => $type,
            'explicit' => true,
        ];
    }

    return [
        'type' => ri_auto_detect_image_type(),
        'explicit' => false,
    ];
}

function ri_normalize_requested_image_type(string $value): ?string
{
    return match (strtolower(trim($value))) {
        'pc', 'desktop', 'landscape', 'horizontal' => RI_IMAGE_TYPE_PC,
        'mobile', 'phone', 'portrait', 'vertical' => RI_IMAGE_TYPE_MOBILE,
        default => null,
    };
}

function ri_auto_detect_image_type(): ?string
{
    $viewportWidth = ri_first_numeric_header(['HTTP_SEC_CH_VIEWPORT_WIDTH', 'HTTP_VIEWPORT_WIDTH']);
    if ($viewportWidth !== null) {
        return $viewportWidth <= 768 ? RI_IMAGE_TYPE_MOBILE : RI_IMAGE_TYPE_PC;
    }

    $mobileHint = ri_first_header('HTTP_SEC_CH_UA_MOBILE');
    if ($mobileHint === '?1') {
        return RI_IMAGE_TYPE_MOBILE;
    }
    if ($mobileHint === '?0') {
        return RI_IMAGE_TYPE_PC;
    }

    $userAgent = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($userAgent === '') {
        return null;
    }

    if (preg_match('/mobile|iphone|ipod|android.*mobile|windows phone|blackberry|opera mini|webos/', $userAgent) === 1) {
        return RI_IMAGE_TYPE_MOBILE;
    }

    if (preg_match('/windows nt|macintosh|x11|cros|linux x86_64|linux i686|ipad/', $userAgent) === 1) {
        return RI_IMAGE_TYPE_PC;
    }

    return null;
}

function ri_first_numeric_header(array $keys): ?int
{
    foreach ($keys as $key) {
        $value = ri_first_header($key);
        if ($value !== null && ctype_digit($value)) {
            return (int)$value;
        }
    }

    return null;
}
