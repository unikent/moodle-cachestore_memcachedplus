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
class cachestore_memcachedplus extends cachestore_memcached
                               implements cache_is_configurable, cache_is_lockable
{
    private $_prefix;
    private $_simplekeys;
    private $_logpurges;
    private $_defid;
    private $_storename;

    /**
     * Constructs the store instance.
     *
     * Noting that this function is not an initialisation. It is used to prepare the store for use.
     * The store will be initialised when required and will be provided with a cache_definition at that time.
     *
     * @param string $name
     * @param array $configuration
     */
    public function __construct($name, array $configuration = array()) {
        $this->_storename = $name;
        $this->options[Memcached::OPT_LIBKETAMA_COMPATIBLE] = true;
        $this->options[Memcached::OPT_TCP_NODELAY] = true;

        $this->_logpurges = array_key_exists('logpurges', $configuration) ? (bool)$configuration['logpurges'] : false;

        parent::__construct($name, $configuration);
    }

    /**
     * Initialises the cache.
     *
     * Once this has been done the cache is all set to be used.
     *
     * @param cache_definition $definition
     */
    public function initialise(cache_definition $definition) {
        $this->_defid = $definition->get_id();
        $this->_prefix = $definition->generate_single_key_prefix();
        $this->_simplekeys = $definition->uses_simple_keys();

        return parent::initialise($definition);
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
     * Purges the cache deleting all items within it.
     *
     * @return boolean True on success. False otherwise.
     */
    public function purge() {
        if ($this->_simplekeys) {
            // Ooh! Perhaps..
            $keys = $this->connection->getAllKeys();
            if ($keys !== false) {
                // Woo!
                $purge = array();
                foreach ($keys as $key) {
                    $suffix = substr($key, strrpos($key, '-') + 1);
                    if ($suffix == $this->_prefix) {
                        $purge[] = $key;
                    }
                }

                $this->delete_many($purge);

                return true;
            }
        }

        if ($this->_logpurges) {
            debugging("Memcached purge called against {$this->_defid} for {$this->_storename}, definition does not have simple keys.");
        }

        return parent::purge();
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
     * Given the data from the add instance form this function creates a configuration array.
     *
     * @param stdClass $data
     * @return array
     */
    public static function config_get_configuration_array($data) {
        $config = parent::config_get_configuration_array($data);
        if (isset($data->logpurges)) {
            $config['logpurges'] = $data->logpurges;
        }

        return $config;
    }

    /**
     * Allows the cache store to set its data against the edit form before it is shown to the user.
     *
     * @param moodleform $editform
     * @param array $config
     */
    public static function config_set_edit_form_data(moodleform $editform, array $config) {
        parent::config_set_edit_form_data($editform, $config);

        if (isset($config['logpurges'])) {
            $data = (array)$editform->get_data();
            $data['logpurges'] = (bool)$config['logpurges'];
            $editform->set_data($data);
        }
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
