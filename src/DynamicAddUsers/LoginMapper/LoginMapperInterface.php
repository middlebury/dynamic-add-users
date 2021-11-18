<?php

namespace DynamicAddUsers\LoginMapper;

require_once( dirname(__FILE__) . '/../DynamicAddUsersPluginInterface.php' );
require_once( dirname(__FILE__) . '/../ConfigurableInterface.php' );

use DynamicAddUsers\DynamicAddUsersPluginInterface;
use DynamicAddUsers\ConfigurableInterface;

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
interface LoginMapperInterface extends ConfigurableInterface
{

  /**
   * Set up any login actions and needed configuration.
   *
   * @param \DynamicAddUsers\DynamicAddUsersPluginInterface $dynamicAddUsersPlugin
   *   The plugin instance which will this implementation will call
   *   onLogin(WP_User $user, $external_user_id = NULL) on user login.
   */
  public function setup (DynamicAddUsersPluginInterface $dynamicAddUsersPlugin);

}
