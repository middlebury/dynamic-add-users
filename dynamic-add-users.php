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

use \DynamicAddUsers\Directory\CASDirectory\Directory AS CasDirectoryDirectory;
use \DynamicAddUsers\Directory\DirectoryBase;
use \DynamicAddUsers\UserManager;
use \DynamicAddUsers\GroupSyncer;

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


global $dynaddusers_db_version;
$dynaddusers_db_version = '0.1';

if (!defined('DYNADDUSERS_JS_DIR'))
	define('DYNADDUSERS_JS_DIR', trailingslashit( get_bloginfo('wpurl') ).'wp-content/plugins'.'/'. dirname( plugin_basename(__FILE__)));

// Redirect all requests to the WordPress built-in user creation page.
function dynaddusers_admin_redirect() {
	global $pagenow;
	if ( $pagenow == 'user-new.php' ) {
		wp_redirect( admin_url( 'users.php?page=dynaddusers' ), 301 );
	}
}
add_action( 'admin_init', 'dynaddusers_admin_redirect' );

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

// Hook for logging in.
// After login, try to load groups from the CAS Directory web service.
function dynaddusers_on_login(WP_User $user, array $attributes) {
	// Default to no groups.
	$groups = [];

	if (!empty($attributes['http://middlebury.edu/MiddleburyCollegeUID'][0])) {
		try {
			$groups = dynaddusers_get_directory()->getGroupsForUser($attributes['http://middlebury.edu/MiddleburyCollegeUID'][0]);
			dynaddusers_get_group_syncer()->syncUser($user->ID, $groups);
		} catch (Exception $e) {
			if ($e->getCode() == 404 || $e->getCode() == 400) {
				// Skip if not found in the data source.
				trigger_error('DynamicAddUsers: Tried to update user groups for  ' . $user->id . ' / '. $attributes['http://middlebury.edu/MiddleburyCollegeUID'][0] . ' but they were not found the directory service.', E_USER_NOTICE);
			} else {
				throw $e;
			}
		}
	} else {
		trigger_error('DynamicAddUsers: Tried to update user groups for  ' . $user->id . ' / '. print_r($attributes, true) . ' but they do not have a http://middlebury.edu/MiddleburyCollegeUID attribute set.', E_USER_WARNING);
	}

	// Let other modules take action based on user groups.
	// See dynaddusers_update_user_on_login(WP_User $user, array $groups) for
	// an example.
	do_action('dynaddusers_update_user_on_login', $user, $groups);
}
add_action('wp_saml_auth_new_user_authenticated', 'dynaddusers_on_login', 10, 2);
add_action('wp_saml_auth_existing_user_authenticated', 'dynaddusers_on_login', 10, 2);


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

// Hook for adding admin menus
add_action('admin_menu', 'dynaddusers_add_pages');

// action for the above hook
function dynaddusers_add_pages () {
	// Add a new submenu under Users:
    add_submenu_page('users.php','Add New Users', 'Add New', 'administrator', 'dynaddusers', 'dynaddusers_options_page');

    // Re-write the Users submenu to replace the built-in 'Add New' submenu
    // with our plugin
	global $submenu;
	// Find our key
	$dynaddusersKey = null;
	if (!isset($submenu['users.php']))
		return;
	foreach ($submenu['users.php'] as $key => $array) {
		if ($array[2] == 'dynaddusers') {
			$dynaddusersKey = $key;
			break;
		}
	}
	if ($dynaddusersKey) {
		// wp-admin/menu.php hard-codes the user-new option at position 10.
		// Replace it with our menu
		unset($submenu['users.php'][10]);
		$submenu['users.php'][10] = $submenu['users.php'][$dynaddusersKey];
		unset($submenu['users.php'][$dynaddusersKey]);
		ksort($submenu['users.php']);
	}
}

// Hooks for AJAX lookups of users/groups
add_action("admin_head", 'dynaddusers_javascript');
add_action('wp_ajax_dynaddusers_search_users', 'dynaddusers_search_users');
add_action('wp_ajax_dynaddusers_search_groups', 'dynaddusers_search_groups');


