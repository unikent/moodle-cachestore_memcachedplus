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

defined('MOODLE_INTERNAL') || die;

if ($hassiteconfig) {
    $ADMIN->add('reports', new admin_externalpage(
        'memcachedplusstatus',
        'Memcached+ Status',
        new \moodle_url('/cache/stores/memcachedplus/index.php'),
        'moodle/site:config'
    ));
}

$settings->add(new admin_setting_configtextarea(
        'cachestore_memcachedplus/testservers',
        new lang_string('testservers', 'cachestore_memcached'),
        new lang_string('testservers_desc', 'cachestore_memcached'),
        '', PARAM_RAW, 60, 3));
