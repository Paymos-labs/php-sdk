<?php

declare(strict_types=1);

use Paymos\Plugin\WordPressOrderLock;

final class SdkOrderLockDatabase
{
    public $prefix = 'wp_';
    public $connection = '11';
    public $locks = array();
    public $acquisitions = 0;
    public $failure = false;
    public $reconnectDuringAcquire = false;

    public function prepare($query, ...$args)
    {
        return array($query, $args);
    }

    public function get_var($prepared)
    {
        if ($this->failure) {
            return null;
        }
        list($query, $args) = $prepared;
        if (strpos($query, 'CASE GET_LOCK') !== false) {
            $this->acquisitions++;
            if ($this->reconnectDuringAcquire) {
                $this->connection = '22';
            }
            $name = $args[0];
            if (isset($this->locks[$name])) {
                return '0';
            }
            $this->locks[$name] = $this->connection;
            return $this->connection;
        }
        $owned = $this->connection === $args[0] && isset($this->locks[$args[1]]) && $this->locks[$args[1]] === $args[2];
        if (strpos($query, 'RELEASE_LOCK') !== false && $owned) {
            unset($this->locks[$args[1]]);
        }
        return $owned ? '1' : '0';
    }
}

function test_wordpress_order_lock_owns_connection_and_does_not_reenter_or_unlock_a_successor()
{
    global $wpdb;
    $wpdb = new SdkOrderLockDatabase();
    $lock = new WordPressOrderLock('woo', 42);
    assertTrueValue($lock->acquire(), 'first worker must acquire.');
    assertTrueValue($lock->acquire(), 'same instance acquire is idempotent.');
    assertSameValue(1, $wpdb->acquisitions, 'idempotent acquire must not increment MySQL reentrant count.');
    $lock->assertOwned();
    assertFalseValue((new WordPressOrderLock('woo', 42))->acquire(), 'another instance must not reenter the same order.');
    foreach (array_keys($wpdb->locks) as $name) {
        assertTrueValue(strlen($name) <= 64, 'MySQL lock names must stay within 64 bytes.');
    }
    $wpdb->connection = '22';
    foreach ($wpdb->locks as $name => $owner) {
        $wpdb->locks[$name] = '22'; // old connection died; a new owner acquired.
    }
    $lost = false;
    try {
        $lock->assertOwned();
    } catch (RuntimeException $error) {
        $lost = true;
    }
    assertTrueValue($lost, 'connection change must stop payment mutation.');
    $lock->release();
    assertSameValue(1, count($wpdb->locks), 'stale release must not unlock the successor.');
    assertFalseValue((new WordPressOrderLock('woo', 42))->acquire(), 'database owner must also prevent acquisition.');
    $wpdb->locks = array();
    $next = new WordPressOrderLock('woo', 42);
    assertTrueValue($next->acquire(), 'a later worker can acquire after expiry by connection death.');
    $next->release();
    $next->release();
    assertSameValue(array(), $wpdb->locks, 'release is idempotent.');
    unset($GLOBALS['wpdb']);
}

function test_wordpress_order_lock_isolates_scopes_and_fails_closed_on_database_failure()
{
    global $wpdb;
    $wpdb = new SdkOrderLockDatabase();
    $woo = new WordPressOrderLock('woo', 42);
    $edd = new WordPressOrderLock('edd', 42);
    assertTrueValue($woo->acquire(), 'Woo must acquire its scope.');
    assertTrueValue($edd->acquire(), 'EDD order with same ID is independent.');
    $woo->release();
    $edd->release();
    $wpdb->failure = true;
    $failed = false;
    try {
        (new WordPressOrderLock('woo', 43))->acquire();
    } catch (RuntimeException $error) {
        $failed = true;
    }
    assertTrueValue($failed, 'unsupported backend/SQL failure cannot authorize mutation.');
    $wpdb->failure = false;
    $wpdb->reconnectDuringAcquire = true;
    $reconnected = new WordPressOrderLock('woo', 44);
    assertTrueValue($reconnected->acquire(), 'acquisition records the actual connection atomically.');
    $reconnected->assertOwned();
    $reconnected->release();
    unset($GLOBALS['wpdb']);
}
