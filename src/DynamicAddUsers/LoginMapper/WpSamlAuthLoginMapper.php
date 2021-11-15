<?php

namespace DynamicAddUsers\LoginMapper;

require_once( dirname(__FILE__) . '/LoginMapperInterface.php' );
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
class WpSamlAuthLoginMapper implements LoginMapperInterface
{

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

    if (!defined('DYNADDUSERS_WP_SAML_AUTH_USER_ID_ATTRIBUTE')) {
      throw new Exception('DYNADDUSERS_WP_SAML_AUTH_USER_ID_ATTRIBUTE must be defined.');
    }
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
    if (!defined('DYNADDUSERS_WP_SAML_AUTH_USER_ID_ATTRIBUTE')) {
      throw new Exception('DYNADDUSERS_WP_SAML_AUTH_USER_ID_ATTRIBUTE must be defined.');
    }
    $external_user_id = NULL;
    if (!empty($attributes[DYNADDUSERS_WP_SAML_AUTH_USER_ID_ATTRIBUTE][0])) {
      $external_user_id = $attributes[DYNADDUSERS_WP_SAML_AUTH_USER_ID_ATTRIBUTE][0];
    }
    else {
      trigger_error('DynamicAddUsers: Tried to user groups for  ' . $user->id . ' / '. print_r($attributes, true) . ' but they do not have a ' . DYNADDUSERS_WP_SAML_AUTH_USER_ID_ATTRIBUTE . ' attribute set.', E_USER_WARNING);
    }
    $this->dynamicAddUsersPlugin->onLogin($user, $external_user_id);
  }


}
