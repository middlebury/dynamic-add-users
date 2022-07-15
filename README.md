# Dynamic Add Users

Dynamic Add Users is a WordPress plugin that replaces the native 'Add User'
screen with a dynamic directory search.

This plugin also allows searching for groups in a directory and bulk-adding
group members to a site as well as synchronizing site-roles based on group-membership.
It provides several services that other plugins can use to look up users and
groups and apply roles to users.

## Components

Dynamic Add Users has several components that work together to provide its
functionality:
 * A `DynamicAddUsersPlugin` singleton to manage configuration and access to services.
 * A **Directory** service with providers that allow lookup of user and group information.
 * **Login-Hook** providers that trigger sync operations at login time and may
   provide access to user identifiers present in the login response.
 * **User Manager** and **Group Syncer** services and APIs for creating user accounts
   and bulk-adding users.
 * Network administrator configuration screens.
 * A replacement "Add Users" screen for site administrators.

### The **Directory** Service

The Dynamic Add Users **Directory** service provides an API for searching for
users by name/email in some underlying data source. The resulting
user-information can be utilized by other components to dynamically provision
accounts into WordPress without the user having to already have logged in.

Each **Directory** service implementation provides API methods defined in
[`DirectoryInterface.php`](https://github.com/middlebury/dynamic-add-users/blob/main/src/DynamicAddUsers/Directory/DirectoryInterface.php):
 * `getUsersBySearch($search)`
 * `getGroupsBySearch ($search)`
 * `getUserInfo ($login)`
 * `getGroupsForUser ($login)`
 * `getGroupMemberInfo ($groupId)`

#### Implementations
 * **Null** - The Null directory provider is enabled by default and allows
   the Dynamic Add Users screen to work with only native WordPress accounts with
   no lookup in external data-sources.
 * **CAS Directory** - The CAS Directory provider allows user/group search and
   lookup via Middlebury's custom [CAS Directory web service](https://mediawiki.middlebury.edu/LIS/CAS_Directory).
   Usage requires configuring the URL of the service as well as access tokens.
   Groups are identified by their DistinguishedName attribute
   (e.g. <code>CN=ITS Staff,OU=General,OU=Groups,DC=middlebury,DC=edu</code>)
   and users are identified by their <code>middleburyCollegeUID</code> attribute.
 * **Microsoft Graph** - The Graph directory provider allows user/group
   search and lookup in Azure AD via Microsoft's
   [Graph API](https://docs.microsoft.com/en-us/graph/api/overview?view=graph-rest-1.0).
   Groups are referenced by their ID property in AzureAD (a GUID value like
   <code>20f74211-e62b-40f9-b00a-513a01a2e431</code>). Users are referenced by a
   configurable primary identifier attribute and an optional secondary/fall-back
   identifier. Transforms may be applied to these identifiers to allow the
   external identifier to meet WordPress's allowed-character restrictions and
   length limits for usernames.

### The **Login-Hook**

The Dynamic Add Users **Login-Hook** providers evaluate user/login attributes
at login time to map the user identifiers present in the login response to user
identifiers known to the directory service. This component allows hooking into
authentication plugin's own login actions that may provide access to additional
attributes that are not present in the native WordPress `wp_login` action.

Each **Login-Hook** implementation will register itself with an appropriate
login action and if possible, return a user-id that is known to the currently
configured Directory service.

#### Implementations
* **Null** - This Login-Hook is enabled by default and always returns a
  null result. Useful as a placeholder when debugging or when no mapping is
  desired.
* **User Login** - This Login-Hook hooks into the native `wp_login` action and
  always returns the `user_login` field on the WordPress user account. If these
  values are known to the currently-configured directory implementation, then
  this is the appropriate Login-Hook to use. It needs no configuration.
* **WP SAML Auth** - This Login-Hook hooks into login flow for the [WP SAML Auth
  plugin](https://wordpress.org/plugins/wp-saml-auth/) and provides access to additional SAML attributes that may be present
  in the authentication response from that plugin. It requires configuration of
  the particular SAML attribute to use when doing Directory lookups.

## Configuration
By default, Dynamic Add Users uses place-holder Null **Directory** and **Login-Hook**
implementations. The implementations in-use and implementation-specific configuration
can be set under **Network Admin &rarr; Settings &rarr; Dynamic Add Users**.

Also included with the Network Admin settings is a **Test** screen that allows
validation of the current configuration and its operation.

## APIs
Dynamic Add Users provides several services, actions, and filters that other
plugins can use to work with it or extend its abilities.

### Using Services
The singleton DynamicAddUsersPlugin provides access to all services of the plugin
and can be accessed via the `dynamic_add_users()` function. The configured service
implementations can then be accessed via the methods detailed in
[`DynamicAddUsersPluginInterface.php`](https://github.com/middlebury/dynamic-add-users/blob/main/src/DynamicAddUsers/DynamicAddUsersPluginInterface.php): `getDirectory()`, `getLoginHook()`, `getUserManager()`, and `getGroupSyncer()`.

Example:
```
$userInfo = dynamic_add_users()->getDirectory()->getUserInfo($externalUserId);
$wordpressUser = dynamic_add_users()->getUserManager()->getOrCreateUser($userInfo);
```

### Actions and Filters

#### `dynamic_add_users__update_user_on_login`

Set/unset roles and capabilities for the user based on groups. This is useful for
setting group-based capabilities at login time.

Example:

```
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
```

#### `dynamic_add_users__filter_user_matches`

Filter directory results when searching for users. This can be useful to allow
removal of service accounts or old/inactivated accounts from the results
presented to site-administrators while searching.

Example:

```
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
```

#### `dynamic_add_users__filter_group_matches`

Filter groups out of the results provided by the underlying Directory service.
This may be useful if certain groups should not be exposed to site administrators.

Example:

```
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
```

### Adding Service implementations

Plugins may provide additional implementations of the **Directory** service by
implementing the `\DynamicAddUsers\Directory\DirectoryInterface` in a class and
ensuring that the class is loaded at runtime before DynamicAddUsers executes.

Plugins may provide additional **Login-Hook** implementations by
implementing the `\DynamicAddUsers\LoginHook\LoginHookInterface` in a class
and ensuring that the class is loaded at runtime before DynamicAddUsers executes.
