<?php

namespace DynamicAddUsers;

use DynamicAddUsers\Directory\DirectoryInterface;
use Exception;
use WP_User;

/**
 * Class to map group memberships to blog roles and keep them in sync.
 */
class GroupSyncer implements GroupSyncerInterface
{

  /**
   * @var DynamicAddUsers\Directory\DirectoryInterface $directory
   *   The directory service to use for user-information lookup.
   */
  protected $directory;

  /**
   * @var DynamicAddUsers\UserManagerInterface $userManager
   *   The user-manager service to use for manipulating users.
   */
  protected $userManager;

  /**
   * Create a new UserManager.
   *
   * @param DynamicAddUsers\Directory\DirectoryInterface $directory
   *   The directory service to use for user-information lookup.
   * @param DynamicAddUsers\UserManagerInterface $userManager
   *   The user-manager service to use for manipulating users.
   */
  public function __construct(DirectoryInterface $directory, UserManagerInterface $userManager) {
    $this->directory = $directory;
    $this->userManager = $userManager;
  }

  /**
   * Keep a group in sync for the current blog.
   *
   * @param string $group_id
   * @param string $role
   * @return NULL
   */
  public function keepGroupInSync ($group_id, $role, $group_label = NULL) {
    $synced = $this->getSyncedGroups();
    foreach ($synced as $group) {
      if ($group->group_id == $group_id && $group->role == $role)
        return false;
    }
    global $wpdb;
    $groups = $wpdb->base_prefix . "dynaddusers_groups";
    $wpdb->delete($groups, array(
      'blog_id' => get_current_blog_id(),
      'group_id' => $group_id,
    ));
    $wpdb->insert($groups, array(
      'blog_id' => get_current_blog_id(),
      'group_id' => $group_id,
      'group_label' => $group_label,
      'role' => $role,
    ));
  }

  /**
   * Answer an array of group ids that are kept in sync for the current blog.
   *
   * @return array
   */
  public function getSyncedGroups() {
    global $wpdb;
    $groups = $wpdb->base_prefix . "dynaddusers_groups";
    return $wpdb->get_results($wpdb->prepare(
      "SELECT
        *
      FROM
        $groups
      WHERE
        blog_id = %d
      ORDER BY
        group_id
      ",
      get_current_blog_id()
    ));
  }

  /**
   * Stop syncing a group for the current blog.
   *
   * @param string $group_id
   * @return NULL
   */
  public function stopSyncingGroup ($group_id) {
    global $wpdb;
    $groups = $wpdb->base_prefix . "dynaddusers_groups";
    $wpdb->query($wpdb->prepare(
      "DELETE FROM
        $groups
      WHERE
        blog_id = %d
        AND group_id = %s
      ",
      get_current_blog_id(),
      $group_id
    ));
    $synced = $wpdb->base_prefix . "dynaddusers_synced";
    $wpdb->query($wpdb->prepare(
      "DELETE FROM
        $synced
      WHERE
        blog_id = %d
        AND group_id = %s
      ",
      get_current_blog_id(),
      $group_id
    ));
  }

