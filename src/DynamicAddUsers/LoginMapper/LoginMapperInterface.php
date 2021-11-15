<?php

namespace DynamicAddUsers\LoginMapper;

/**
 * Map user logins to a user id that can be referenced in the Directory system.
 *
 * Implementations of the LoginMapper *should* take action on user login and
 * call
 *    dynaddusers_on_login(WP_User $user, $external_user_id = NULL)
 * after extracting the external user identifier from the login attributes.
 * dynaddusers_on_login() should be called on every login, even if no external
 * user identifier is present in the login attributes.
 */
interface LoginMapperInterface
{

  /**
   * Set up any login actions and needed configuration.
   */
  public function setup ();

}
