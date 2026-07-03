<?php

declare(strict_types=1);

function ri_is_safe_remote_url(string $url, array $config, bool $resolveDns): bool
{
    if (!ri_is_http_url($url)) {
        return false;
    }

    $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($host) || !ri_is_allowed_remote_host($host, $config['linkCheck']['allowedHosts'])) {
        return false;
    }

    return !ri_is_private_or_local_host($host, $resolveDns);
}

function ri_is_safe_allowed_remote_host(string $host): bool
{
    if (str_starts_with($host, '*.')) {
        $host = substr($host, 2);
    }

    return ri_is_safe_host($host);
}

function ri_is_allowed_remote_host(string $host, array $allowedHosts): bool
{
    if ($allowedHosts === []) {
        return false;
    }

    $host = strtolower(trim($host, '[]'));
    foreach ($allowedHosts as $allowedHost) {
        if (!is_string($allowedHost)) {
            continue;
        }

        $allowedHost = strtolower(trim($allowedHost));
        if (str_starts_with($allowedHost, '*.')) {
            $suffix = substr($allowedHost, 1);
            if (str_ends_with($host, $suffix)) {
                return true;
            }
            continue;
        }

        if ($host === strtolower(trim($allowedHost, '[]'))) {
            return true;
        }
    }

    return false;
}

function ri_is_private_or_local_host(string $host, bool $resolveDns): bool
{
    $host = strtolower(trim($host, '[]'));
    if ($host === 'localhost' || str_ends_with($host, '.localhost') || $host === 'metadata.google.internal') {
        return true;
    }

    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return !ri_is_public_ip($host);
    }

    if ($resolveDns) {
        $addresses = ri_resolve_host_addresses($host);
        if ($addresses === []) {
            return true;
        }

        foreach ($addresses as $address) {
            if (!ri_is_public_ip($address)) {
                return true;
            }
        }
    }

    return false;
}

function ri_resolved_public_host_addresses(string $host): array
{
    $host = strtolower(trim($host, '[]'));
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return ri_is_public_ip($host) ? [$host] : [];
    }

    $addresses = ri_resolve_host_addresses($host);
    if ($addresses === []) {
        return [];
    }

    $publicAddresses = [];
    foreach ($addresses as $address) {
        if (!ri_is_public_ip($address)) {
            return [];
        }

        $publicAddresses[] = $address;
    }

    return array_values(array_unique($publicAddresses));
}

function ri_is_public_ip(string $address): bool
{
    return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

function ri_resolve_host_addresses(string $host): array
{
    $addresses = [];
    $records = @dns_get_record($host, DNS_A + DNS_AAAA);
    if (is_array($records)) {
        foreach ($records as $record) {
            foreach (['ip', 'ipv6'] as $key) {
                if (isset($record[$key]) && is_string($record[$key])) {
                    $addresses[] = $record[$key];
                }
            }
        }
    }

    $legacyAddresses = @gethostbynamel($host);
    if (is_array($legacyAddresses)) {
        $addresses = array_merge($addresses, $legacyAddresses);
    }

    return array_values(array_unique($addresses));
}