// Options page
function dynaddusers_options_page () {
	ob_start();
	if (isset($_POST['user']) && $_POST['user']) {
		try {
			$info = dynaddusers_get_directory()->getUserInfo($_POST['user']);
			if (!is_array($info)) {
				print "Could not find user '".$_POST['user']."'.";
			} else {
				try {
					// Get or create the user object.
					$user = dynaddusers_get_user_manager()->getOrCreateUser($info);
				} catch (Exception $e) {
					print "Error: ".htmlentities($e->getMessage());
				}
			}
		} catch (Exception $e) {
			// If a users wasn't found/created via info in the CAS directory, look in
			// the local user database for a matching user.
			if ($e->getCode() >= 400 && $e->getCode() < 500) {
				$user = get_user_by('login', $_POST['user']);
			}
		}

		if (empty($user)) {
			print "Could not find user '".esc_attr($_POST['user'])."'.";
		} else {
			try {
				dynaddusers_get_user_manager()->addUserToBlog($user, $_POST['role']);
				print "Added ".$user->display_name.' as '.strip_tags($_POST['role']);
			} catch (Exception $e) {
				print $e->getMessage();
			}
		}
	}
	$userResults = ob_get_clean();

	ob_start();
	$sync = true;
	if (!empty($_POST['group'])) {
		try {
			if (isset($_POST['group_sync']) && $_POST['group_sync'] == 'sync') {
				dynaddusers_get_group_syncer()->keepGroupInSync($_POST['group'], strip_tags($_POST['role']));
				$changes = dynaddusers_get_group_syncer()->syncGroup(get_current_blog_id(), $_POST['group'], strip_tags($_POST['role']));
				if (count($changes)) {
					print implode("\n<br/>", $changes);
				} else {
					print "No changes to synchronize.";
				}
			} else {
				$sync = false;
				$memberInfo = dynaddusers_get_directory()->getGroupMemberInfo($_POST['group']);
				if (!is_array($memberInfo)) {
					print "Could not find members for '".$_POST['group']."'.";
				} else {
					foreach ($memberInfo as $info) {
						try {
							$user = dynaddusers_get_user_manager()->getOrCreateUser($info);
							dynaddusers_get_user_manager()->addUserToBlog($user, $_POST['role']);
							print "Added ".$user->display_name.' as '.dynaddusers_article($_POST['role']).' '.strip_tags($_POST['role']);
						} catch (Exception $e) {
							print esc_html($e->getMessage());
						}
						print "\n\t<br/>";
					}
				}
			}
		} catch (Exception $e) {
			if ($e->getCode() == 404) {
				print "Group '".esc_html($_POST['group'])."' was not found. You may want to stop syncing.";
			} else {
				print "Error: " . esc_html($e->getMessage());
			}
		}
	}
	$groupResults = ob_get_clean();

	ob_start();
	if (!empty($_POST['sync_group_id'])) {
		if (!empty($_POST['stop_syncing_and_remove_users'])) {
			try {
				dynaddusers_get_group_syncer()->removeUsersInGroup($_POST['sync_group_id']);
				dynaddusers_get_group_syncer()->stopSyncingGroup($_POST['sync_group_id']);
			} catch (Exception $e) {
				if ($e->getCode() == 404) {
					print "Group '".esc_html($_POST['sync_group_id'])."' was not found. You may want to stop syncing.";
				} else {
					print "Error: " . esc_html($e->getMessage());
				}
			}
		} else if (!empty($_POST['stop_syncing'])) {
			dynaddusers_get_group_syncer()->stopSyncingGroup($_POST['sync_group_id']);
		} else {
			try {
				$changes = dynaddusers_get_group_syncer()->syncGroup(get_current_blog_id(), $_POST['sync_group_id'], $_POST['role']);
				print "<strong>Synchronizing  ". DirectoryBase::convertDnToDisplayPath($_POST['sync_group_id']) . ":</strong>\n<br/>";
				print " &nbsp; &nbsp; ";
				if (count($changes)) {
					print implode("\n<br/> &nbsp; &nbsp; ", $changes);
				} else {
					print "No changes to synchronize.";
				}
			} catch (Exception $e) {
				if ($e->getCode() == 404) {
					print "Group '".esc_html($_POST['sync_group_id'])."' was not found. You may want to stop syncing.";
				} else {
					print "Error: " . esc_html($e->getMessage());
				}
			}
		}
	}
	$groupSyncResults = ob_get_clean();

	print "\n<div class='wrap'>";
	print "\n<div id='icon-users' class='icon32'> <br/> </div>";
	print "\n<h2>Add New Users</h2>";
	print "\n<p>Search for users or groups by name or email address to add them to your blog.</p>";
	print "\n<form id='dynaddusers_user_form' action='".$_SERVER['REQUEST_URI']."' method='post'>";
	print "\n<h3>Add An Individual User</h3>";
	print "\n<input type='text' id='dynaddusers_user_search' name='user_search' value='' size='50'/>";
	print "\n<input type='hidden' id='dynaddusers_user' name='user' value=''/>";
	print "\n<input type='submit' value='Add User'/>";
	print "\n as ";
	dynaddusers_print_role_element();
	print "\n</form>";
	if (strlen($userResults)) {
		print "\n<p style='border: 1px solid red; color: red; padding: 0.5em;'>".$userResults."</p>";
	}

	print "\n<form id='dynaddusers_group_form' action='".$_SERVER['REQUEST_URI']."' method='post'>";
	print "\n<h3>Add Users By Group</h3>";
	print "\n<input type='text' id='dynaddusers_group_search' name='group_search' value='' size='50'/>";
	print "\n<input type='hidden' id='dynaddusers_group' name='group' value=''/>";
	print "\n<input type='submit' value='Add Group Members'/>";
	print "\n as ";
	dynaddusers_print_role_element();
	print "\n<br/>";
	print "\n<label><input type='radio' id='dynaddusers_sync' name='group_sync' value='sync' ".($sync?"checked='checked'":"")."/> Keep in Sync</label>";
	print "\n &nbsp; &nbsp; <label><input type='radio' id='dynaddusers_sync' name='group_sync' value='once' ".(!$sync?"checked='checked'":"")."/> Add once</label>";
	print "\n</form>";
	if (strlen($groupResults)) {
		print "\n<p style='border: 1px solid red; color: red; padding: 0.5em;'>".$groupResults."</p>";
	}

	print "\n<h3>Synced Groups</h3>";
	print "\n<p>Users who are members of synced groups will automatically be added-to or removed-from the site each time they log into WordPress. If you wish to fully synchronize a group so that you can see all potential users in the WordPress user-list, press the <em>Sync Now</em> button.</p>";
	$groups = dynaddusers_get_group_syncer()->getSyncedGroups();
	if (!count($groups)) {
		print "\n<p><em>none</em></p>";
	} else {
		print "\n<table id='dynaddusers_groups'>";
		print "\n<thead>";
		print "\n\t<tr><th>Group</th><th>Role</th><th>Actions</th></tr>";
		print "\n</thead>";
		print "\n<tbody>";
		foreach ($groups as $group) {
			print "\n\t<tr>";
			print "\n\t\t<td>";
			print DirectoryBase::convertDnToDisplayPath($group->group_id);
			print "\n\t\t</td>";
			print "\n\t\t<td style='padding-left: 10px; padding-right: 10px;'>";
			print $group->role;
			print "\n\t\t</td>";
			print "\n\t\t<td>";
			print "\n\t\t\t<form action='"."' method='post'>";
			print "\n\t\t\t<input type='hidden' name='sync_group_id' value='".htmlentities($group->group_id)."'/>";
			print "\n\t\t\t<input type='hidden' name='role' value='".$group->role."'/>";
			print "\n\t\t\t<input type='submit' name='sync_now' value='Sync Now'/>";
			print "\n\t\t\t<input type='submit' name='stop_syncing' value='Stop Syncing'/>";
			print "\n\t\t\t<input type='submit' name='stop_syncing_and_remove_users' value='Stop Syncing And Remove Users'/>";
			print "\n\t\t\t</form>";
			print "\n\t\t</td>";
			print "\n\t</tr>";
		}
		print "\n</tbody>";
		print "\n</table>";
	}
	if (strlen($groupSyncResults)) {
		print "\n<p style='border: 1px solid red; color: red; padding: 0.5em;'>".$groupSyncResults."</p>";
	}

	print "\n</div>";
}