  /**
   * Synchronize a user given their new list of groups, setting roles in sites.
   *
   * @param object $user
   * @param array $groups
   * @return NULL
   */
  public function syncUser ($user_id, array $groups) {
    global $wpdb;

    $group_table = $wpdb->base_prefix . "dynaddusers_groups";
    $synced_table = $wpdb->base_prefix . "dynaddusers_synced";

    $role_levels = array(
      'subscriber' => 1,
      'contributor' => 2,
      'author' => 3,
      'editor' => 4,
      'administrator' => 5,
    );

    $user = new WP_User( $user_id );

    // Get a list of all existing roles handled by the DAU
    $query = "SELECT
      g.blog_id, g.group_id, g.role
    FROM
      $group_table g
    WHERE
      ";
    $args = array();
    if (count($groups)) {
      $placeholders = array_fill(0, count($groups), '%s');
      $query .= "\n\tg.group_id IN (".implode(', ', $placeholders).")";
      $args = array_merge($args, $groups);
    }
    $roles_to_ensure = $wpdb->get_results($wpdb->prepare($query, $args));
    foreach ($roles_to_ensure as $role_to_ensure) {
      $existing_role = $this->userManager->getUsersCurrentRoleInBlog($user_id, $role_to_ensure->blog_id);
      // If the user has no role or a lesser role.
      // Otherwise, we'll assume that it was customized by the blog admin and ignore it.
      if (empty($existing_role)) {
        $this->userManager->addUserToBlog($user, $role_to_ensure->role, $role_to_ensure->blog_id, $role_to_ensure->group_id);
      }
      // If the user has a lesser role than their group would provide, upgrade them
      else if ($role_levels[$existing_role] < $role_levels[$role_to_ensure->role]) {
        $this->userManager->addUserToBlog($user, $role_to_ensure->role, $role_to_ensure->blog_id, $role_to_ensure->group_id);
      }
    }
    // Switch back to the current blog if we left it.
    restore_current_blog();

    // Get a list of the blogs where the user was previously added, but is no longer
    // in one of the groups
    $query = "SELECT
      g.blog_id, g.group_id, g.role
    FROM
      $group_table g
      INNER JOIN $synced_table s ON (g.blog_id = s.blog_id AND g.group_id = s.group_id)
    WHERE
      s.user_id = %s
      ";
    $args = array($user_id);
    if (count($groups)) {
      $placeholders = array_fill(0, count($groups), '%s');
      $query .= "\n\t AND s.group_id NOT IN (".implode(', ', $placeholders).")";
      $args = array_merge($args, $groups);
    }
    $roles_gone = $wpdb->get_results($wpdb->prepare($query, $args));
    foreach ($roles_gone as $role_gone) {
      // If the group-role assignment is gone...
      // ...AND the user currently has that role...
      // ...AND the user doesn't have that role through another group
      // take away that role.
      // If they have a lesser role through another group apply that,
      // otherwise, remove them from the blog.
      $existing_role = $this->userManager->getUsersCurrentRoleInBlog($user_id, $role_gone->blog_id);
      // Only change or remove the role if the role was the same as the one
      // set by this plugin before. Otherwise, we'll assume that it was customized
      // by the blog admin and ignore it.
      if (empty($existing_role) || $existing_role == $role_gone->role) {
        // Verify that the group still exists and don't remove the user if it is gone.
        // This is particularly a problem for class groups which disapear after several years.
        try {
          $memberInfo = $this->directory->getGroupMemberInfo($role_gone->group_id);
          $this->userManager->removeUserFromBlog($user_id, $role_gone->group_id, $role_gone->blog_id);
        } catch (Exception $e) {
          // Skip removal for missing groups
        }

      }
    }

    // Switch back to the current blog if we left it.
    restore_current_blog();
  }

  /**
   * Synchronize all groups.
   *
   * @return NULL
   */
  public function syncAllGroups () {
    global $wpdb;
    $groups_table = $wpdb->base_prefix . "dynaddusers_groups";
    $groups_to_sync = $wpdb->get_results($wpdb->prepare(
      "SELECT
        *
      FROM
        $groups_table
      ORDER BY
        blog_id
      "
    ));
    $this->syncGroups($groups_to_sync);
  }

  /**
   * Sync a list of groups.
   *
   * @param array $groups
   * @return NULL
   */
  public function syncGroups (array $groups) {
    foreach ($groups as $group) {
      try {
        $this->syncGroup($group->blog_id, $group->group_id, $group->role, $group->group_label);
      } catch (Exception $e) {
        user_error($e->getMessage(), E_USER_WARNING);
      }
    }
  }

