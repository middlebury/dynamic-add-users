<?php

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
add_filter('dynamic_add_users__filter_user_matches', 'dynaddusers_filter_old_guest_accounts');

/**
 * Set the authentication type to "shibboleth_account" when adding new accounts.
 *
 * @param WP_User $user
 */
function dynaddusers_set_cp_auth_type(WP_User $user) {
  // Set the authentication type for new accounts.
	if (defined('CAMPUSPRESS_AUTH_SHIBBOLETH')) {
	  update_usermeta($user->ID, 'shibboleth_account', true);
	}
}
add_action('dynamic_add_users__update_user_on_create', 'dynaddusers_set_cp_auth_type', 10, 1);
