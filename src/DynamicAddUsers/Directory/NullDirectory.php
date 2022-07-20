<?php

namespace DynamicAddUsers\Directory;

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

  /**
   * Answer a description for this implementation.
   *
   * @return string
   *   The description text.
   */
  public static function description() {
    return "This implementation does no lookups against any external data-source. It allows the DynamicAddUsers UI to still function with only lookups of local users.";
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
  protected function getGroupsBySearchFromDirectory ($search) {
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
   * Fetch an array of group ids and display names a user is a member of.
   *
   * Ex: array('100' => 'All Students', '5' => 'Faculty');
   *
   * Throws an exception if the user isn't found in the underlying data-source.
   *
   * Note: IDs and values are defined by the underlying implementation and can
   * only be assumed to be strings.
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

  /**
   * Validate the settings and return an array of error messages.
   *
   * @return array
   *   Any error messages for settings. If empty, settings are validated.
   */
  public function checkSettings() {
    return [];
  }

  /**
   * Answer an array of test argments that should be passed to our test function.
   *
   * This is a nested array that describes the form elements/arguments used by
   * this implementation. Options is only needed for select/radio/checkboxes
   * type fields.
   *
   * Format:
   *    [
   *      'argument' => [
   *        'label' => 'argument label',
   *        'description' => 'description of the argument.',
   *        'value' => 'current value',
   *        'type' => 'select',
   *        'options' => [
   *          'value' => 'label',
   *          'value2' => 'label2',
   *        ],
   *      ],
   *      'argument_2' => [
   *        'label' => 'argument label2',
   *        'description' => 'description of the argument.',
   *        'value' => 'current value',
   *        'type' => 'text',
   *      ],
   *    ]
   *
   * @return array
   */
  public function getTestArguments() {
    return [];
  }

  /**
   * If possible, test the settings against the backing system.
   *
   * @param array $arguments
   *   An array of arguments [argument => value, argument_2 => value2] as
   *   described by getTestArguments().
   *
   * @return array
   *   An array of results with information about each test performed.
   *   Each result should indicatate success or failure as well as a message.
   *      [
   *        [
   *          'success' => true,
   *          'message' => 'Host exists at the URL provided.',
   *        ],
   *        [
   *          'success' => false,
   *          'message' => 'Query for xyz failed.',
   *        ],
   *      ]
   */
  public function testSettings(array $arguments = []) {
    return [
      [
        'success' => true,
        'message' => "This Null implemenation doesn't actually do anything.",
      ]
    ];
  }

}
