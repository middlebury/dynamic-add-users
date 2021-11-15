<?php
/*
Plugin Name: Dynamic Add Users
Plugin URI:
Description: Replaces the 'Add User' screen with a dynamic search for users and groups
Author: Adam Franco
Author URI: http://www.adamfranco.com/
*/

require_once( dirname(__FILE__) . '/src/DynamicAddUsers/Directory/CASDirectory/Directory.php' );
require_once( dirname(__FILE__) . '/src/DynamicAddUsers/Directory/DirectoryBase.php' );
require_once( dirname(__FILE__) . '/src/DynamicAddUsers/UserManager.php' );
require_once( dirname(__FILE__) . '/src/DynamicAddUsers/GroupSyncer.php' );
require_once( dirname(__FILE__) . '/src/DynamicAddUsers/LoginMapper/WpSamlAuthLoginMapper.php' );
require_once( dirname(__FILE__) . '/src/DynamicAddUsers/Admin/AddUsers.php' );

use \DynamicAddUsers\Directory\CASDirectory\Directory AS CasDirectoryDirectory;
use \DynamicAddUsers\Directory\DirectoryBase;
use \DynamicAddUsers\UserManager;
use \DynamicAddUsers\GroupSyncer;
use \DynamicAddUsers\LoginMapper\WpSamlAuthLoginMapper;
use \DynamicAddUsers\Admin\AddUsers;

/**
 * Answer the currently configured directory implementation.
 *
 * @return \DynamicAddUsers\Directory\DirectoryInterface
 */
function dynaddusers_get_directory() {
	static $directory;
	if (!isset($directory)) {
		if (!defined('DYNADDUSERS_CAS_DIRECTORY_URL')) {
			throw new Exception('DYNADDUSERS_CAS_DIRECTORY_URL must be defined.');
		}
		if (!defined('DYNADDUSERS_CAS_DIRECTORY_ADMIN_ACCESS')) {
			throw new Exception('DYNADDUSERS_CAS_DIRECTORY_ADMIN_ACCESS must be defined.');
		}
		$directory = new CasDirectoryDirectory(DYNADDUSERS_CAS_DIRECTORY_URL, DYNADDUSERS_CAS_DIRECTORY_ADMIN_ACCESS);
	}
	return $directory;
}

/**
 * Answer the currently configured directory implementation.
 *
 * @return \DynamicAddUsers\Directory\DirectoryInterface
 */
function dynaddusers_get_user_manager() {
	static $userManager;
	if (!isset($userManager)) {
		$userManager = new UserManager(dynaddusers_get_directory());
	}
	return $userManager;
}

/**
 * Answer the currently configured directory implementation.
 *
 * @return \DynamicAddUsers\Directory\DirectoryInterface
 */
function dynaddusers_get_group_syncer() {
	static $groupSyncer;
	if (!isset($groupSyncer)) {
		$groupSyncer = new GroupSyncer(dynaddusers_get_directory(), dynaddusers_get_user_manager());
	}
	return $groupSyncer;
}

/**
 * Answer the currently configured login-mapper implementation.
 *
 * @return \DynamicAddUsers\LoginMapper\LoginMapperInterface
 */
function dynaddusers_get_login_mapper() {
	static $loginMapper;
	if (!isset($loginMapper)) {
		$loginMapper = new WpSamlAuthLoginMapper();
	}
	return $loginMapper;
}

/**
 * Set up login actions.
 */
dynaddusers_get_login_mapper()->setup();


global $dynaddusers_db_version;
$dynaddusers_db_version = '0.1';

// Database table check
function dynaddusers_update_db_check() {
    global $dynaddusers_db_version;
    if (get_site_option( 'dynaddusers_db_version' ) != $dynaddusers_db_version) {
        dynaddusers_install();
    }
}
add_action( 'plugins_loaded', 'dynaddusers_update_db_check' );

/**
 * Install hook.
 */
