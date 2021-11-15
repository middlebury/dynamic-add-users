<?php
/*
Plugin Name: Dynamic Add Users
Plugin URI:
Description: Replaces the 'Add User' screen with a dynamic search for users and groups
Author: Adam Franco
Author URI: http://www.adamfranco.com/
*/

require_once( dirname(__FILE__) . '/src/DynamicAddUsers/DynamicAddUsersPlugin.php' );

use \DynamicAddUsers\DynamicAddUsersPlugin;
use WP_User;

/**
 * Answer the plugin class.
 *
 * @return \DynamicAddUsers\DynamicAddUsersPluginInterface
 */
function dynaddusers_plugin() {
  static $plugin;
  if (!isset($plugin)) {
    $plugin = new DynamicAddUsersPlugin();
  }
  return $plugin;
}

// Initialize the plugin instance.
dynaddusers_plugin();

/**
 * Action: Set/unset roles and capabilities for the user based on groups.
 *
 * This plugin will call
 *   doAction('dynaddusers_update_user_on_login', $user, $groups)
 * when a user logs in. Below is an example implementation.
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
 * @param array $userMatches
 * @return array The filtered matches.
 */
function dynaddusers_filter_user_matches($matches) {
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
 * @param array $matches
 * @return array The filtered matches.
 */
function dynaddusers_filter_group_matches($matches) {
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
add_filter('dynaddusers_filter_user_matches', 'dynaddusers_filter_old_guest_accounts');


// For now we will try to avoid syncing all groups via cron as this may take a
// really long time. Instead we will sync all of the groups for a blog when viewing
// the user page, and add/remove individuals from all of their groups on login.
// Hopefully these incremental updates will be sufficient and avoid unneeded big
// synchronizations.
//
// // Schedule cron jobs for group syncing
// add_action('dynaddusers_group_sync_event', 'dynaddusers_sync_all_groups');
// function dynaddusers_activation() {
//   if ( !wp_next_scheduled( 'dynaddusers_group_sync_event' ) ) {
//     wp_schedule_event(time(), 'daily', 'dynaddusers_group_sync_event');
//   }
// }
// add_action('wp', 'dynaddusers_activation');
