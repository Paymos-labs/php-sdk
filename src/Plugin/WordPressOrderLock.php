<?php

declare(strict_types=1);

namespace Paymos\Plugin;

/** Connection-owned MySQL/MariaDB mutex shared by all paths mutating an order. */
final class WordPressOrderLock
{
    /** @var string */
    private $name;
    /** @var string|null */
    private $owner;
    /** @var array<string, bool> Prevent accidental reentrant locks across instances. */
    private static $held = array();

    public function __construct($scope, $orderId)
    {
        global $wpdb;
        if (!is_string($scope) || $scope === '' || (!is_int($orderId) && !is_string($orderId)) || (string) $orderId === '') {
            throw new \InvalidArgumentException('Paymos order lock requires a scope and order ID.');
        }
        if (!is_object($wpdb) || !isset($wpdb->prefix)) {
            throw new \RuntimeException('Paymos order lock requires the WordPress database.');
        }
        $identity = array(defined('DB_NAME') ? DB_NAME : '', (string) $wpdb->prefix,
            function_exists('get_current_blog_id') ? (string) get_current_blog_id() : '', $scope, (string) $orderId);
        // A fixed-length hash also keeps untrusted scope/ID bytes out of SQL names.
        $this->name = 'paymos_order_' . substr(hash('sha256', serialize($identity)), 0, 48);
        $this->owner = null;
    }

    public function acquire(): bool
    {
        if ($this->owner !== null) {
            $this->assertOwned();
            return true;
        }
        if (isset(self::$held[$this->name])) {
            return false;
        }
        $db = $this->database();
        // Capture the owner in the acquiring SQL statement: wpdb may reconnect.
        $owner = $db->get_var($db->prepare(
            'SELECT CASE GET_LOCK(%s, 0) WHEN 1 THEN CONNECTION_ID() WHEN 0 THEN 0 ELSE NULL END', $this->name
        ));
        if ($owner === '0' || $owner === 0) {
            return false;
        }
        if (!$this->validConnection($owner)) {
            throw new \RuntimeException('Paymos order lock could not be acquired; named locks are required.');
        }
        $this->owner = (string) $owner;
        try {
            $this->assertOwned();
        } catch (\Throwable $error) {
            $this->release();
            throw $error;
        }
        self::$held[$this->name] = true;
        return true;
    }

    public function assertOwned(): void
    {
        if ($this->owner === null) {
            throw new \RuntimeException('Paymos order lock is not owned.');
        }
        $db = $this->database();
        $result = $db->get_var($db->prepare(
            'SELECT IF(CONNECTION_ID() = %s AND IS_USED_LOCK(%s) = %s, 1, 0)',
            $this->owner, $this->name, $this->owner
        ));
        if ($result !== '1' && $result !== 1) {
            throw new \RuntimeException('Paymos order lock ownership was lost; payment mutation stopped.');
        }
    }

    public function release(): void
    {
        if ($this->owner === null) {
            return;
        }
        try {
            $db = $this->database();
            // The conditional executes on the same connection as RELEASE_LOCK.
            // A reconnect must never release a lock acquired by a later worker.
            $db->get_var($db->prepare(
                'SELECT IF(CONNECTION_ID() = %s AND IS_USED_LOCK(%s) = %s, RELEASE_LOCK(%s), 0)',
                $this->owner, $this->name, $this->owner, $this->name
            ));
        } finally {
            unset(self::$held[$this->name]);
            $this->owner = null;
        }
    }

    private function validConnection($value): bool
    {
        return (is_int($value) || is_string($value)) && ctype_digit((string) $value) && (string) $value !== '0';
    }

    /** @return object */
    private function database()
    {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare')) {
            throw new \RuntimeException('Paymos order lock requires the WordPress database.');
        }
        return $wpdb;
    }
}
