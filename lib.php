<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * The library file for the memcached+ cache store.
 * This file is part of the memcached+ cache store, it contains the API for interacting with an instance of the store.
 *
 * @package    cachestore_memcachedplus
 * @copyright  2015 Skylar Kelty <S.Kelty@kent.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/cache/stores/memcached/lib.php');

/**
 * The memcached+ store.
 *
 * Configuration options:
 *      servers:        string: host:port:weight , ...
 *      compression:    true, false
 *      serialiser:     SERIALIZER_PHP, SERIALIZER_JSON, SERIALIZER_IGBINARY
 *      prefix:         string: defaults to instance name
 *      hashmethod:     HASH_DEFAULT, HASH_MD5, HASH_CRC, HASH_FNV1_64, HASH_FNV1A_64, HASH_FNV1_32,
 *                      HASH_FNV1A_32, HASH_HSIEH, HASH_MURMUR
 *      bufferwrites:   true, false
 *
 * @copyright  2015 Skylar Kelty <S.Kelty@kent.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cachestore_memcachedplus extends cachestore_memcached implements cache_is_configurable, cache_is_key_aware, cache_is_searchable, cache_is_lockable
{
    /**
     * Initialises the cache.
     *
     * Once this has been done the cache is all set to be used.
     *
     * @param cache_definition $definition
     */
    public function initialise(cache_definition $definition) {
        parent::initialise($definition);

        $prefix = $this->options[Memcached::OPT_PREFIX_KEY] . crc32($definition->get_id());
        if (strlen($prefix) > 128) {
            $prefix = crc32($prefix);
        }

        $this->options[Memcached::OPT_PREFIX_KEY] = $prefix;

        $this->connection = new Memcached(crc32($prefix));
        $servers = $this->connection->getServerList();
        if (empty($servers)) {
            foreach ($this->options as $key => $value) {
                $this->connection->setOption($key, $value);
            }
            $this->connection->addServers($this->servers);
        }

        if ($this->clustered) {
            foreach ($this->setservers as $setserver) {
                // Since we will have a number of them with the same name, append server and port.
                $connection = new Memcached(crc32($prefix . $setserver[0] . $setserver[1]));
                foreach ($this->options as $key => $value) {
                    $connection->setOption($key, $value);
                }
                $connection->addServer($setserver[0], $setserver[1]);
                $this->setconnections[] = $connection;
            }
        }
    }

    /**
     * Returns true if this store instance is ready to be used.
     *
     * @return bool
     */
    public function is_ready() {
        static $isready = array();

        $servers = $this->connection->getServerList();
        $key = crc32(json_encode($servers));

        if (!isset($isready[$key]) || !$isready[$key]) {
            $isready[$key] = @$this->connection->set("ping", 'ping', 1);
        }

        return $isready[$key];
    }

    /**
     * Returns the supported modes as a combined int.
     *
     * @param array $configuration
     * @return int
     */
    public static function get_supported_modes(array $configuration = array()) {
        return self::MODE_APPLICATION + self::MODE_SESSION;
    }

    /**
     * Returns the supported features as a combined int.
     *
     * @param array $configuration
     * @return int
     */
    public static function get_supported_features(array $configuration = array()) {
        return self::SUPPORTS_NATIVE_TTL + self::IS_SEARCHABLE;
    }

    /**
     * Put the connections into non-blocking mode.
     * We should never do this, but when purging large sets of data
     * it probably doesn't matter.
     * Subsequent gets and sets are queued behind the deletes anyway,
     * which means they will block there.
     * This can be useful when we just fire-and-forget delete.
     */
    private function set_blocking($block) {
        if ($this->clustered) {
            foreach ($this->setconnections as $connection) {
                $connection->setOption(\Memcached::OPT_NO_BLOCK, $block);
            }
        } else {
            $this->connection->setOption(\Memcached::OPT_NO_BLOCK, $block);
        }
    }

    /**
     * Deletes several keys from the cache in a single action.
     *
     * @param array $keys The keys to delete
     * @return int The number of items successfully deleted.
     */
    public function delete_many(array $keys) {
        // Only delete keys we actually have.
        $valid = $this->find_all();
        $keys = array_intersect($keys, $valid);

        if ($this->clustered) {
            foreach ($this->setconnections as $connection) {
                $connection->deleteMulti($keys);
            }
        } else {
            $this->connection->deleteMulti($keys);
        }

        return count($keys);
    }

    /**
     * Purges the cache deleting all items within it.
     *
     * @return boolean True on success. False otherwise.
     */
    public function purge() {
        $keys = $this->find_all();

        $this->set_blocking(false);
        if ($this->clustered) {
            foreach ($this->setconnections as $connection) {
                $connection->deleteMulti($keys);
            }
        } else {
            $this->connection->deleteMulti($keys);
        }
        $this->set_blocking(true);

        return true;
    }

    /**
     * Acquires a lock on the given key for the given identifier.
     *
     * @param string $key The key we are locking.
     * @param string $ownerid The identifier so we can check if we have the lock or if it is someone else.
     *      The use of this property is entirely optional and implementations can act as they like upon it.
     * @return bool True if the lock could be acquired, false otherwise.
     */
    public function acquire_lock($key, $ownerid) {
        return $this->connection->add("lock_{$key}", $ownerid);
    }

    /**
     * Test if there is already a lock for the given key and if there is whether it belongs to the calling code.
     *
     * @param string $key The key we are locking.
     * @param string $ownerid The identifier so we can check if we have the lock or if it is someone else.
     * @return bool True if this code has the lock, false if there is a lock but this code doesn't have it, null if there
     *      is no lock.
     */
    public function check_lock_state($key, $ownerid) {
        $lock = $this->get("lock_{$key}");
        return $lock !== false && $lock == $ownerid;
    }

    /**
     * Releases the lock on the given key.
     *
     * @param string $key The key we are locking.
     * @param string $ownerid The identifier so we can check if we have the lock or if it is someone else.
     *      The use of this property is entirely optional and implementations can act as they like upon it.
     * @return bool True if the lock has been released, false if there was a problem releasing the lock.
     */
    public function release_lock($key, $ownerid) {
        return $this->delete("lock_{$key}");
    }

    /**
     * Test is a cache has a key.
     *
     * The use of the has methods is strongly discouraged. In a high load environment the cache may well change between the
     * test and any subsequent action (get, set, delete etc).
     * Instead it is recommended to write your code in such a way they it performs the following steps:
     * <ol>
     * <li>Attempt to retrieve the information.</li>
     * <li>Generate the information.</li>
     * <li>Attempt to set the information</li>
     * </ol>
     *
     * Its also worth mentioning that not all stores support key tests.
     * For stores that don't support key tests this functionality is mimicked by using the equivalent get method.
     * Just one more reason you should not use these methods unless you have a very good reason to do so.
     *
     * @param string|int $key
     * @return bool True if the cache has the requested key, false otherwise.
     */
    public function has($key) {
        return $this->has_any(array($key));
    }

    /**
     * Test if a cache has at least one of the given keys.
     *
     * It is strongly recommended to avoid the use of this function if not absolutely required.
     * In a high load environment the cache may well change between the test and any subsequent action (get, set, delete etc).
     *
     * Its also worth mentioning that not all stores support key tests.
     * For stores that don't support key tests this functionality is mimicked by using the equivalent get method.
     * Just one more reason you should not use these methods unless you have a very good reason to do so.
     *
     * @param array $keys
     * @return bool True if the cache has at least one of the given keys
     */
    public function has_any(array $keys) {
        $haystack = $this->find_all();
        foreach ($keys as $key) {
            if (in_array($key, $haystack)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Test is a cache has all of the given keys.
     *
     * It is strongly recommended to avoid the use of this function if not absolutely required.
     * In a high load environment the cache may well change between the test and any subsequent action (get, set, delete etc).
     *
     * Its also worth mentioning that not all stores support key tests.
     * For stores that don't support key tests this functionality is mimicked by using the equivalent get method.
     * Just one more reason you should not use these methods unless you have a very good reason to do so.
     *
     * @param array $keys
     * @return bool True if the cache has all of the given keys, false otherwise.
     */
    public function has_all(array $keys) {
        $haystack = $this->find_all();
        foreach ($keys as $key) {
            if (!in_array($key, $haystack)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Finds all of the keys being used by the cache store.
     *
     * @return array.
     */
    public function find_all() {
        return $this->find_by_prefix('');
    }

    /**
     * Finds all of the keys whose keys start with the given prefix.
     *
     * @param string $prefix
     */
    public function find_by_prefix($prefix) {
        $prefix = $this->options[Memcached::OPT_PREFIX_KEY] . $prefix;
        $result = array();

        $keys = $this->connection->getAllKeys();
        foreach ($keys as $key) {
            $pos = strpos($key, $prefix);
            if ($pos === 0) {
                $result[] = substr($key, strlen($prefix));
            }
        }

        return $result;
    }

    /**
     * Creates a test instance for unit tests if possible.
     * @param cache_definition $definition
     * @return bool|cachestore_memcached
     */
    public static function initialise_unit_test_instance(cache_definition $definition) {
        if (!self::are_requirements_met()) {
            return false;
        }

        $testservers = get_config('cachestore_memcachedplus', 'testservers');
        $testservers = defined('TEST_CACHESTORE_MEMCACHED_TESTSERVERS') ? TEST_CACHESTORE_MEMCACHED_TESTSERVERS : $testservers;
        if (empty($testservers)) {
            return false;
        }


        $configuration = array();
        $configuration['servers'] = explode("\n", $testservers);

        $store = new static('Test memcached', $configuration);
        $store->initialise($definition);

        return $store;
    }

    /**
     * Returns Memcached stats.
     */
    public function get_stats() {
        return $this->connection->getStats();
    }
}