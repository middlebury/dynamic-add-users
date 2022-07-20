<?php

namespace DynamicAddUsers;

/**
 * Group Syncers assign roles in WordPress sites based on group membership.
 */
interface GroupSyncerInterface
{

  /**
   * Keep a group in sync for the current blog.
   *
   * @param string $group_id
   * @param string $role
   * @param string $groups_label
   * @return NULL
   */
  public function keepGroupInSync ($group_id, $role, $group_label = NULL);

  /**
   * Answer an array of group ids that are kept in sync for the current blog.
   *
   * @return array
   */
  public function getSyncedGroups();

  /**
   * Stop syncing a group for the current blog.
   *
   * @param string $group_id
   * @return NULL
   */
  public function stopSyncingGroup ($group_id);

  /**
   * Synchronize a user given their new list of groups, setting roles in sites.
   *
   * @param object $user
   * @param array $group_ids
   * @return NULL
   */
  public function syncUser ($user_id, array $group_ids);

  /**
   * Synchronize all groups.
   *
   * @return NULL
   */
  public function syncAllGroups ();

  /**
   * Sync a list of groups.
   *
   * @param array $groups
   * @return NULL
   */
  public function syncGroups (array $groups);

  /**
   * Synchronize a group, setting roles for each user in that group.
   *
   * @param int $blog_id
   * @param string $groups_id
   * @param string $role
   * @param string $group_label
   * @return NULL
   */
  public function syncGroup ($blog_id, $group_id, $role, $group_label = NULL);

  /**
   * Remove all of the users in a group from the current blog.
   *
   * @param string $group_id
   * @return NULL
   */
  public function removeUsersInGroup ($group_id);

}
