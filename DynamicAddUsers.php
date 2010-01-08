<?php
/*
Plugin Name: DynamicAddUsers
Plugin URI: http://chisel.middlebury.edu/
Description: Replaces the 'Add User' screen with a dynamic search for users and groups
Author: Adam Franco
Author URI: http://www.adamfranco.com/
*/

if (!defined('DYNADDUSERS_JS_DIR'))
	define('DYNADDUSERS_JS_DIR', trailingslashit( get_bloginfo('wpurl') ).'wp-content/mu-plugins'.'/'. dirname( plugin_basename(__FILE__)));


// Hook for adding admin menus
add_action('admin_menu', 'dynaddusers_add_pages');

// action for the above hook
function dynaddusers_add_pages () {
	// Add a new submenu under Options:
    add_options_page('Add New Users', 'Add New Users', 'administrator', 'dynaddusers', 'dynaddusers_options_page');

}

// Hooks for AJAX lookups of users/groups
add_action("admin_head", 'dynaddusers_javascript');
add_action('wp_ajax_dynaddusers_search_users', 'dynaddusers_search_users');
add_action('wp_ajax_dynaddusers_search_groups', 'dynaddusers_search_groups');


// Options page
function dynaddusers_options_page () {
	ob_start();
	if (isset($_POST['user']) && $_POST['user']) {
		$info = dynaddusers_get_user_info($_POST['user']);
		if (!is_array($info)) {
			print "Could not find user '".$_POST['user']."'.";
		} else {
			try {
				$user = dynaddusers_get_user($info);
				dynaddusers_add_user_to_blog($user, $_POST['role']);
				print "Added ".$user->display_name.' as '.strip_tags($_POST['role']);
			} catch (Exception $e) {
				print "Error: ".htmlentities($e->getMessage());
			}
		}
	}
	$userResults = ob_get_clean();

	ob_start();
	if (isset($_POST['group']) && $_POST['group']) {
		$memberInfo = dynaddusers_get_member_info($_POST['group']);
		if (!is_array($memberInfo)) {
			print "Could not find members for '".$_POST['group']."'.";
		} else {
			foreach ($memberInfo as $info) {
				print "\n\t<br/>";
				try {
					$user = dynaddusers_get_user($info);
					dynaddusers_add_user_to_blog($user, $_POST['role']);
					print "Added ".$user->display_name.' as '.strip_tags($_POST['role']);
				} catch (Exception $e) {
					print "Error: ".htmlentities($e->getMessage());
				}
			}
		}
	}
	$groupResults = ob_get_clean();
	print "\n<div class='wrap'>";
	print "\n<div id='icon-users' class='icon32'> <br/> </div>";
	print "\n<h2>Add New Users</h2>";
	print "\n<p>Search for users or groups by name or email address to add them to your blog.</p>";
	print "\n<form action='".$_SERVER['REQUEST_URI']."' method='post'>";
	print "\n<h3>Add An Individual User</h3>";
	print "\n<input type='text' id='dynaddusers_user_search' name='user_search' value='' size='50'/>";
	print "\n<input type='hidden' id='dynaddusers_user' name='user' value=''/>";
	print "\n<input type='submit' value='Add User'/>";
	print "\n as ";
	dynaddusers_print_role_element();
	print "\n</form>";
	print "\n<p>".$userResults."</p>";
	print "\n<form action='".$_SERVER['REQUEST_URI']."' method='post'>";
	print "\n<h3>Bulk-Add Users By Group</h3>";
	print "\n<input type='text' id='dynaddusers_group_search' name='group_search' value='' size='50'/>";
	print "\n<input type='hidden' id='dynaddusers_group' name='group' value=''/>";
	print "\n<input type='submit' value='Add Group Members'/>";
	print "\n as ";
	dynaddusers_print_role_element();
	print "\n</form>";
	print "\n<p>".$groupResults."</p>";
	print "\n</div>";
}

add_action('admin_init', 'dynaddusers_init');
function dynaddusers_init () {
	wp_enqueue_script('autocomplete', DYNADDUSERS_JS_DIR.'/jquery.autocomplete.min.js', array('jquery'));
	wp_enqueue_style('autocomplete', DYNADDUSERS_JS_DIR.'/jquery.autocomplete.css', array('jquery'));
}

