<?php

namespace DynamicAddUsers;

/**
 * User Managers provide the ability to get or create users and assign roles.
 */
interface UserManagerInterface
{

  /**
   * Answer the user id for the given info, creating the user record if needed.
   *
   * @param array $userInfo
   * @return object The user object.
   */
  public function getOrCreateUser (array $userInfo);

  /**
   * Add a user to a blog.
   *
   * @param object $user
   * @param string $role
   * @param optional int $dest_blog_id
   * @param option string $sync_group If passed, mark this role assignment as synchronized with this group.
   * @return NULL
   */
  public function addUserToBlog ($user, $role, $blog_id = null, $sync_group = null);

  /**
   * Remove a user from a blog.
   *
   * @param int $user_id
   * @param string $group_id
   * @param int $blog_id
   * @return NULL
   */
  public function removeUserFromBlog ($user_id, $group_id, $blog_id);

  /**
   * Get an existing role for the user.
   *
   * @param int $user_id
   * @param optional int $blog_id
   *    If NULL or not passed, the current blog will be queried.
   * @return mixed string role or NULL if none.
   */
  public function getUsersCurrentRoleInBlog($user_id, $blog_id = NULL);

}
