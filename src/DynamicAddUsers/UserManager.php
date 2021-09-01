<?php

namespace DynamicAddUsers;

require_once( dirname(__FILE__) . '/Directory/DirectoryInterface.php' );
require_once( dirname(__FILE__) . '/UserManagerInterface.php' );

use DynamicAddUsers\Directory\DirectoryInterface;
use Exception;
use WP_User;

/**
 * Class to create users and manage their roles.
 */
class UserManager implements UserManagerInterface
{

  /**
   * @var DynamicAddUsers\Directory\DirectoryInterface $directory
   *   The directory service to use for user-information lookup.
   */
  protected $directory;

  /**
   * Create a new UserManager.
   *
   * @param DynamicAddUsers\Directory\DirectoryInterface $directory
   *   The directory service to use for user-information lookup.
   */
  public function __construct(DirectoryInterface $directory) {
    $this->directory = $directory;
  }

  /**
   * Answer the user id for the given info, creating the user record if needed.
   *
   * @param array $userInfo
   * @return object The user object.
   */
  public function getOrCreateUser (array $userInfo) {
    $user = get_user_by('login', $userInfo['user_login']);
    if (is_object($user))
      return $user;

    // Create a new user
    return $this->createUser($userInfo);
  }

  /**
   * Create a new user-record for the given info and return the new user object.
   *
   * @param array $userInfo
   * @return object The user object for the new user.
   */
  protected function createUser(array $userInfo) {
    $required = array('user_login', 'user_email', 'user_nicename', 'nickname', 'display_name');
    foreach ($required as $field) {
      if (!isset($userInfo[$field]))
        throw new Exception("$field is missing in ".print_r($userInfo, true));
      if (!strlen(trim($userInfo[$field])))
        throw new Exception("$field is empty in ".print_r($userInfo, true));
    }

    // Create a new user with a random pass since we are using external logins.
    $userId = wpmu_create_user($userInfo['user_login'], md5(rand().serialize($userInfo)), $userInfo['user_email']);

    // If we don't have a user-id, check for old accounts with conflicting email
    // addresses.
    if (!$userId) {
      $oldUser = get_user_by('email', $userInfo['user_email']);
      if (is_object($oldUser)) {
        // Verify that the old user no longer exists in the directory.
        try {
          $oldUserInfo = $this->directory->getUserInfo($oldUser->get('user_login'));
          if (!empty($oldUserInfo)) {
            throw new Exception("Could not create a user for ".print_r($userInfo, true) . '. An existing user with that email address exists in WordPress and the directory.');
          }
        }
        catch (Exception $e) {
          if ($e->getCode() == 404) {
            // The old user's ID no longer exists in the directory. It should be safe
            // to move their email out of the way.
            if (preg_match('/^(.+)@(middlebury|miis)\.edu$/', $userInfo['user_email'], $m)) {
              // Change the old-user's email to something else.
              $replacementEmail = $m[1].'-old-replaced@'.$m[2].'.edu';
              wp_update_user(['ID' => $oldUser->id, 'user_email' => $replacementEmail]);
              trigger_error('DynamicAddUsers: Renamed email for old account ' . $oldUser->id . ' / '. $oldUser->get('user_login') . ' from ' . $userInfo['user_email'] . ' to ' . $replacementEmail . '. This address was reused in new user account ' . $userInfo['user_login'], E_USER_WARNING);

              // Try creating the new user account again.
              $userId = wpmu_create_user($userInfo['user_login'], md5(rand().serialize($userInfo)), $userInfo['user_email']);
            }
            else {
              throw new Exception("Could not create a user for ".print_r($userInfo, true) . '. An existing user with that email address exists in WordPress but the email domain is not middlebury.edu or miis.edu.');
            }
          }
          else {
            throw $e;
          }
        }
      }
    }

    // If we still don't have a userId, throw an exception.
    if (!$userId) {
      throw new Exception("Could not create a user for ".print_r($userInfo, true));
    }

    // Add the rest of the user information
    $userInfo['ID'] = $userId;
    wp_update_user($userInfo);

    $user = get_userdata($userId);
    if (!is_object($user))
      throw new Exception("Problem fetching information for $userId");
    return $user;
  }

