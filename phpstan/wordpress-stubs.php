<?php

/** Minimal signatures for the optional WordPress adapters. This file is parsed by PHPStan only. */

class WP_Error
{
}

/** @param mixed $value */
function is_wp_error($value): bool
{
}

/** @param array<string, mixed> $args @return array<string, mixed>|WP_Error */
function wp_safe_remote_request(string $url, array $args)
{
}

/** @param array<string, mixed> $response */
function wp_remote_retrieve_response_code(array $response): int
{
}

/** @param array<string, mixed> $response */
function wp_remote_retrieve_body(array $response): string
{
}

/** @param array<string, mixed> $response @return array<string, mixed>|object */
function wp_remote_retrieve_headers(array $response)
{
}

/** @return mixed */
function get_option(string $option, $default = false)
{
}

/** @param mixed $value */
function update_option(string $option, $value, bool $autoload = true): bool
{
}

function delete_option(string $option): bool
{
}

function wp_salt(string $scheme = 'auth'): string
{
}