  /**
   * Synchronize a group, setting roles for each user in that group.
   *
   * @param int $blog_id
   * @param string $groups_id
   * @param string $role
   * @param string $group_label
   * @return NULL
   */
  public function syncGroup ($blog_id, $group_id, $role, $group_label = NULL) {
    global $wpdb;
    $role_levels = array(
      'subscriber' => 1,
      'contributor' => 2,
      'author' => 3,
      'editor' => 4,
      'administrator' => 5,
    );
    $changes = array();
    $memberInfo = $this->directory->getGroupMemberInfo($group_id);
    if (!is_array($memberInfo)) {
      throw new Exception("Could not find members for group '".$group_label."' with id '".$group_id."'.");
    } else {
      $user_ids = array();
      foreach ($memberInfo as $info) {
        try {
          $user = $this->userManager->getOrCreateUser($info);
          $user_ids[] = $user->ID;
          $existing_role = $this->userManager->getUsersCurrentRoleInBlog($user->ID, $blog_id);
          if (!is_user_member_of_blog($user->ID, $blog_id) || $role_levels[$role] > $role_levels[$existing_role]) {
            $this->userManager->addUserToBlog($user, $role, $blog_id, $group_id);
            $changes[] = 'Added '.$user->display_name.' as '.$this->article($role).' '.$role.'.';
          }
        } catch (Exception $e) {
          user_error($e->getMessage(), E_USER_WARNING);
        }
      }

      // Remove users who have left the group.
      $table = $wpdb->base_prefix . "dynaddusers_synced";
      $query = "SELECT user_id
          FROM $table
          WHERE
            blog_id = %d
            AND group_id = %s";
      $args = array($blog_id, $group_id);
      if (count($user_ids)) {
        $placeholders = array_fill(0, count($user_ids), '%d');
        $query .= "\n\t AND user_id NOT IN (".implode(', ', $placeholders).")";
        $args = array_merge($args, $user_ids);
      }
      $missing_users = $wpdb->get_col($wpdb->prepare($query, $args));
      foreach ($missing_users as $user_id) {
        $existing_role = $this->userManager->getUsersCurrentRoleInBlog($user_id, $blog_id);
        $user = new WP_User($user_id);
        if (is_user_member_of_blog($user_id, $blog_id) && $role_levels[$role] == $role_levels[$existing_role]) {
          remove_user_from_blog($user_id, $blog_id);
          $changes[] = 'Removed '.$user->display_name.'.';
        }
      }

      // Update our list of synced users.
      $query = "DELETE FROM $table
          WHERE
            blog_id = %d
            AND group_id = %s";
      $wpdb->query($wpdb->prepare($query, $args));
      foreach ($user_ids as $user_id) {
        $wpdb->insert($table, array('blog_id' => $blog_id, 'group_id' => $group_id, 'user_id' => $user_id));
      }

      // Record our sync time.
      $groups_table = $wpdb->base_prefix . "dynaddusers_groups";
      $wpdb->query($wpdb->prepare(
        "UPDATE
          $groups_table
        SET
          last_sync = NOW()
        WHERE
          blog_id = %d
          AND group_id = %s
        ",
        $blog_id,
        $group_id
      ));
    }
    return $changes;
  }

  /**
   * Remove all of the users in a group from the current blog.
   *
   * @param string $group_id
   * @return NULL
   */
  public function removeUsersInGroup ($group_id) {
    $blog_id = get_current_blog_id();
    $members = $this->getSyncedUsersForGroup($blog_id, $group_id);
    if (!is_array($members)) {
      print "Couldn't fetch group info.";
      return;
    }
    foreach ($members as $user_id) {
      try {
        $user = get_userdata($user_id);
        if (is_user_member_of_blog($user_id, $blog_id) && $user_id != get_current_user_id()) {
          remove_user_from_blog($user_id, $blog_id);
          print "Removed ".$user->display_name."<br/>\n";
        }
      } catch (Exception $e) {
        print "Error: ".htmlentities($e->getMessage());
      }
    }
  }

  /**
   * Answer an array of user ids synced to a blog for a group.
   *
   * @param int $blog_id
   * @param string $group_id
   * @return array
   */
  protected function getSyncedUsersForGroup($blog_id, $group_id) {
    // Get a list of users currently synced by the DAU
    global $wpdb;
    $table = $wpdb->base_prefix . "dynaddusers_synced";
    $query = "SELECT user_id
        FROM $table
        WHERE
          blog_id = %d
          AND group_id = %s";
    $args = array($blog_id, $group_id);
    return $wpdb->get_col($wpdb->prepare($query, $args));
  }

  /**
   * Answer the article 'a' or 'an' for a word.
   *
   * @param string $word
   * @return string 'a' or 'an'
   */
  protected function article ($word) {
    if (preg_match('/^[aeiou]/', $word))
      return 'an';
    else
      return 'a';
  }

}
