<?php
/*
Plugin Name: Dynamic Add Users
Plugin URI:
Description: Replaces the 'Add User' screen with a dynamic search for users and groups
Author: Adam Franco
Author URI: http://www.adamfranco.com/
*/

global $dynaddusers_db_version;
$dynaddusers_db_version = '0.1';

if (!defined('DYNADDUSERS_JS_DIR'))
	define('DYNADDUSERS_JS_DIR', trailingslashit( get_bloginfo('wpurl') ).'wp-content/mu-plugins'.'/'. dirname( plugin_basename(__FILE__)));

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
			$groups = dynaddusers_get_user_groups($attributes['http://middlebury.edu/MiddleburyCollegeUID'][0]);
			dynaddusers_sync_user($user->ID, $groups);
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
			$info = dynaddusers_get_user_info($_POST['user']);
			if (!is_array($info)) {
				print "Could not find user '".$_POST['user']."'.";
			} else {
				try {
					// Get or create the user object.
					$user = dynaddusers_get_user($info);
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
				dynaddusers_add_user_to_blog($user, $_POST['role']);
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
				dynaddusers_keep_in_sync($_POST['group'], strip_tags($_POST['role']));
				$changes = dynaddusers_sync_group(get_current_blog_id(), $_POST['group'], strip_tags($_POST['role']));
				if (count($changes)) {
					print implode("\n<br/>", $changes);
				} else {
					print "No changes to synchronize.";
				}
			} else {
				$sync = false;
				$memberInfo = dynaddusers_get_member_info($_POST['group']);
				if (!is_array($memberInfo)) {
					print "Could not find members for '".$_POST['group']."'.";
				} else {
					foreach ($memberInfo as $info) {
						try {
							$user = dynaddusers_get_user($info);
							dynaddusers_add_user_to_blog($user, $_POST['role']);
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
				dynaddusers_remove_users_in_group($_POST['sync_group_id']);
				dynaddusers_stop_syncing($_POST['sync_group_id']);
			} catch (Exception $e) {
				if ($e->getCode() == 404) {
					print "Group '".esc_html($_POST['sync_group_id'])."' was not found. You may want to stop syncing.";
				} else {
					print "Error: " . esc_html($e->getMessage());
				}
			}
		} else if (!empty($_POST['stop_syncing'])) {
			dynaddusers_stop_syncing($_POST['sync_group_id']);
		} else {
			try {
				$changes = dynaddusers_sync_group(get_current_blog_id(), $_POST['sync_group_id'], $_POST['role']);
				print "<strong>Synchronizing ".dynaddusers_get_group_display_name_from_dn($_POST['sync_group_id']).":</strong>\n<br/>";
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
	$groups = dynaddusers_get_synced_groups();
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
			print dynaddusers_get_group_display_name_from_dn($group->group_id);
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
		foreach (dynaddusers_get_user_matches($_REQUEST['term']) as $user) {
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
		foreach (dynaddusers_get_group_matches($_REQUEST['term']) as $id => $displayName) {
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
 * Add a user to a blog
 *
 * @param object $user
 * @param string $role
 * @param optional int $dest_blog_id
 * @param option string $sync_group If passed, mark this role assignment as synchronized with this group.
 * @return void
 * @since 1/8/10
 */
function dynaddusers_add_user_to_blog ($user, $role, $blog_id = null, $sync_group = null) {
	if (is_null($blog_id)) {
		$blog_id = get_current_blog_id();
	}
	if (!$blog_id)
		throw new Exception('No current $blog_id available.');
	if (!strlen($role))
		throw new Exception('No $role specified.');

	$role_levels = array(
		'subscriber' => 1,
		'contributor' => 2,
		'author' => 3,
		'editor' => 4,
		'administrator' => 5,
	);

	if (is_user_member_of_blog($user->ID, $blog_id)) {
		$existing_role = dynaddusers_get_existing_role($user->ID, $blog_id);
		if ($role_levels[$existing_role] > $role_levels[$role]) {
			throw new Exception("User ".$user->display_name." is already ".dynaddusers_article($existing_role).' '.$existing_role." of this blog, not reducing to ".dynaddusers_article($role).' '.$role.'.');
		} else if ($role_levels[$existing_role] == $role_levels[$role]) {
			throw new Exception("User ".$user->display_name." is already ".dynaddusers_article($existing_role).' '.$existing_role." of this blog.");
		}
	}

	add_user_to_blog($blog_id, $user->ID, $role);

	// Note that they are now going to be managed as part of the group.
	if ($sync_group) {
		global $wpdb;
		$sync_table = $wpdb->base_prefix . "dynaddusers_synced";
		$sync_exists = $wpdb->get_row($wpdb->prepare("SELECT * FROM $sync_table WHERE blog_id = %d AND group_id = %s AND user_id = %d", $blog_id, $sync_group, $user->ID));
		if (is_null($sync_exists)) {
			$wpdb->insert($sync_table, array('blog_id' => $blog_id, 'group_id' => $sync_group, 'user_id' => $user->ID));
		}
	}
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
 * Remove a user from a blog
 *
 * @param int $user_id
 * @param string $group_id
 * @param int $blog_id
 * @return void
 */
function dynaddusers_remove_user_from_blog ($user_id, $group_id, $blog_id) {
	// remove the user from the blog if they are a member
	if (is_user_member_of_blog($user_id, $blog_id))
		remove_user_from_blog($user_id, $blog_id);

	// Update our list of synced users, cleaning up if needed.
	global $wpdb;
	$synced = $wpdb->base_prefix . "dynaddusers_synced";
	$wpdb->query($wpdb->prepare(
		"DELETE FROM
			$synced
		WHERE
			blog_id = %d
			AND group_id = %s
			AND user_id = %d
		",
		$blog_id,
		$group_id,
		$user_id
	));
}

/**
 * Answer the user id for the given info, creating the user record if needed.
 *
 * @param array $userInfo
 * @return object The user object.
 * @since 1/8/10
 */
function dynaddusers_get_user (array $userInfo) {
	$user = get_user_by('login', $userInfo['user_login']);
	if (is_object($user))
		return $user;

	// Create a new user
	return dynaddusers_create_user($userInfo);
}

/**
 * Create a new user-record for the given info and return the new id.
 *
 * Modify this function if you need to have account-information emailed to the user.
 *
 * @param array $userInfo
 * @return object The user object for the new user
 * @since 1/8/10
 */
function dynaddusers_create_user (array $userInfo) {
	$required = array('user_login', 'user_email', 'user_nicename', 'nickname', 'display_name');
	foreach ($required as $field) {
		if (!isset($userInfo[$field]))
			throw new Exception("$field is missing in ".print_r($userInfo, true));
		if (!strlen(trim($userInfo[$field])))
			throw new Exception("$field is empty in ".print_r($userInfo, true));
	}

	// Create a new user with a random pass since we are using external logins.
	$userId = wpmu_create_user($userInfo['user_login'], md5(rand().serialize($userInfo)), $userInfo['user_email']);

	// If we don't have a user-id, check for old accounts with conflicting email
	// addresses.
	if (!$userId) {
		$oldUser = get_user_by('email', $userInfo['user_email']);
		if (is_object($oldUser)) {
			// Verify that the old user no longer exists in the directory.
			try {
				$oldUserInfo = dynaddusers_get_user_info($oldUser->get('user_login'));
				if (!empty($oldUserInfo)) {
					throw new Exception("Could not create a user for ".print_r($userInfo, true) . '. An existing user with that email address exists in WordPress and the directory.');
				}
			}
			catch (Exception $e) {
				if ($e->getCode() == 404) {
					// The old user's ID no longer exists in the directory. It should be safe
					// to move their email out of the way.
					if (preg_match('/^(.+)@(middlebury|miis)\.edu$/', $userInfo['user_email'], $m)) {
						// Change the old-user's email to something else.
						$replacementEmail = $m[1].'-old-replaced@'.$m[2].'.edu';
						wp_update_user(['ID' => $oldUser->id, 'user_email' => $replacementEmail]);
						trigger_error('DynamicAddUsers: Renamed email for old account ' . $oldUser->id . ' / '. $oldUser->get('user_login') . ' from ' . $userInfo['user_email'] . ' to ' . $replacementEmail . '. This address was reused in new user account ' . $userInfo['user_login'], E_USER_WARNING);

						// Try creating the new user account again.
						$userId = wpmu_create_user($userInfo['user_login'], md5(rand().serialize($userInfo)), $userInfo['user_email']);
					}
					else {
						throw new Exception("Could not create a user for ".print_r($userInfo, true) . '. An existing user with that email address exists in WordPress but the email domain is not middlebury.edu or miis.edu.');
					}
				}
				else {
					throw $e;
				}
			}
		}
	}

	// If we still don't have a userId, throw an exception.
	if (!$userId) {
		throw new Exception("Could not create a user for ".print_r($userInfo, true));
	}

	// Add the rest of the user information
	$userInfo['ID'] = $userId;
	wp_update_user($userInfo);

	$user = get_userdata($userId);
	if (!is_object($user))
		throw new Exception("Problem fetching information for $userId");
	return $user;
}

/*********************************************************
 * The methods below are particular to the authentication/directory
 * system this plugin is working against. They should be reworked
 * if going against a different sort of directory system.
 *********************************************************/


/**
 * Fetch an array user logins and display names for a given search string.
 * Ex: array('1' => 'John Doe', '2' => 'Jane Doe');
 *
 * Re-write this method to use your own searching logic if you do not wish
 * to make use of the same web-service.
 *
 * @param string $search
 * @return array
 * @since 1/8/10
 */
function dynaddusers_get_user_matches ($search) {
	$xpath = dynaddusers_midd_lookup(array(
		'action'	=> 'search_users',
		'query'		=> $search,
	));
	$matches = array();
	foreach($xpath->query('/cas:results/cas:entry') as $entry) {
		$matches[] = dynaddusers_midd_get_info($entry, $xpath);
	}
	// Merge in any users from the WordPress database that aren't already in the results.
	$databaseMatches = dynaddusers_get_user_matches_from_db($search);
	foreach ($databaseMatches as $databaseMatch) {
		$inResults = FALSE;
		foreach ($matches as $match) {
			if (mb_strtolower($match['user_login']) == mb_strtolower($databaseMatch['user_login'])) {
				$inResults = TRUE;
				break;
			}
		}
		if (!$inResults) {
			$matches[] = $databaseMatch;
		}
	}

	// Filter matches if needed.
	return apply_filters('dynaddusers__filter_user_matches', $matches);
}

/**
 * Fetch an array user logins and display names for a given search string.
 * Ex: array('1' => 'John Doe', '2' => 'Jane Doe');
 *
 * @param string $search
 * @return array
 */
function dynaddusers_get_user_matches_from_db($search) {
	$query = new WP_User_Query( [
		'search' => '*'.esc_attr( $search ).'*',
		'search_columns' => [
			'user_login',
			'user_nicename',
			'user_email',
		],
		'blog_id' => 1,
	] );
	$results = $query->get_results();
	$matches = [];
	foreach ($results as $result) {
		$data = $result->data;
		$matches[] = [
			'user_login' => $data->user_login,
			'user_email' => $data->user_email,
			'user_nicename' => $data->user_nicename,
			'display_name' => $data->display_name,
		];
	}
	return $matches;
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

/**
 * Fetch an array group ids and display names for a given search string.
 * Ex: array('100' => 'All Students', '5' => 'Faculty');
 *
 * Re-write this method to use your own searching logic if you do not wish
 * to make use of the same web-service.
 *
 * @param string $search
 * @return array
 * @since 1/8/10
 */
function dynaddusers_get_group_matches ($search) {
	$xpath = dynaddusers_midd_lookup(array(
		'action'	=> 'search_groups',
		'query'		=> $search,
	));
	$matches = array();
	foreach($xpath->query('/cas:results/cas:entry') as $entry) {
		$matches[dynaddusers_midd_get_group_id($entry, $xpath)] = dynaddusers_midd_get_group_display_name($entry, $xpath);
	}
	return $matches;
}

/**
 * Fetch an array user info for a login string.
 * Elements:
 *	user_login		The login field that will match or be inserted into the users table.
 *	user_email
 *	user_nicename
 *	nickname
 *	display_name
 *	first_name
 *	last_name
 *
 * Re-write this method to use your own searching logic if you do not wish
 * to make use of the same web-service.
 *
 * @param string $login
 * @return array or NULL if not user login found
 * @since 1/8/10
 */
function dynaddusers_get_user_info ($login) {
	$xpath = dynaddusers_midd_lookup(array(
		'action'	=> 'get_user',
		'id'		=> $login,
	));
// 	var_dump($xpath->document->saveXML());
	$entries = $xpath->query('/cas:results/cas:entry');
	if ($entries->length < 1) {
		throw new Exception('Could not get user. Expecting 1 entry, found '.$entries->length, 404);
	}
	else if ($entries->length > 1) {
		throw new Exception('Could not get user. Expecting 1 entry, found '.$entries->length);
	}

	$entry = $entries->item(0);

	return dynaddusers_midd_get_info($entry, $xpath);
}

/**
 * Fetch an array of group DNs for a user.
 *
 * Throws an exception if the user isn't found in the underlying data-source.
 *
 * @param string $login
 * @return array or NULL if not user login found
 * @since 1/8/10
 */
function dynaddusers_get_user_groups ($login) {
	$xpath = dynaddusers_midd_lookup(array(
		'action'	=> 'get_user',
		'id'		=> $login,
		'include_membership' => 'TRUE',
	));
	$entries = $xpath->query('/cas:results/cas:entry');
	if ($entries->length < 1) {
		throw new Exception('Could not get user. Expecting 1 entry, found '.$entries->length, 404);
	}
	else if ($entries->length > 1) {
		throw new Exception('Could not get user. Expecting 1 entry, found '.$entries->length);
	}

	$groups = [];
	foreach ($xpath->query('cas:attribute[@name="MemberOf"]', $entries->item(0)) as $attribute) {
		$groups[] = $attribute->getAttribute('value');
	}
	return $groups;
}

/**
 * Fetch a two-dimensional array user info for every member of a group.
 * Ex:
 *	array(
 *		array(
 *			'user_login' => '1',
 *			'user_email' => 'john.doe@example.com',
 *			...
 *		),
 *		array(
 *			'user_login' => '2',
 *			'user_email' => 'jane.doe@example.com',
 *			...
 *		),
 *		...
 *	);
 *
 *
 * Elements:
 *	user_login		The login field that will match or be inserted into the users table.
 *	user_email
 *	user_nicename
 *	nickname
 *	display_name
 *	first_name
 *	last_name
 *
 * Re-write this method to use your own searching logic if you do not wish
 * to make use of the same web-service.
 *
 * @param string $groupId
 * @return array or NULL if group id not found
 * @since 1/8/10
 */
function dynaddusers_get_member_info ($groupId) {
	$xpath = dynaddusers_midd_lookup(array(
		'action'	=> 'get_group_members',
		'id'		=> $groupId,
	));
// 	var_dump($xpath->document->saveXML());
	$memberInfo = array();
	foreach($xpath->query('/cas:results/cas:entry') as $entry) {
		try {
			$memberInfo[] = dynaddusers_midd_get_info($entry, $xpath);
		} catch (Exception $e) {
			if ($e->getCode() == 65004) {
				// Ignore any groups that we encounter
			} else {
				throw $e;
			}
		}
	}
	return $memberInfo;
}

/**
 * Answer an array of user ids synced to a blog for a group.
 *
 * @param int $blog_id
 * @param string $group_id
 * @return array
 */
function dynaddusers_get_synced_users_for_group($blog_id, $group_id) {
	// Get a list of users currently synced by the DAU
	global $wpdb;
	$table = $wpdb->base_prefix . "dynaddusers_synced";
	$query = "SELECT user_id
			FROM $table
			WHERE
				blog_id = %d
				AND group_id = %s";
	$args = array($blog_id, $group_id);
	return $wpdb->get_col($wpdb->prepare($query, $args));
}

/**
 * Lookup directory information and return it as an XPath object
 *
 * @param array $parameters
 * @return DOMXPath
 * @since 1/8/10
 */
function dynaddusers_midd_lookup (array $parameters) {
	if (!defined('DYNADDUSERS_CAS_DIRECTORY_URL'))
		throw new Exception('DYNADDUSERS_CAS_DIRECTORY_URL must be defined.');
	$args = [
		'timeout' => 120,
		'user-agent' => 'WordPress DynamicAddUsers',
	];
	if (defined('DYNADDUSERS_CAS_DIRECTORY_ADMIN_ACCESS')) {
		$args['headers']["Admin-Access"] = DYNADDUSERS_CAS_DIRECTORY_ADMIN_ACCESS;
	}
	$response = wp_remote_get(DYNADDUSERS_CAS_DIRECTORY_URL.'?'.http_build_query($parameters), $args);
	if ( !is_array( $response )) {
		throw new Exception('Could not load XML information for '.print_r($parameters, true));
	}
	$xml_string = $response['body'];
	if (!$xml_string || $response['response']['code'] >= 300)
		throw new Exception('Could not load XML information for '.print_r($parameters, true), $response['response']['code']);
	$doc = new DOMDocument;
	if (!$doc->loadXML($xml_string))
		throw new Exception('Could not load XML information for '.print_r($parameters, true), $response['response']['code']);

	$xpath = new DOMXPath($doc);
	$xpath->registerNamespace('cas', 'http://www.yale.edu/tp/cas');

	return $xpath;
}

/**
 * Answer the user info matching a cas:entry element.
 *
 * @param DOMElement $entry
 * @param DOMXPath $xpath
 * @return string
 * @since 1/8/10
 */
function dynaddusers_midd_get_info (DOMElement $entry, DOMXPath $xpath) {
	$info = array();
	$info['user_login'] = dynaddusers_midd_get_login($entry, $xpath);
	$info['user_email'] = dynaddusers_midd_get_attribute('EMail', $entry, $xpath);

	preg_match('/^(.+)@(.+)$/', $info['user_email'], $matches);
	$emailUser = $matches[1];
	$emailDomain = $matches[2];
	if ($login = dynaddusers_midd_get_attribute('Login', $entry, $xpath))
		$nicename = $login;
	else
		$nicename = $emailUser;

	$info['user_nicename'] = $nicename;
	$info['nickname'] = $nicename;
	$info['first_name'] = dynaddusers_midd_get_attribute('FirstName', $entry, $xpath);
	$info['last_name'] = dynaddusers_midd_get_attribute('LastName', $entry, $xpath);
	$info['display_name'] = $info['first_name']." ".$info['last_name'];
	return $info;
}

/**
 * Answer the login field for an cas:entry element.
 *
 * @param DOMElement $entry
 * @param DOMXPath $xpath
 * @return string
 * @since 1/8/10
 */
function dynaddusers_midd_get_login (DOMElement $entry, DOMXPath $xpath) {
	$elements = $xpath->query('./cas:user', $entry);
	if ($elements->length !== 1) {
		if ($xpath->query('./cas:group', $entry)->length)
			throw new Exception('Could not get user login. Expecting one cas:user element, found a cas:group instead.', 65004);
		else
			throw new Exception('Could not get user login. Expecting one cas:user element, found '.$elements->length.'.');
	}
	return $elements->item(0)->nodeValue;
}

/**
 * Answer the group id field for an cas:entry element.
 *
 * @param DOMElement $entry
 * @param DOMXPath $xpath
 * @return string
 * @since 1/8/10
 */
function dynaddusers_midd_get_group_id (DOMElement $entry, DOMXPath $xpath) {
	$elements = $xpath->query('./cas:group', $entry);
	if ($elements->length !== 1)
		throw new Exception('Could not get group id. Expecting one cas:group element, found '.$elements->length.'.');
	return $elements->item(0)->nodeValue;
}

/**
 * Answer the group display name for an cas:entry element.
 *
 * @param DOMElement $entry
 * @param DOMXPath $xpath
 * @return string
 * @since 1/8/10
 */
function dynaddusers_midd_get_group_display_name (DOMElement $entry, DOMXPath $xpath) {
	$displayName = dynaddusers_midd_get_attribute('DisplayName', $entry, $xpath);
	$id = dynaddusers_midd_get_group_id($entry, $xpath);

	$displayName .= " (".dynaddusers_get_group_display_name_from_dn($id).")";

	return $displayName;
}

/**
 * Answer a group display name from a DN.
 *
 * @param strin $dn
 * @return string
 */
function dynaddusers_get_group_display_name_from_dn ($dn) {
	// Reverse the DN and trim off the domain parts.
	$path = ldap_explode_dn($dn, 1);
	unset($path['count']);
	$path = array_slice(array_reverse($path), 2);
	return implode(' > ', $path);
}

/**
 * Answer the login field for an cas:entry element.
 *
 * @param string $attribute
 * @param DOMElement $entry
 * @param DOMXPath $xpath
 * @return string
 * @since 1/8/10
 */
function dynaddusers_midd_get_attribute ($attribute, DOMElement $entry, DOMXPath $xpath) {
	$elements = $xpath->query('./cas:attribute[@name = "'.$attribute.'"]', $entry);
	if (!$elements->length)
		return '';
	return $elements->item(0)->getAttribute('value');
}

/*********************************************************
 * The methods below are related to synchronizing groups.
 *********************************************************/

/**
 * Answer an array of groups that will be kept in sync for this blog.
 *
 * @return array
 */
function dynaddusers_get_synced_groups () {
	global $wpdb;
	$groups = $wpdb->base_prefix . "dynaddusers_groups";
	return $wpdb->get_results($wpdb->prepare(
		"SELECT
			*
		FROM
			$groups
		WHERE
			blog_id = %d
		ORDER BY
			group_id
		",
		get_current_blog_id()
	));
}

/**
 * Keep a group in sync for the current blog.
 *
 * @param string $group_id
 * @param string $role
 * @return void
 */
function dynaddusers_keep_in_sync ($group_id, $role) {
	$synced = dynaddusers_get_synced_groups();
	foreach ($synced as $group) {
		if ($group->group_id == $group_id && $group->role == $role)
			return false;
	}
	global $wpdb;
	$groups = $wpdb->base_prefix . "dynaddusers_groups";
	$wpdb->delete($groups, array(
		'blog_id' => get_current_blog_id(),
		'group_id' => $group_id,
	));
	$wpdb->insert($groups, array(
		'blog_id' => get_current_blog_id(),
		'group_id' => $group_id,
		'role' => $role,
	));
}

/**
 * Stop syncing a group.
 *
 * @param string $group_id
 * @return void
 */
function dynaddusers_stop_syncing ($group_id) {
	global $wpdb;
	$groups = $wpdb->base_prefix . "dynaddusers_groups";
	$wpdb->query($wpdb->prepare(
		"DELETE FROM
			$groups
		WHERE
			blog_id = %d
			AND group_id = %s
		",
		get_current_blog_id(),
		$group_id
	));
	$synced = $wpdb->base_prefix . "dynaddusers_synced";
	$wpdb->query($wpdb->prepare(
		"DELETE FROM
			$synced
		WHERE
			blog_id = %d
			AND group_id = %s
		",
		get_current_blog_id(),
		$group_id
	));
}

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

/**
 * Synchronize all groups.
 *
 * @return void
 */
function dynaddusers_sync_all_groups () {
	global $wpdb;
	$groups_table = $wpdb->base_prefix . "dynaddusers_groups";
	$groups_to_sync = $wpdb->get_results($wpdb->prepare(
		"SELECT
			*
		FROM
			$groups_table
		ORDER BY
			blog_id
		"
	));
	dynaddusers_sync_groups($groups_to_sync);
}

/**
 * Sync a list of groups.
 *
 * @param array $groups
 * @return void
 */
function dynaddusers_sync_groups (array $groups) {
	foreach ($groups as $group) {
		try {
			dynaddusers_sync_group($group->blog_id, $group->group_id, $group->role);
		} catch (Exception $e) {
			user_error($e->getMessage(), E_USER_WARNING);
		}
	}
}

/**
 * Synchronize a group.
 *
 * @param int $blog_id
 * @param string $groups_id
 * @param string $role
 * @return void
 */
function dynaddusers_sync_group ($blog_id, $group_id, $role) {
	global $wpdb;
	$role_levels = array(
		'subscriber' => 1,
		'contributor' => 2,
		'author' => 3,
		'editor' => 4,
		'administrator' => 5,
	);
	$changes = array();
	$memberInfo = dynaddusers_get_member_info($group_id);
	if (!is_array($memberInfo)) {
		throw new Exception("Could not find members for '".$group_id."'.");
	} else {
		$user_ids = array();
		foreach ($memberInfo as $info) {
			try {
				$user = dynaddusers_get_user($info);
				$user_ids[] = $user->ID;
				$existing_role = dynaddusers_get_existing_role($user->ID, $blog_id);
				if (!is_user_member_of_blog($user->ID, $blog_id) || $role_levels[$role] > $role_levels[$existing_role]) {
					dynaddusers_add_user_to_blog($user, $role, $blog_id, $group_id);
					$changes[] = 'Added '.$user->display_name.' as '.dynaddusers_article($role).' '.$role.'.';
				}
			} catch (Exception $e) {
				user_error($e->getMessage(), E_USER_WARNING);
			}
		}

		// Remove users who have left the group.
		$table = $wpdb->base_prefix . "dynaddusers_synced";
		$query = "SELECT user_id
				FROM $table
				WHERE
					blog_id = %d
					AND group_id = %s";
		$args = array($blog_id, $group_id);
		if (count($user_ids)) {
			$placeholders = array_fill(0, count($user_ids), '%d');
			$query .= "\n\t AND user_id NOT IN (".implode(', ', $placeholders).")";
			$args = array_merge($args, $user_ids);
		}
		$missing_users = $wpdb->get_col($wpdb->prepare($query, $args));
		foreach ($missing_users as $user_id) {
			$existing_role = dynaddusers_get_existing_role($user_id, $blog_id);
			$user = new WP_User($user_id);
			if (is_user_member_of_blog($user_id, $blog_id) && $role_levels[$role] == $role_levels[$existing_role]) {
				remove_user_from_blog($user_id, $blog_id);
				$changes[] = 'Removed '.$user->display_name.'.';
			}
		}

		// Update our list of synced users.
		$query = "DELETE FROM $table
				WHERE
					blog_id = %d
					AND group_id = %s";
		$wpdb->query($wpdb->prepare($query, $args));
		foreach ($user_ids as $user_id) {
			$wpdb->insert($table, array('blog_id' => $blog_id, 'group_id' => $group_id, 'user_id' => $user_id));
		}

		// Record our sync time.
		$groups_table = $wpdb->base_prefix . "dynaddusers_groups";
		$wpdb->query($wpdb->prepare(
			"UPDATE
				$groups_table
			SET
				last_sync = NOW()
			WHERE
				blog_id = %d
				AND group_id = %s
			",
			$blog_id,
			$group_id
		));
	}
	return $changes;
}

/**
 * Get an existing role for the user.
 *
 * @param int $user_id
 * @param optional int $blog_id
 *		If NULL or not passed, the current blog will be queried.
 * @return mixed string role or NULL if none.
 */
function dynaddusers_get_existing_role($user_id, $blog_id = NULL) {
	if (!is_null($blog_id)) {
		switch_to_blog($blog_id);
	}
	$user = new WP_User( $user_id );
	$role_levels = array(
		'subscriber' => 1,
		'contributor' => 2,
		'author' => 3,
		'editor' => 4,
		'administrator' => 5,
	);
	$existing_role = NULL;
	foreach (array_keys(array_reverse($role_levels)) as $role) {
		if (isset($user->allcaps[$role]) && $user->allcaps[$role]) {
			$existing_role = $role;
			break;
		}
	}
	if (!is_null($blog_id)) {
		restore_current_blog();
	}
	return $existing_role;
}

/**
 * Synchronize a user given their new list of groups.
 *
 * @param object $user
 * @param array $groups_ids
 * @return void
 */
function dynaddusers_sync_user ($user_id, array $group_ids) {
	global $wpdb;

	$group_table = $wpdb->base_prefix . "dynaddusers_groups";
	$synced_table = $wpdb->base_prefix . "dynaddusers_synced";

	$role_levels = array(
		'subscriber' => 1,
		'contributor' => 2,
		'author' => 3,
		'editor' => 4,
		'administrator' => 5,
	);

	$user = new WP_User( $user_id );

	// Get a list of all existing roles handled by the DAU
	$query = "SELECT
		g.blog_id, g.group_id, g.role
	FROM
		$group_table g
	WHERE
		";
	$args = array();
	if (count($group_ids)) {
		$placeholders = array_fill(0, count($group_ids), '%s');
		$query .= "\n\tg.group_id IN (".implode(', ', $placeholders).")";
		$args = array_merge($args, $group_ids);
	}
	$roles_to_ensure = $wpdb->get_results($wpdb->prepare($query, $args));
	foreach ($roles_to_ensure as $role_to_ensure) {
		$existing_role = dynaddusers_get_existing_role($user_id, $role_to_ensure->blog_id);
		// If the user has no role or a lesser role.
		// Otherwise, we'll assume that it was customized by the blog admin and ignore it.
		if (empty($existing_role)) {
			dynaddusers_add_user_to_blog($user, $role_to_ensure->role, $role_to_ensure->blog_id, $role_to_ensure->group_id);
		}
		// If the user has a lesser role than their group would provide, upgrade them
		else if ($role_levels[$existing_role] < $role_levels[$role_to_ensure->role]) {
			dynaddusers_add_user_to_blog($user, $role_to_ensure->role, $role_to_ensure->blog_id, $role_to_ensure->group_id);
		}
	}
	// Switch back to the current blog if we left it.
	restore_current_blog();

	// Get a list of the blogs where the user was previously added, but is no longer
	// in one of the groups
	$query = "SELECT
		g.blog_id, g.group_id, g.role
	FROM
		$group_table g
		INNER JOIN $synced_table s ON (g.blog_id = s.blog_id AND g.group_id = s.group_id)
	WHERE
		s.user_id = %s
		";
	$args = array($user_id);
	if (count($group_ids)) {
		$placeholders = array_fill(0, count($group_ids), '%s');
		$query .= "\n\t AND s.group_id NOT IN (".implode(', ', $placeholders).")";
		$args = array_merge($args, $group_ids);
	}
	$roles_gone = $wpdb->get_results($wpdb->prepare($query, $args));
	foreach ($roles_gone as $role_gone) {
		// If the group-role assignment is gone...
		// ...AND the user currently has that role...
		// ...AND the user doesn't have that role through another group
		// take away that role.
		// If they have a lesser role through another group apply that,
		// otherwise, remove them from the blog.
		$existing_role = dynaddusers_get_existing_role($user_id, $role_gone->blog_id);
		// Only change or remove the role if the role was the same as the one
		// set by this plugin before. Otherwise, we'll assume that it was customized
		// by the blog admin and ignore it.
		if (empty($existing_role) || $existing_role == $role_gone->role) {
			// Verify that the group still exists and don't remove the user if it is gone.
			// This is particularly a problem for class groups which disapear after several years.
			try {
				$memberInfo = dynaddusers_get_member_info($role_gone->group_id);
				dynaddusers_remove_user_from_blog($user_id, $role_gone->group_id, $role_gone->blog_id);
			} catch (Exception $e) {
				// Skip removal for missing groups
			}

		}
	}

	// Switch back to the current blog if we left it.
	restore_current_blog();
}

/**
 * Remove all of the users in a group from a blog.
 *
 * @param string $group_id
 * @return void
 */
function dynaddusers_remove_users_in_group ($group_id) {
	$blog_id = get_current_blog_id();
	$members = dynaddusers_get_synced_users_for_group($blog_id, $group_id);
	if (!is_array($members)) {
		print "Couldn't fetch group info.";
		return;
	}
	foreach ($members as $user_id) {
		try {
			$user = get_userdata($user_id);
			if (is_user_member_of_blog($user_id, $blog_id) && $user_id != get_current_user_id()) {
				remove_user_from_blog($user_id, $blog_id);
				print "Removed ".$user->display_name."<br/>\n";
			}
		} catch (Exception $e) {
			print "Error: ".htmlentities($e->getMessage());
		}
	}
}