function dynaddusers_install () {
	global $wpdb;
	global $dynaddusers_db_version;

	$groups = $wpdb->base_prefix . "dynaddusers_groups";
	$synced = $wpdb->base_prefix . "dynaddusers_synced";
	if ($wpdb->get_var("SHOW TABLES LIKE '$groups'") != $groups) {
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

		$sql = "CREATE TABLE " . $groups . " (
			blog_id int(11) NOT NULL,
			group_id varchar(255) NOT NULL,
			role varchar(25) NOT NULL,
			last_sync datetime default NULL,
			PRIMARY KEY  (blog_id,group_id),
			KEY last_sync (last_sync)
		);";
		dbDelta($sql);

		$sql = "CREATE TABLE " . $synced . " (
			blog_id int(11) NOT NULL,
			group_id varchar(255) NOT NULL,
			user_id int(11) NOT NULL,
			PRIMARY KEY  (blog_id,group_id,user_id)
		);";
		dbDelta($sql);

		add_option("dynaddusers_db_version", $dynaddusers_db_version);
	}
}

/**
 * Action to take on user login.
 *
 * LoginMapperInterface implementations *should* call this function after
 * attempting to map a login response to an external user identifier.
 *
 * @param WP_User $user
 *   The user who has authenticated.
 * @param optional string $external_user_id
 *   If the login attributes map to an external user identifier that can be
 *   looked up in the directory service, that ID should be passed here.
 */
function dynaddusers_on_login(WP_User $user, $external_user_id = NULL) {
	// Default to no groups.
	$groups = [];

	if (!is_null($external_user_id)) {
		try {
			$groups = dynaddusers_get_directory()->getGroupsForUser($external_user_id);
			dynaddusers_get_group_syncer()->syncUser($user->ID, $groups);
		} catch (Exception $e) {
			if ($e->getCode() == 404 || $e->getCode() == 400) {
				// Skip if not found in the data source.
				trigger_error('DynamicAddUsers: Tried to update user groups for  ' . $user->id . ' / '. $external_user_id . ' but they were not found the directory service.', E_USER_NOTICE);
			} else {
				throw $e;
			}
		}
	}

	// Let other modules take action based on user groups.
	// See dynaddusers_update_user_on_login(WP_User $user, array $groups) for
	// an example.
	do_action('dynaddusers_update_user_on_login', $user, $groups);
}

/**
 * Set/unset roles and capabilities for the user based on groups.
 *
 * @param WP_User $user
 * @param array $groups
 */
function dynaddusers_update_user_on_login(WP_User $user, array $groups) {
	/*
	// Example: Let institution users do something.
	if (in_array('CN=institution,OU=General,OU=Groups,DC=middlebury,DC=edu', $groups)) {
		$user->add_cap('middlebury_custom_capability');
	}
	// For all other users disallow this capability.
	else {
		$user->remove_cap('middlebury_custom_capability');
	}
	*/
}

AddUsers::init(dynaddusers_get_directory(), dynaddusers_get_user_manager(), dynaddusers_get_group_syncer());

/**
 * Filter out old guest accounts that shouldn't be able to log in any more.
 *
 * values will look like: guest_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
 *
 * @param array $userMatches
 * @return array The filtered matches.
 */
function dynaddusers_filter_old_guest_accounts($matches) {
  $results = [];
  foreach ($matches as $match) {
    if (!preg_match('/^guest_[a-z0-9]{31,34}$/i', $match['user_login'])) {
      $results[] = $match;
    }
  }
  return $results;
}
add_filter('dynaddusers__filter_user_matches', 'dynaddusers_filter_old_guest_accounts');


// For now we will try to avoid syncing all groups via cron as this may take a
// really long time. Instead we will sync all of the groups for a blog when viewing
// the user page, and add/remove individuals from all of their groups on login.
// Hopefully these incremental updates will be sufficient and avoid unneeded big
// synchronizations.
//
// // Schedule cron jobs for group syncing
// add_action('dynaddusers_group_sync_event', 'dynaddusers_sync_all_groups');
// function dynaddusers_activation() {
// 	if ( !wp_next_scheduled( 'dynaddusers_group_sync_event' ) ) {
// 		wp_schedule_event(time(), 'daily', 'dynaddusers_group_sync_event');
// 	}
// }
// add_action('wp', 'dynaddusers_activation');