function dynaddusers_javascript () {
?>

	<script type="text/javascript" >
	// <![CDATA[

	jQuery(document).ready( function($) {
		$("#dynaddusers_user_search").autocomplete(ajaxurl, {
			extraParams: {
				'action': 'dynaddusers_search_users'
			},
			delay: 600,
			max: 100,
			minChars: 3,
			formatItem: function (data, i, max, value, term) {
				if (value) {
					var parts = value.split("\t");
					if (parts[1])
						return parts[1];
					else
						return parts[0];
				} else {
					return data;
				}
			}
		}).result(function(event, data, formatted) {
			if (data) {
				var parts = data[0].split("\t");
				if (parts[1])
					$('#dynaddusers_user_search').val(parts[1]);
				else
					$('#dynaddusers_user_search').val(parts[0]);

				$('#dynaddusers_user').val(parts[0]);
			}
		});

		$("#dynaddusers_group_search").autocomplete(ajaxurl, {
			extraParams: {
				'action': 'dynaddusers_search_groups'
			},
			delay: 600,
			max: 100,
			minChars: 3,
			formatItem: function (data, i, max, value, term) {
				if (value) {
					var parts = value.split("\t");
					if (parts[1])
						return parts[1];
					else
						return parts[0];
				} else {
					return data;
				}
			}
		}).result(function(event, data, formatted) {
			if (data) {
				var parts = data[0].split("\t");
				if (parts[1])
					$('#dynaddusers_group_search').val(parts[1]);
				else
					$('#dynaddusers_group_search').val(parts[0]);

				$('#dynaddusers_group').val(parts[0]);
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
	header('Content-Type: text/plain');
	if ($_REQUEST['q']) {
		foreach (dynaddusers_get_user_matches($_REQUEST['q']) as $user) {
			print $user['user_login']."\t".$user['display_name']." (".$user['user_email'].")\n";
		}
	}
	exit;
}

// Fullfill the search-groups hook
function dynaddusers_search_groups () {
	while (ob_get_level())
		ob_end_clean();
	header('Content-Type: text/plain');
	if ($_REQUEST['q']) {
		foreach (dynaddusers_get_group_matches($_REQUEST['q']) as $id => $displayName) {
			print $id."\t".$displayName."\n";
		}
	}
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
 * @return void
 * @since 1/8/10
 */
function dynaddusers_add_user_to_blog ($user, $role) {
	global $blog_id;
	if (!$blog_id)
		throw new Exception('No current $blog_id available.');
	if (!strlen($role))
		throw new Exception('No $role specified.');

	if (is_user_member_of_blog($user->ID, $blog_id))
		throw new Exception("User ".$user->display_name." is already a member of this blog.");

	add_existing_user_to_blog(
		array(
			'user_id' => $user->ID,
			'role' => $role
		)
	);
}

/**
 * Answer the user id for the given info, creating the user record if needed.
 *
 * @param array $userInfo
 * @return object The user object.
 * @since 1/8/10
 */
function dynaddusers_get_user (array $userInfo) {
	$user = get_userdatabylogin($userInfo['user_login']);
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
	require_once(ABSPATH.WPINC.'/registration.php');

	$required = array('user_login', 'user_email', 'user_nicename', 'nickname', 'display_name');
	foreach ($required as $field) {
		if (!isset($userInfo[$field]))
			throw new Exception("$field is missing in ".print_r($userInfo, true));
		if (!strlen(trim($userInfo[$field])))
			throw new Exception("$field is empty in ".print_r($userInfo, true));
	}

	// Create a new user with a random pass since we are using external logins.
	$userId = wpmu_create_user($userInfo['user_login'], md5(rand().serialize($userInfo)), $userInfo['user_email']);
	if (!$userId)
		throw new Exception("Could not create a user for ".print_r($userInfo, true));

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
	return $matches;
}

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
	if ($entries->length !== 1)
		throw new Exception('Could not get user. Expecting 1 entry, found '.$entries->length);
	$entry = $entries->item(0);

	return dynaddusers_midd_get_info($entry, $xpath);
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
		$memberInfo[] = dynaddusers_midd_get_info($entry, $xpath);
	}
	return $memberInfo;
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
	if (defined('DYNADDUSERS_CAS_DIRECTORY_ADMIN_ACCESS'))
		$parameters['ADMIN_ACCESS'] = DYNADDUSERS_CAS_DIRECTORY_ADMIN_ACCESS;

	$doc = new DOMDocument;
	if (!$doc->load(DYNADDUSERS_CAS_DIRECTORY_URL.'?'.http_build_query($parameters)))
		throw new Exception('Could not load XML information for '.print_r($parameters, true));

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
	if ($elements->length !== 1)
		throw new Exception('Could not get user login. Expecting one cas:user element, found '.$elements->length.'.');
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

	// Reverse the DN and trim off the domain parts.
	$path = ldap_explode_dn($id, 1);
	unset($path['count']);
	$path = array_slice(array_reverse($path), 2);

	$displayName .= " (".implode(' > ', $path).")";

	return $displayName;
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