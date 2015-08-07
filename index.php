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

require_once(dirname(__FILE__) . '/../../../config.php');
require_once($CFG->dirroot . '/lib/adminlib.php');
require_once($CFG->libdir . '/tablelib.php');

admin_externalpage_setup('memcachedplusstatus');

$PAGE->set_title('Memcached+ Status');
$PAGE->set_heading('Memcached+ Status');

$definition = cache_definition::load_adhoc(cache_store::MODE_APPLICATION, 'cachestore_memcachedplus', 'statuscheck');
$instance = cachestore_memcachedplus::initialise_unit_test_instance($definition);
if (!$instance) {
    print_error('You must setup this store\'s test servers.');
}

echo $OUTPUT->header();
echo $OUTPUT->heading('Memcached+ Status');

$status = $instance->get_stats();

foreach ($status as $server => $data) {
    echo $OUTPUT->heading($server, 4);

    $table = new \flexible_table("memcachedplus_status_{$server}");
    $table->define_columns(array('variable', 'value'));
    $table->define_headers(array('Variable', 'Value'));
    $table->define_baseurl($PAGE->url);
    $table->setup();

    foreach ($data as $k => $v) {
        $table->add_data(array($k, $v));
    }

    $table->finish_output();
}

echo $OUTPUT->footer();