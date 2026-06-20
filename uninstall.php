<?php

/**
 * Uninstall handler for Future Step LMS plugin
 *
 * This file is executed when the plugin is deleted from WordPress plugins page.
 * Handles complete cleanup of plugin data, options, and database tables.
 *
 * @package    fs-lms
 * @since      0.0.1
 * @see        WP_UNINSTALL_PLUGIN
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || die( 'Direct access to this file is not allowed.' );

require_once __DIR__ . '/vendor/autoload.php';

use Inc\Enums\Access\Capability;
use Inc\Migrations\Migration_1_0_0;

// 1. Удалить все 7 кастомных таблиц
$migration = new Migration_1_0_0();
$migration->down();

// 2. Удалить все опции плагина из wp_options одним запросом
global $wpdb;
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM $wpdb->options WHERE option_name LIKE %s",
		'fs_lms_%'
	)
);

// 3. Снять LMS-capabilities с роли administrator
$admin = get_role( 'administrator' );

if ( $admin instanceof WP_Role ) {
	$lms_caps = array(
		Capability::ManageApplications->value,
		Capability::EnrollStudent->value,
		Capability::ViewPII->value,
		Capability::ExportPII->value,
		Capability::ManagePersons->value,
		Capability::ViewLMSStats->value,
		Capability::ManageLMSAssignments->value,
	);

	foreach ( $lms_caps as $cap ) {
		$admin->remove_cap( $cap );
	}
}