  /**
   * Add a user to a blog.
   *
   * @param object $user
   * @param string $role
   * @param optional int $dest_blog_id
   * @param option string $sync_group If passed, mark this role assignment as synchronized with this group.
   * @return NULL
   */
  public function addUserToBlog ($user, $role, $blog_id = null, $sync_group = null) {
    if (is_null($blog_id)) {
      $blog_id = get_current_blog_id();
    }
    if (!$blog_id)
      throw new Exception('No current $blog_id available.');
    if (!strlen($role))
      throw new Exception('No $role specified.');

    $role_levels = array(
      'subscriber' => 1,
      'contributor' => 2,
      'author' => 3,
      'editor' => 4,
      'administrator' => 5,
    );

    if (is_user_member_of_blog($user->ID, $blog_id)) {
      $existing_role = $this->getUsersCurrentRoleInBlog($user->ID, $blog_id);
      if ($role_levels[$existing_role] > $role_levels[$role]) {
        throw new Exception("User ".$user->display_name." is already ".dynaddusers_article($existing_role).' '.$existing_role." of this blog, not reducing to ".dynaddusers_article($role).' '.$role.'.');
      } else if ($role_levels[$existing_role] == $role_levels[$role]) {
        throw new Exception("User ".$user->display_name." is already ".dynaddusers_article($existing_role).' '.$existing_role." of this blog.");
      }
    }

    add_user_to_blog($blog_id, $user->ID, $role);

    // Note that they are now going to be managed as part of the group.
    if ($sync_group) {
      global $wpdb;
      $sync_table = $wpdb->base_prefix . "dynaddusers_synced";
      $sync_exists = $wpdb->get_row($wpdb->prepare("SELECT * FROM $sync_table WHERE blog_id = %d AND group_id = %s AND user_id = %d", $blog_id, $sync_group, $user->ID));
      if (is_null($sync_exists)) {
        $wpdb->insert($sync_table, array('blog_id' => $blog_id, 'group_id' => $sync_group, 'user_id' => $user->ID));
      }
    }
  }

  /**
   * Remove a user from a blog.
   *
   * @param int $user_id
   * @param string $group_id
   * @param int $blog_id
   * @return NULL
   */
  public function removeUserFromBlog ($user_id, $group_id, $blog_id) {
    // remove the user from the blog if they are a member
    if (is_user_member_of_blog($user_id, $blog_id))
      remove_user_from_blog($user_id, $blog_id);

    // Update our list of synced users, cleaning up if needed.
    global $wpdb;
    $synced = $wpdb->base_prefix . "dynaddusers_synced";
    $wpdb->query($wpdb->prepare(
      "DELETE FROM
        $synced
      WHERE
        blog_id = %d
        AND group_id = %s
        AND user_id = %d
      ",
      $blog_id,
      $group_id,
      $user_id
    ));
  }

  /**
   * Get an existing role for the user.
   *
   * @param int $user_id
   * @param optional int $blog_id
   *    If NULL or not passed, the current blog will be queried.
   * @return mixed string role or NULL if none.
   */
  public function getUsersCurrentRoleInBlog($user_id, $blog_id = NULL) {
    if (!is_null($blog_id)) {
      switch_to_blog($blog_id);
    }
    $user = new WP_User( $user_id );
    $role_levels = array(
      'subscriber' => 1,
      'contributor' => 2,
      'author' => 3,
      'editor' => 4,
      'administrator' => 5,
    );
    $existing_role = NULL;
    foreach (array_keys(array_reverse($role_levels)) as $role) {
      if (isset($user->allcaps[$role]) && $user->allcaps[$role]) {
        $existing_role = $role;
        break;
      }
    }
    if (!is_null($blog_id)) {
      restore_current_blog();
    }
    return $existing_role;
  }
}
