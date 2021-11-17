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
class WpSamlAuthLoginMapper extends LoginMapperBase implements LoginMapperInterface
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
    if (!empty($userIdAttribute)) {
      throw new Exception('DynamicAddUsers WP Saml Auth user ID attribute must be defined.');
    }
    $external_user_id = NULL;
    if (!empty($attributes[$userIdAttribute][0])) {
      $external_user_id = $attributes[$userIdAttribute][0];
    }
    else {
      trigger_error('DynamicAddUsers: Tried to user groups for  ' . $user->id . ' / '. print_r($attributes, true) . ' but they do not have a ' . $userIdAttribute . ' attribute set.', E_USER_WARNING);
    }
    $this->dynamicAddUsersPlugin->onLogin($user, $external_user_id);
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

}
