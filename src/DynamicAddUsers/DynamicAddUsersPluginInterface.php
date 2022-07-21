<?php

namespace DynamicAddUsers;

use WP_User;

/**
 * Methods other plugins may rely on.
 */
interface DynamicAddUsersPluginInterface
{

  /**
   * Answer the currently configured DirectoryInterface implementation.
   *
   * @return \DynamicAddUsers\Directory\DirectoryInterface
   */
  public function getDirectory();

  /**
   * Answer the currently configured UserManagerInterface implementation.
   *
   * @return \DynamicAddUsers\UserManagerInterface
   */
  public function getUserManager();

  /**
   * Answer the currently configured GroupSyncerInterface implementation.
   *
   * @return \DynamicAddUsers\GroupSyncerInterface
   */
  public function getGroupSyncer();

  /**
   * Answer the currently configured LoginHook implementation.
   *
   * @return \DynamicAddUsers\LoginHook\LoginHookInterface
   */
  public function getLoginHook();

  /*******************************************************
   * Login flow -- Internal methods of the plugin.
   *******************************************************/

  /**
   * Action to take on user login.
   *
   * LoginHookInterface implementations *should* call this function after
   * attempting to map a login response to an external user identifier.
   *
   * Flow of actions:
   *   1. A LoginHookInterface implementation hooks into the authentication
   *      plugin's post-authentication action and maps the user attributes to an
   *      external user-id that is valid in the DirectoryInterface implementation.
   *   2. The LoginHookInterface implementation calls onLogin().
   *   3. onLogin() looks up a user's groups in the
   *      DirectoryInterface implementation.
   *   4. onLogin() passes the user and their groups to the
   *      GroupSyncerInterface implementation to set appropriate roles in target
   *      sites.
   *   5. onLogin() finishes and triggers the 'dynamic_add_users__update_user_on_login'
   *      action to allow other modules to take actions on login.
   *
   *
   * @param WP_User $user
   *   The user who has authenticated.
   * @param optional string $external_user_id
   *   If the login attributes map to an external user identifier that can be
   *   looked up in the directory service, that ID should be passed here.
   */
  public function onLogin(WP_User $user, $external_user_id = NULL);

  /*******************************************************
   * Configuration -- Internal methods of the plugin.
   *******************************************************/

  /**
   * Answer an array of directory implementations that can be configured.
   *
   * Format:
   *   [id => label]
   *
   * @return array
   */
  public function getDirectoryImplementations();

  /**
   * Set the directory implementation to use.
   *
   * @param string $id
   *   The identifier of the implementation that should be used.
   */
  public function setDirectoryImplementation($id);

  /**
   * Answer an array of LoginHook implementations that can be configured.
   *
   * Format:
   *   [id => label]
   *
   * @return array
   */
  public function getLoginHookImplementations();

  /**
   * Set the LoginHook implementation to use.
   *
   * @param string $id
   *   The identifier of the implementation that should be used.
   */
  public function setLoginHookImplementation($id);

}
