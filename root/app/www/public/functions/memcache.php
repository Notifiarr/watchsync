<?php

/*
----------------------------------
------  Created: 091326   ------
------  Austin Best       ------
----------------------------------
*/

function memcacheClient()
{
    global $memcached;

    if ($memcached instanceof Memcached) {
        return $memcached;
    }
    if (!class_exists('Memcached')) {
        return null;
    }

    $memcached = new Memcached();
    $memcached->addServer(MEMCACHE_HOST, MEMCACHE_PORT);

    return $memcached;
}

function memcacheGet($key)
{
    $client = memcacheClient();
    if (!$client) {
        return null;
    }

    $value = $client->get(MEMCACHE_PREFIX . $key);
    if ($client->getResultCode() != Memcached::RES_SUCCESS) {
        return null;
    }

    return $value;
}

function memcacheSet($key, $value, $ttl = 0)
{
    $client = memcacheClient();
    if (!$client) {
        return false;
    }

    return $client->set(MEMCACHE_PREFIX . $key, $value, intval($ttl));
}

function memcacheDelete($key)
{
    $client = memcacheClient();
    if (!$client) {
        return false;
    }

    return $client->delete(MEMCACHE_PREFIX . $key);
}
