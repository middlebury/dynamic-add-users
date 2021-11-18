<?php

namespace DynamicAddUsers\LoginMapper;

require_once( dirname(__FILE__) . '/LoginMapperInterface.php' );
require_once( dirname(__FILE__) . '/LoginMapperBase.php' );
require_once( dirname(__FILE__) . '/../DynamicAddUsersPluginInterface.php' );

use Exception;
use WP_User;
use DynamicAddUsers\DynamicAddUsersPluginInterface;

/**
 * Map user logins to a user id that can be referenced in the Directory system.
 *
 * Implementations of the LoginMapper *should* take action on user login and
 * call
 *    DynamicAddUsersPluginInterface::onLogin(WP_User $user, $external_user_id = NULL)
 * after extracting the external user identifier from the login attributes.
 * DynamicAddUsersPluginInterface::onLogin() should be called on every login,
 * even if no external user identifier is present in the login attributes.
 */
class UserLoginLoginMapper extends LoginMapperBase implements LoginMapperInterface
{

  /**
   * Answer an identifier for this implementation.
   *
   * @return string
   *   The implementation id.
   */
  public static function id() {
    return 'user_login';
  }

  /**
   * Answer a label for this implementation.
   *
   * @return string
   *   The implementation label.
   */
  public static function label() {
    return 'User Login';
  }

  /**
   * @var \DynamicAddUsers\DynamicAddUsersPluginInterface $dynamicAddUsersPlugin
   *   The plugin instance which will this implementation will call
   *   onLogin(WP_User $user, $external_user_id = NULL) on user login.
   */
  protected $dynamicAddUsersPlugin;

  /**
   * Set up any login actions and needed configuration.
   *
   * @param \DynamicAddUsers\DynamicAddUsersPluginInterface $dynamicAddUsersPlugin
   *   The plugin instance which will this implementation will call
   *   onLogin(WP_User $user, $external_user_id = NULL) on user login.
   */
  public function setup (DynamicAddUsersPluginInterface $dynamicAddUsersPlugin) {
    $this->dynamicAddUsersPlugin = $dynamicAddUsersPlugin;

    add_action('wp_login', [$this, 'onLogin'], 10, 2);
  }

  /**
   * Callback action to take on login via the WpSaml module.
   *
   * @param string $userLogin
   *   The login/username of the user.
   * @param WP_User $user
   *   The user that logged in.
   */
  public function onLogin($userLogin, WP_User $user) {
    if (empty($userLogin)) {
      trigger_error('DynamicAddUsers: Tried to user groups for  ' . $user->id . ' / '. $userLogin . ' but their username/login was not set.', E_USER_WARNING);
    }
    $this->dynamicAddUsersPlugin->onLogin($user, $userLogin);
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
    return [
      'user_id' => [
        'label' => 'WordPress User ID',
        'description' => 'A WordPress user ID to test the attributes of. If empty, defaults to the current user.',
        'value' => '',
        'type' => 'text',
      ],
    ];
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
    $messages = [];

    if (empty($arguments['user_id'])) {
      $userId = get_current_user_id();
      $userLabel = 'the current user';
      $user = get_user_by('id', $userId);
    }
    else {
      $userId = $arguments['user_id'];
      $user = get_user_by('id', $userId);
      if (!$user) {
        $messages[] = [
          'success' => FALSE,
          'message' => 'No user found with ID ' . esc_attr($userId) . '.',
        ];
        return $messages;
      }
      $userLabel = 'user ' . $userId . ' "' . $user->display_name . '"';
    }

    $external_user_id = $user->user_login;
    if ($external_user_id) {
      $messages[] = [
        'success' => TRUE,
        'message' => 'Found a valid value of "' . $external_user_id . '" for the user_login field on ' . $userLabel . '.',
      ];

      // Try user info and group lookup for this external id.
      try {
        $info = $this->dynamicAddUsersPlugin->getDirectory()->getUserInfo($external_user_id);
        $messages[] = [
          'success' => TRUE,
          'message' => 'Found user info for "' . esc_html($external_user_id) . '": <pre>' . esc_html(print_r($info, true)) . '</pre>',
        ];
      }
      catch (Exception $e) {
        $messages[] = [
          'success' => FALSE,
          'message' => 'Failed to lookup user info for "' . esc_html($external_user_id) . '" Code: ' . $e->getCode() . ' Message: ' . esc_html($message),
        ];
      }
      try {
        $groups = $this->dynamicAddUsersPlugin->getDirectory()->getGroupsForUser($external_user_id);
        $messages[] = [
          'success' => TRUE,
          'message' => 'Found groups for "' . esc_html($external_user_id) . '": <pre>' . esc_html(print_r($groups, true)) . '</pre>',
        ];
      }
      catch (Exception $e) {
        $messages[] = [
          'success' => FALSE,
          'message' => 'Failed to lookup user groups for "' . esc_html($external_user_id) . '" Code: ' . $e->getCode() . ' Message: ' . esc_html($message),
        ];
      }
    }
    else {
      $messages[] = [
        'success' => FALSE,
        'message' => 'No valid value found for the user_login field on ' . $userLabel . '.',
      ];
    }

    return $messages;
  }

}
