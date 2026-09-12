<?php

/*
----------------------------------
------  Created: 091026   ------
------  Austin Best       ------
----------------------------------
*/

function requestPeerIp()
{
    $addr = strval($_SERVER['REMOTE_ADDR'] ?? '');
    if ($addr == '') {
        return '';
    }
    if (str_starts_with($addr, '[') && str_ends_with($addr, ']')) {
        return substr($addr, 1, -1);
    }
    if (substr_count($addr, ':') == 1 && str_contains($addr, '.')) {
        return explode(':', $addr)[0];
    }

    return $addr;
}

function requestHeaderValue($name)
{
    $name = trim(strval($name));
    if ($name == '') {
        return '';
    }
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $key => $value) {
                if (strcasecmp(strval($key), $name) == 0) {
                    return trim(strval($value));
                }
            }
        }
    }
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

    return trim(strval($_SERVER[$serverKey] ?? ''));
}

function normalizeLoginCidr($value, $ipHint = '')
{
    $value = trim(strval($value));
    if ($value == '') {
        return '';
    }
    if (str_contains($value, '/')) {
        return $value;
    }
    $isV6 = str_contains($value, ':') || str_contains($ipHint, ':');

    return $value . ($isV6 ? '/128' : '/32');
}

function parseLoginUpstreams($raw)
{
    $parts = preg_split('/[\s,;]+/', strval($raw)) ?: [];
    $cidrs = [];
    foreach ($parts as $part) {
        $cidr = normalizeLoginCidr($part);
        if ($cidr == '') {
            continue;
        }
        $cidrs[$cidr] = true;
    }

    return array_keys($cidrs);
}

function loginBuiltinNetworks($allowLoopback, $allowPrivate)
{
    $cidrs = [];
    if ($allowLoopback) {
        $cidrs[] = '127.0.0.1/32';
        $cidrs[] = '::1/128';
    }
    if ($allowPrivate) {
        $cidrs[] = '10.0.0.0/8';
        $cidrs[] = '172.16.0.0/12';
        $cidrs[] = '192.168.0.0/16';
        $cidrs[] = 'fc00::/7';
    }

    return $cidrs;
}

function loginTrustedNetworks($database)
{
    $custom = parseLoginUpstreams($database->getSetting('loginUpstreams') ?: '');
    $built  = loginBuiltinNetworks(
        $database->settingEnabled('loginAllowLoopback'),
        $database->settingEnabled('loginAllowPrivate'),
    );

    return array_values(array_unique(array_merge($built, $custom)));
}

function ipInCidr($ip, $cidr)
{
    $ip   = trim(strval($ip));
    $cidr = normalizeLoginCidr($cidr, $ip);
    if ($ip == '' || $cidr == '' || !str_contains($cidr, '/')) {
        return false;
    }
    list($subnet, $bits) = explode('/', $cidr, 2);
    $bits                = intval($bits);
    $ipBin               = @inet_pton($ip);
    $subnetBin           = @inet_pton($subnet);
    if ($ipBin == false || $subnetBin == false || strlen($ipBin) != strlen($subnetBin)) {
        return false;
    }
    $maxBits = strlen($ipBin) * 8;
    if ($bits < 0 || $bits > $maxBits) {
        return false;
    }
    $bytes   = intdiv($bits, 8);
    $remBits = $bits % 8;
    if ($bytes && substr($ipBin, 0, $bytes) != substr($subnetBin, 0, $bytes)) {
        return false;
    }
    if (!$remBits) {
        return true;
    }
    $mask = (~((1 << (8 - $remBits)) - 1)) & 0xFF;

    return (ord($ipBin[$bytes]) & $mask) == (ord($subnetBin[$bytes]) & $mask);
}

function loginPeerAllowed($database, $ip = null)
{
    $ip = $ip == null ? requestPeerIp() : trim(strval($ip));
    if ($ip == '') {
        return false;
    }
    foreach (loginTrustedNetworks($database) as $cidr) {
        if (ipInCidr($ip, $cidr)) {
            return true;
        }
    }

    return false;
}

function normalizeLoginMode($mode)
{
    $mode = strtolower(trim(strval($mode)));
    if ($mode == LoginModes::OFF || $mode == LoginModes::BYPASS) {
        return $mode;
    }

    return LoginModes::REQUIRED;
}

function normalizeLoginAuthHeader($header)
{
    $header = trim(strval($header));
    if ($header == '') {
        return LOGIN_DEFAULT_AUTH_HEADER;
    }
    if (!preg_match('/^[A-Za-z0-9-]+$/', $header)) {
        return LOGIN_DEFAULT_AUTH_HEADER;
    }

    return $header;
}

function loginModeHasTrust($mode)
{
    return normalizeLoginMode($mode) == LoginModes::BYPASS;
}

function applyTrustedForwardedFor($database)
{
    $peer = requestPeerIp();
    if ($peer == '' || !loginPeerAllowed($database, $peer)) {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = $peer;

        return;
    }
    $xff = trim(strval($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($xff == '') {
        return;
    }
    if (preg_match('/(?:,|\s)([^\s,]+)\s*$/', $xff, $match)) {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = trim($match[1]);
    }
}

function applyLoginTrust($database)
{
    if (defined('IS_API') && IS_API) {
        return;
    }
    if (!empty($_SESSION['userdata'])) {
        return;
    }

    $mode = normalizeLoginMode($database->getSetting('loginMode') ?: LoginModes::REQUIRED);
    if ($mode == LoginModes::REQUIRED) {
        return;
    }

    if ($mode == LoginModes::BYPASS) {
        applyTrustedForwardedFor($database);
        if (!loginPeerAllowed($database)) {
            return;
        }
        $header = normalizeLoginAuthHeader($database->getSetting('loginAuthHeader') ?: LOGIN_DEFAULT_AUTH_HEADER);
        $name   = requestHeaderValue($header);
        if ($name == '') {
            return;
        }
        $user = $database->getUserByUsername($name);
        if (!$user) {
            $user = $database->getFirstUser();
        }
    } else {
        $user = $database->getFirstUser();
    }

    if (!$user) {
        return;
    }

    if (session_status() != PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['userdata'] = $user;
}
