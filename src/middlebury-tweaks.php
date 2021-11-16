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
