<?php
/**
 * Dynamic Add Users replaces the 'Add User' screen with a dynamic directory search.
 *
 * This plugin also allows searching for groups in a directory and bulk-adding
 * group members to a site as well as synchronizing site-roles based on group-membership.
 * It provides several services that other plugins can use to look up users and
 * groups and apply roles to users.
 *
 * @link              https://github.com/middlebury/dynamic-add-users
 * @since             1.0.0
 * @package           Dynamic_Add_Users
 *
 * @wordpress-plugin
 * Plugin Name:       Dynamic Add Users
 * Plugin URI:        https://github.com/middlebury/dynamic-add-users
 * Description:       Replaces the 'Add User' screen with a dynamic search for users and groups.
 * Version:           1.2.0
 * Author:            Middlebury College
 * Author URI:        https://github.com/middlebury/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       dynamic-add-users
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Currently plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
define( 'DYNAMIC_ADD_USERS_VERSION', '1.1.0' );

require_once( __DIR__ . '/vendor/autoload.php' );

use DynamicAddUsers\DynamicAddUsersPlugin;
use WP_User;

/**
 * Answer the plugin instance.
 *
 * @return \DynamicAddUsers\DynamicAddUsersPluginInterface
 */
function dynamic_add_users() {
  static $plugin;
  if (!isset($plugin)) {
    $plugin = new DynamicAddUsersPlugin();
  }
  return $plugin;
}

// Initialize the plugin instance.
dynamic_add_users();


/*******************************************************
 * Actions and Filters
 *******************************************************/

/**
 * Action: Set/unset roles and capabilities for the user based on groups.
 *
 * This plugin will call
 *   doAction('dynamic_add_users__update_user_on_login', $user, $groups)
 * when a user logs in. Below is an example implementation.
 *
 * @param WP_User $user
 * @param array $groups
 */
function dynamic_add_users__update_user_on_login(WP_User $user, array $groups) {
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

/**
 * Filter: Filter directory results when searching for users.
 *
 * Each match is an array like:
 *
 *  [
 *    'user_login' => '',
 *    'user_email' => '',
 *    'user_nicename' => '',
 *    'display_name' => '',
 *  ]
 *
 * This plugin will call
 *   apply_filters('dynamic_add_users__filter_user_matches', $matches)
 * when searches against the directory are run. Below is an example
 * implementation.
 *
 * @param array $matches
 * @return array The filtered matches.
 */
function dynamic_add_users__filter_user_matches($matches) {
  /*
  // Example: Filter out accounts prefixed with 'guest_'.
  $results = [];
  foreach ($matches as $match) {
    if (!preg_match('/^guest_[a-z0-9]{31,34}$/i', $match['user_login'])) {
      $results[] = $match;
    }
  }
  return $results;
  */
}

/**
 * Filter: Filter directory results when searching for groups.
 *
 * Each matches is an array of group ID => display name. Keys and values depend
 * on the directory implementation and should not have their format assumed.
 * Examples:
 *
 *  [
 *    '100' => 'All Students',
 *    '5' => 'Faculty',
 *  ]
 *
 * or
 *
 *  [
 *    'cn=All Students,OU=Groups,DC=middlebury,DC=edu' => 'All Students',
 *    'cn=Faculty,OU=Groups,DC=middlebury,DC=edu' => 'Faculty',
 *  ]
 *
 * This plugin will call
 *   apply_filters('dynamic_add_users__filter_group_matches', $matches)
 * when searches against the directory are run. Below is an example
 * implementation.
 *
 * @param array $matches
 * @return array The filtered matches.
 */
function dynamic_add_users__filter_group_matches($matches) {
  /*
  // Example: Filter out groups prefixed with 'xx' or 'zz'.
  $results = [];
  foreach ($matches as $id => $displayName) {
    if (!preg_match('/^(xx|zz).+$/i', $displayName)) {
      $results[$id] = $displayName;
    }
  }
  return $results;
  */
}


require_once( dirname(__FILE__) . '/src/middlebury-tweaks.php' );