add_action('admin_init', 'dynaddusers_init');
function dynaddusers_init () {
	wp_enqueue_script('jquery-ui-autocomplete');
}

function dynaddusers_javascript () {
?>

	<script type="text/javascript" >
	// <![CDATA[

	jQuery(document).ready( function($) {
		if(!$("#dynaddusers_user_search").length) {
			return;
		}
		$("#dynaddusers_user_search").autocomplete({
			source: ajaxurl + "?action=dynaddusers_search_users",
			delay: 600,
			minLength: 3,
			select: function (event, ui) {
				this.value = ui.item.label;
				$('#dynaddusers_user').val(ui.item.value);
				event.preventDefault();
			},
			focus: function (event, ui) {
				this.value = ui.item.label;
				event.preventDefault();
			},
			change: function (event, ui) {
				// Ensure that the hidden is set, though it should be by select.
				if (ui.item && ui.item.value) {
					$('#dynaddusers_user').val(ui.item.value);
				}
				// Clear out the hidden field is cleared if we don't have a chosen item or delete the choice.
				else {
					$('#dynaddusers_user').val('');
				}
			}
		});

		$("#dynaddusers_group_search").autocomplete({
			source: ajaxurl + "?action=dynaddusers_search_groups",
			delay: 600,
			minLength: 3,
			select: function (event, ui) {
				this.value = ui.item.label;
				$('#dynaddusers_group').val(ui.item.value);
				event.preventDefault();
			},
			focus: function (event, ui) {
				this.value = ui.item.label;
				event.preventDefault();
			},
			change: function (event, ui) {
				// Ensure that the hidden is set, though it should be by select.
				if (ui.item && ui.item.value) {
					$('#dynaddusers_group').val(ui.item.value);
				}
				// Clear out the hidden field is cleared if we don't have a chosen item or delete the choice.
				else {
					$('#dynaddusers_group').val('');
				}
			}
		});

		// Check for users being selected
		$('#dynaddusers_user_form').submit(function() {
			if (!$('#dynaddusers_user').val()) {
				alert('Please select a user from the search results.');
				return false;
			}
		});
		$('#dynaddusers_group_form').submit(function() {
			if (!$('#dynaddusers_group').val()) {
				alert('Please select a group from the search results.');
				return false;
			}
		});
	});

	// ]]>
	</script>

<?php
}

