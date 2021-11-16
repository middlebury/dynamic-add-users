<?php

namespace DynamicAddUsers\Directory;

use WP_User_Query;

/**
 * A base class with common functionality for directory implementations.
 */
abstract class DirectoryBase
{

  /**
   * Fetch an array user logins and display names for a given search string.
   * Ex: array('1' => 'John Doe', '2' => 'Jane Doe');
   *
   * @param string $search
   * @return array
   */
  public function getUsersBySearch ($search) {
    // Get matches from our directory implementation.
    $matches = $this->getUsersBySearchFromDirectory($search);

    // Merge in any users from the WordPress database that aren't already in the results.
    foreach ($this->getUsersBySearchFromDatabase($search) as $databaseMatch) {
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
    return apply_filters('dynamic_add_users__filter_user_matches', $matches);
  }

  /**
   * Fetch an array user logins and display names for a given search string.
   * Ex: array('1' => 'John Doe', '2' => 'Jane Doe');
   *
   * @param string $search
   * @return array
   */
  abstract protected function getUsersBySearchFromDirectory ($search);

  /**
   * Fetch an array user logins and display names for a given search string.
   * Ex: array('1' => 'John Doe', '2' => 'Jane Doe');
   *
   * @param string $search
   * @return array
   */
  protected function getUsersBySearchFromDatabase($search) {
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
   * Fetch an array group ids and display names for a given search string.
   * Ex: ['100' => 'All Students', '5' => 'Faculty']
   *
   * Re-write this method to use your own searching logic if you do not wish
   * to make use of the same web-service.
   *
   * @param string $search
   * @return array
   */
  public function getGroupsBySearch ($search) {
    $matches = $this->getGroupsBySearchFromDirectory();
    // Filter matches if needed.
    return apply_filters('dynamic_add_users__filter_group_matches', $matches);
  }

  /**
   * Fetch an array group ids and display names for a given search string.
   * Ex: array('100' => 'All Students', '5' => 'Faculty');
   *
   * @param string $search
   * @return array
   */
  abstract protected function getGroupsBySearchFromDirectory ($search);

  /**
   * Answer a group display name from a DN.
   *
   * @param strin $dn
   * @return string
   */
  public static function convertDnToDisplayPath ($dn) {
    // Reverse the DN and trim off the domain parts.
    $path = ldap_explode_dn($dn, 1);
    unset($path['count']);
    $path = array_slice(array_reverse($path), 2);
    return implode(' > ', $path);
  }

  /**
   * Answer the current value of a setting.
   *
   * @param string $settingKey
   *   The setting key.
   * @return string
   *   The current setting value.
   */
  public function getSetting($settingKey) {
    return get_site_option($settingKey);
  }

  /**
   * Update the value of a setting.
   *
   * @param string $settingKey
   *   The setting key.
   * @param string $value
   *   The setting value.
   */
  public function updateSetting($settingKey, $value) {
    update_site_option($settingKey, $value);
  }

}
