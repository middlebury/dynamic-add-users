<?php

namespace DynamicAddUsers\LoginHook;

use Exception;
use WP_User;
use DynamicAddUsers\DynamicAddUsersPluginInterface;

/**
 * Map user logins to a user id that can be referenced in the Directory system.
 *
 * Implementations of the LoginHook *should* take action on user login and
 * call
 *    DynamicAddUsersPluginInterface::onLogin(WP_User $user, $external_user_id = NULL)
 * after extracting the external user identifier from the login attributes.
 * DynamicAddUsersPluginInterface::onLogin() should be called on every login,
 * even if no external user identifier is present in the login attributes.
 */
class WpSamlAuthLoginHook extends LoginHookBase implements LoginHookInterface
{

  /**
   * Answer an identifier for this implementation.
   *
   * @return string
   *   The implementation id.
   */
  public static function id() {
    return 'wp_saml_auth';
  }

  /**
   * Answer a label for this implementation.
   *
   * @return string
   *   The implementation label.
   */
  public static function label() {
    return 'WP Saml Auth';
  }

  /**
   * Answer a description for this implementation.
   *
   * @return string
   *   The description text.
   */
  public static function description() {
    return 'This implementation hooks into the <a href="https://wordpress.org/plugins/wp-saml-auth/">WP SAML Auth</a> plugins\'s <code>wp_saml_auth_new_user_authenticated</code> and <code>wp_saml_auth_existing_user_authenticated</code> actions to trigger user/group updates and will refer to a configurable attribute in the SAML response.';
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

    add_action('wp_saml_auth_new_user_authenticated', [$this, 'onLogin'], 10, 2);
    add_action('wp_saml_auth_existing_user_authenticated', [$this, 'onLogin'], 10, 2);
  }

  /**
   * Callback action to take on login via the WpSaml module.
   *
   * @param WP_User $user
   *   The user that logged in.
   * @param array $attributes
   *   User attributes from the SAML response.
   */
  public function onLogin(WP_User $user, array $attributes) {
    $userIdAttribute = $this->getSetting('dynamic_add_users__wp_saml_auth__user_id_attribute');
    if (empty($userIdAttribute)) {
        trigger_error('DynamicAddUsers: Configuration error. WP Saml Auth user ID attribute must be defined. ', E_USER_WARNING);
    }

    // Store user attributes for debugging if configured.
    if ($this->getSetting('dynamic_add_users__wp_saml_auth__record_login_attributes') == '1') {
      update_user_meta($user->id, 'dynamic_add_users__wp_saml_auth__login_attributes', $attributes);
    }

    $external_user_id = $this->getExternalUserIdFromAttributes($userIdAttribute, $attributes);
    if (empty($external_user_id)) {
      trigger_error('DynamicAddUsers: Tried to user groups for  ' . $user->id . ' / '. print_r($attributes, true) . ' but they do not have a ' . $userIdAttribute . ' attribute set.', E_USER_WARNING);
    }
    $this->dynamicAddUsersPlugin->onLogin($user, $external_user_id);
  }

  /**
   * Extract the External User id value from an attribute set.
   *
   * @param string $userIdAttribute
   *   The attribute key in the login response.
   * @param array $attributes
   *   The login attribute set to extract from.
   *
   * @return mixed
   *   The external user identifier string or NULL if not found.
   */
  protected function getExternalUserIdFromAttributes($userIdAttribute, array $attributes) {
    if (!empty($userIdAttribute) && !empty($attributes[$userIdAttribute][0])) {
      return $attributes[$userIdAttribute][0];
    }
    else {
      return NULL;

    }
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
    // Clear user-meta of login attributes if we are disabling recording.
    if ($settingKey == 'dynamic_add_users__wp_saml_auth__record_login_attributes' && !intval($value)) {
      delete_metadata('user', NULL, 'dynamic_add_users__wp_saml_auth__login_attributes', '', true);
    }

    parent::updateSetting($settingKey, $value);
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
    return [
      'dynamic_add_users__wp_saml_auth__user_id_attribute' => [
        'label' => 'User Id Attribute',
        'description' => 'The attribute to use as the external user id in the SAML response. This should map to the user-ids known to the Directory implementation. Example: http://middlebury.edu/MiddleburyCollegeUID',
        'value' => $this->getSetting('dynamic_add_users__wp_saml_auth__user_id_attribute'),
        'type' => 'text',
      ],
      'dynamic_add_users__wp_saml_auth__record_login_attributes' => [
        'label' => 'Record Login Attributes',
        'description' => 'If true, login attributes will be stored in user-meta under the <code>dynamic_add_users__wp_saml_auth__login_attributes</code> key each time a user authenticates. When set to false, these attributes will be cleared for all users.',
        'value' => $this->getSetting('dynamic_add_users__wp_saml_auth__record_login_attributes'),
        'type' => 'select',
        'options' => [
          '0' => 'No',
          '1' => 'Yes',
        ],
      ],
    ];
  }