// Fullfill the search-users hook
function dynaddusers_search_users () {
	while (ob_get_level())
		ob_end_clean();
	header('Content-Type: text/json');
	$results = array();
	if ($_REQUEST['term']) {
		foreach (dynaddusers_get_directory()->getUsersBySearch($_REQUEST['term']) as $user) {
			$results[] = array(
				'value' => $user['user_login'],
				'label' => $user['display_name']." (".$user['user_email'].")",
			);
		}
	}
	print json_encode($results);
	exit;
}

// Fullfill the search-groups hook
function dynaddusers_search_groups () {
	while (ob_get_level())
		ob_end_clean();
	header('Content-Type: text/json');
	$results = array();
	if ($_REQUEST['term']) {
		foreach (dynaddusers_get_directory()->getGroupsBySearch($_REQUEST['term']) as $id => $displayName) {
			$results[] = array(
				'value' => $id,
				'label' => $displayName,
			);
		}
	}
	print json_encode($results);
	exit;
}

/**
 * Print out the role form element
 *
 * @return void
 * @since 1/8/10
 */
function dynaddusers_print_role_element () {
	print "\n<select name='role' id='role'>";
	$role = isset($_POST['role']) ? $_POST['role'] : get_option('default_role');
	wp_dropdown_roles($role);
	print "\n</select>";
}

/**
 * Answer the article 'a' or 'an' for a word.
 *
 * @param string $word
 * @return string 'a' or 'an'
 */
function dynaddusers_article ($word) {
	if (preg_match('/^[aeiou]/', $word))
		return 'an';
	else
		return 'a';
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
