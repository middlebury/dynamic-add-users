<?php

namespace DynamicAddUsers\Directory;

require_once( dirname(__FILE__) . '/DirectoryInterface.php' );
require_once( dirname(__FILE__) . '/DirectoryBase.php' );

use DynamicAddUsers\Directory\DirectoryInterface;
use DynamicAddUsers\Directory\DirectoryBase;
use Exception;

/**
 *
 */
class NullDirectory extends DirectoryBase implements DirectoryInterface
{

  /**
   * Answer an identifier for this implementation.
   *
   * @return string
   *   The implementation id.
   */
  public static function id() {
    return 'null_directory';
  }

  /**
   * Answer a label for this implementation.
   *
   * @return string
   *   The implementation label.
   */
  public static function label() {
    return 'Null Directory';
  }

  /** API Methods **/

  /**
   * Fetch an array user logins and display names for a given search string.
   * Ex: array('1' => 'John Doe', '2' => 'Jane Doe');
   *
   * @param string $search
   * @return array
   */
  protected function getUsersBySearchFromDirectory ($search) {
    return [];
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
   */
  public function getGroupsBySearchFromDirectory ($search) {
    return [];
  }

  /**
   * Fetch an array user info for a login string.
   * Elements:
   *  user_login    The login field that will match or be inserted into the users table.
   *  user_email
   *  user_nicename
   *  nickname
   *  display_name
   *  first_name
   *  last_name
   *
   * Re-write this method to use your own searching logic if you do not wish
   * to make use of the same web-service.
   *
   * @param string $login
   * @return array
   */
  public function getUserInfo ($login) {
    return [];
  }

  /**
   * Fetch an array of group ids a user is a member of.
   *
   * Throws an exception if the user isn't found in the underlying data-source.
   *
   * @param string $login
   * @return array
   */
  public function getGroupsForUser ($login) {
    return [];
  }

  /**
   * Fetch a two-dimensional array user info for every member of a group.
   * Ex:
   *  array(
   *    array(
   *      'user_login' => '1',
   *      'user_email' => 'john.doe@example.com',
   *      ...
   *    ),
   *    array(
   *      'user_login' => '2',
   *      'user_email' => 'jane.doe@example.com',
   *      ...
   *    ),
   *    ...
   *  );
   *
   *
   * Elements:
   *  user_login    The login field that will match or be inserted into the users table.
   *  user_email
   *  user_nicename
   *  nickname
   *  display_name
   *  first_name
   *  last_name
   *
   * @param string $groupId
   * @return array or NULL if group id not found
   */
  public function getGroupMemberInfo ($groupId) {
    return NULL;
  }

  /**
   * Answer an array of settings used by this directory.
   *
   * This is a nested array that describes the form elements/settings used by
   * this implementation. Options is only needed for select/radio/checkboxes
   * type fields.
   *
   * Format:
   *    [
   *      'setting-key' => [
   *        'label' => 'setting label',
   *        'description' => 'description of the setting.',
   *        'value' => 'current value',
   *        'type' => 'select',
   *        'options' => [
   *          'value' => 'label',
   *          'value2' => 'label2',
   *        ],
   *      ],
   *      'setting-key-2' => [
   *        'label' => 'setting label2',
   *        'description' => 'description of the setting.',
   *        'value' => 'current value',
   *        'type' => 'text',
   *      ],
   *    ]
   *
   * @return array
   */
  public function getSettings() {
    return [];
  }

}
