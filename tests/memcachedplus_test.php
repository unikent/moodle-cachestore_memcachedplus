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
 * Memcached+ unit tests.
 *
 * If you wish to use these unit tests all you need to do is add the following definition to
 * your config.php file.
 *
 * define('TEST_CACHESTORE_MEMCACHED_TESTSERVERS', '127.0.0.1:11211');
 *
 * @package    cachestore_memcachedplus
 * @copyright  2015 Skylar Kelty <S.Kelty@kent.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Include the necessary evils.
global $CFG;
require_once($CFG->dirroot.'/cache/tests/fixtures/stores.php');
require_once($CFG->dirroot.'/cache/stores/memcachedplus/lib.php');

/**
 * Memcached+ unit test class.
 *
 * @package    cachestore_memcachedplus
 * @copyright  2015 Skylar Kelty <S.Kelty@kent.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cachestore_memcachedplus_test extends cachestore_tests {
    /**
     * Returns the memcached+ class name
     * @return string
     */
    protected function get_class_name() {
        return 'cachestore_memcachedplus';
    }

    /**
     * Ensures purge only affects the one cache store.
     */
    public function test_purge() {
        $definition = cache_definition::load_adhoc(cache_store::MODE_APPLICATION, 'cachestore_memcachedplus', 'phpunit_test');
        $instance = cachestore_memcachedplus::initialise_unit_test_instance($definition);

        $definition2 = cache_definition::load_adhoc(cache_store::MODE_APPLICATION, 'cachestore_memcachedplus', 'phpunit_test_two');
        $instance2 = cachestore_memcachedplus::initialise_unit_test_instance($definition2);

        if (!$instance || !$instance2) {
            $this->markTestSkipped();
        }

        $keys = array(
            // Alphanumeric.
            'abc', 'ABC', '123', 'aB1', '1aB',
            // Hyphens.
            'a-1', '1-a', '-a1', 'a1-',
            // Underscores.
            'a_1', '1_a', '_a1', 'a1_'
        );

        // Set some keys.
        foreach ($keys as $key) {
            $this->assertTrue($instance->set($key, $key), "Failed to set key `$key`");
            $this->assertTrue($instance2->set($key, $key), "Failed to set key `$key`");
        }

        // Get some keys.
        foreach ($keys as $key) {
            $this->assertEquals($key, $instance->get($key), "Failed to get key `$key`");
            $this->assertEquals($key, $instance2->get($key), "Failed to get key `$key`");
        }

        // Purge instance one.
        $instance->purge();

        // Get some keys.
        foreach ($keys as $key) {
            $this->assertFalse($instance->get($key), "Key still existed `$key`");
            $this->assertEquals($key, $instance2->get($key), "Failed to get key `$key` (purged)");
        }

        // Cleanup.
        $instance->purge();
        $instance2->purge();
    }

    /**
     * Test searching.
     */
    public function test_search() {
        $definition = cache_definition::load_adhoc(cache_store::MODE_APPLICATION, 'cachestore_memcachedplus', 'phpunit_test');
        $instance = cachestore_memcachedplus::initialise_unit_test_instance($definition);

        if (!$instance) {
            $this->markTestSkipped();
        }

        $instance->purge();
        $this->assertEmpty($instance->find_all(), "Found items on a new connection");
        $this->assertFalse($instance->has("search_1"), "Found search1 after purge");

        // Add a few items.
        $this->assertTrue($instance->set("search_1", "value!"), "Failed to set key `search_1`");
        $this->assertTrue($instance->set("search_2", "value! :D"), "Failed to set key `search_1`");

        // Search for search1.
        $this->assertTrue($instance->has("search_1", "Couldn't find search1"));
        $this->assertFalse($instance->has("search_3", "Found search_3 but didn't add it"));

        // Test find_by_prefix.
        $this->assertEquals(2, count($instance->find_by_prefix("search_")));
        $this->assertEquals(0, count($instance->find_by_prefix("_search")));

        // Cleanup.
        $instance->purge();
    }

    /**
     * Test locking
     */
    public function test_locking() {
        $definition = cache_definition::load_adhoc(cache_store::MODE_APPLICATION, 'cachestore_memcachedplus', 'phpunit_test');
        $instance = cachestore_memcachedplus::initialise_unit_test_instance($definition);

        if (!$instance) {
            $this->markTestSkipped();
        }

        // Basic lock checks.
        $this->assertFalse($instance->check_lock_state('testlock', 'phpunit'));
        $this->assertTrue($instance->acquire_lock('testlock', 'phpunit'));
        $this->assertTrue($instance->check_lock_state('testlock', 'phpunit'));
        $this->assertFalse($instance->check_lock_state('testlock', 'phpunit2'));
        $this->assertFalse($instance->check_lock_state('testlock2', 'phpunit'));
        $this->assertFalse($instance->check_lock_state('testlock2', 'phpunit2'));
        $this->assertTrue($instance->release_lock('testlock', 'phpunit'));
        $this->assertFalse($instance->check_lock_state('testlock', 'phpunit'));

        // Re-lock check.
        $this->assertTrue($instance->acquire_lock('testlock', 'phpunit'));
        $this->assertTrue($instance->check_lock_state('testlock', 'phpunit'));
        $this->assertTrue($instance->release_lock('testlock', 'phpunit'));

        // Multi-lock checks.
        $this->assertTrue($instance->acquire_lock('testlock1', 'phpunit'));
        $this->assertTrue($instance->acquire_lock('testlock2', 'phpunit'));
        $this->assertTrue($instance->acquire_lock('testlock3', 'differentphpunit'));
        $this->assertTrue($instance->check_lock_state('testlock1', 'phpunit'));
        $this->assertTrue($instance->check_lock_state('testlock2', 'phpunit'));
        $this->assertTrue($instance->check_lock_state('testlock3', 'differentphpunit'));
        $this->assertFalse($instance->check_lock_state('testlock3', 'phpunit'));
        $this->assertTrue($instance->release_lock('testlock1', 'phpunit'));
        $this->assertTrue($instance->release_lock('testlock2', 'phpunit'));
        $this->assertTrue($instance->release_lock('testlock3', 'differentphpunit'));
        $this->assertFalse($instance->check_lock_state('testlock1', 'phpunit'));
        $this->assertFalse($instance->check_lock_state('testlock2', 'phpunit'));
        $this->assertFalse($instance->check_lock_state('testlock3', 'differentphpunit'));

        // Cleanup.
        $instance->purge();
    }
}