  /**
   * Validate the settings and return an array of error messages.
   *
   * @return array
   *   Any error messages for settings. If empty, settings are validated.
   */
  public function checkSettings() {
    $messages = [];
    if (empty($this->getSetting('dynamic_add_users__wp_saml_auth__user_id_attribute'))) {
      $messages[] = 'The User ID Attribute must be specified.';
    }
    return $messages;
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

    // test the setting existance.
    $attributeId = $this->getSetting('dynamic_add_users__wp_saml_auth__user_id_attribute');
    if (empty($attributeId)) {
      $messages[] = [
        'success' => FALSE,
        'message' => 'The User ID Attribute must be specified in the configuration.',
      ];
    }
    else {
      $messages[] = [
        'success' => TRUE,
        'message' => 'A User ID Attribute is specified in the configuration: ' . esc_attr($attributeId),
      ];
    }

    if ($this->getSetting('dynamic_add_users__wp_saml_auth__record_login_attributes') != '1') {
      $messages[] = [
        'success' => FALSE,
        'message' => 'The WpSamlAuthLoginHook is not currently configured to store login attributes. Without recording attributes we can not do further tests.',
      ];
      return $messages;
    }
    else {
      $messages[] = [
        'success' => TRUE,
        'message' => 'The WpSamlAuthLoginHook is currently configured to store login attributes.',
      ];
    }

    if (empty($arguments['user_id'])) {
      $userId = get_current_user_id();
      $userLabel = 'the current user';
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

    // Verify that we have login attributes to compare.
    $loginAttributes = get_user_meta($userId, 'dynamic_add_users__wp_saml_auth__login_attributes', TRUE);
    if ($loginAttributes === FALSE || empty($loginAttributes)) {
      $messages[] = [
        'success' => FALSE,
        'message' => 'No login attributes are available for ' . $userLabel . ' at this time. Log out and back in using the WP Saml Auth module to refresh login attributes.',
      ];
      return $messages;
    }
    elseif (!is_array($loginAttributes)) {
      $messages[] = [
        'success' => FALSE,
        'message' => 'Login attributes are available for ' . $userLabel . ' are expected to be an array, but found "' . esc_html(var_export($loginAttributes, true)) . '". Log out and back in using the WP Saml Auth module to refresh login attributes.',
      ];
      return $messages;
    }
    $messages[] = [
      'success' => TRUE,
      'message' => 'Found login attributes for ' . $userLabel . ': <pre>' . esc_html(var_export($loginAttributes, true)) . '</pre>',
    ];

    // Verify that our configured attribute exists in the login attributes.
    if (isset($loginAttributes[$attributeId])) {
      $messages[] = [
        'success' => TRUE,
        'message' => 'Found a "' . $attributeId . '" attribute in the login attributes for ' . $userLabel . '.',
      ];
      $external_user_id = $this->getExternalUserIdFromAttributes($attributeId, $loginAttributes);
      if ($external_user_id) {
        $messages[] = [
          'success' => TRUE,
          'message' => 'Found a valid value of "' . $external_user_id . '" for the "' . $attributeId . '" attribute in the login attributes for ' . $userLabel . '.',
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
          'message' => 'No valid value found for the "' . $attributeId . '" attribute in the login attributes for ' . $userLabel . '.',
        ];
      }
    }
    else {
      $messages[] = [
        'success' => FALSE,
        'message' => "The login attributes for ' . $userLabel . ' don't have a '" . $attributeId . "' key.",
      ];
    }

    return $messages;
  }

}
