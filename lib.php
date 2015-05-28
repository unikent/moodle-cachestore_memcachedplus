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
class cachestore_memcachedplus extends cachestore_memcached implements cache_is_configurable, cache_is_key_aware, cache_is_searchable, cache_is_lockable {
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
     * Return a prefix for this definition.
     */
    protected function get_key_prefix() {
        if (!isset($this->definition)) {
            return '';
        }

        return $this->definition->get_id();
    }

    /**
     * Retrieves an item from the cache store given its key.
     *
     * @param string $key The key to retrieve
     * @return mixed The data that was associated with the key, or false if the key did not exist.
     */
    public function get($key) {
        $key = $this->get_key_prefix() . $key;

        return parent::get($key);
    }


    /**
     * Retrieves several items from the cache store in a single transaction.
     *
     * If not all of the items are available in the cache then the data value for those that are missing will be set to false.
     *
     * @param array $keys The array of keys to retrieve
     * @return array An array of items from the cache. There will be an item for each key, those that were not in the store will
     *      be set to false.
     */
    public function get_many($keys) {
        $prefix = $this->get_key_prefix();

        $vals = array();
        foreach ($keys as $key) {
            $vals[] = $prefix . $key;
        }

        return parent::get_many($keys);
    }


    /**
     * Sets an item in the cache given its key and data value.
     *
     * @param string $key The key to use.
     * @param mixed $data The data to set.
     * @return bool True if the operation was a success false otherwise.
     */
    public function set($key, $data) {
        $key = $this->get_key_prefix() . $key;

        return parent::set($key, $data);
    }


    /**
     * Sets many items in the cache in a single transaction.
     *
     * @param array $keyvaluearray An array of key value pairs. Each item in the array will be an associative array with two
     *      keys, 'key' and 'value'.
     * @return int The number of items successfully set. It is up to the developer to check this matches the number of items
     *      sent ... if they care that is.
     */
    public function set_many(array $keyvaluearray) {
        $prefix = $this->get_key_prefix();

        $vals = array();
        foreach ($keyvaluearray as $key => $value) {
            $vals[$prefix . $key] = $value;
        }

        return parent::set_many($vals);
    }


    /**
     * Deletes an item from the cache store.
     *
     * @param string $key The key to delete.
     * @return bool Returns true if the operation was a success, false otherwise.
     */
    public function delete($key) {
        $key = $this->get_key_prefix() . $key;

        return parent::delete($key);
    }


    /**
     * Deletes several keys from the cache in a single action.
     *
     * @param array $keys The keys to delete
     * @return int The number of items successfully deleted.
     */
    public function delete_many(array $keys) {
        $prefix = $this->get_key_prefix();

        $vals = array();
        foreach ($keys as $key) {
            $vals[] = $prefix . $key;
        }

        return parent::delete_many($keys);
    }


    /**
     * Purges the cache deleting all items within it.
     *
     * @return boolean True on success. False otherwise.
     */
    public function purge() {
        // TODO
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
        $prefix = $this->get_key_prefix();
        return $this->connection->add("{$prefix}lock_{$key}", $ownerid);
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
        $this->delete("lock_{$key}");
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
        $prefix = $this->get_key_prefix();

        $haystack = $this->connection->getAllKeys();
        foreach ($keys as $key) {
            if (in_array($prefix . $key, $haystack)) {
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
        $prefix = $this->get_key_prefix();

        $haystack = $this->connection->getAllKeys();
        foreach ($keys as $key) {
            if (!in_array($prefix . $key, $haystack)) {
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
        $prefix = $this->get_key_prefix() . $prefix;
        $result = array();

        $keys = $this->connection->getAllKeys();
        foreach ($keys as $key) {
            if (strpos($key, $prefix) === 0) {
                $result[] = $key;
            }
        }

        return $result;
    }
}