<?php

namespace DynamicAddUsers\Directory;

/**
 *
 */
interface DirectoryInterface
{

  /**
   * Fetch an array user logins and display names for a given search string.
   *
   * Ex: array('1' => 'John Doe', '2' => 'Jane Doe');
   *
   * Note: IDs and values are defined by the underlying implementation and can
   * only be assumed to be strings.
   *
   * @param string $search
   * @return array
   */
  public function getUsersBySearch ($search);

  /**
   * Fetch an array group ids and display names for a given search string.
   *
   * Ex: array('100' => 'All Students', '5' => 'Faculty');
   *
   * Note: IDs and values are defined by the underlying implementation and can
   * only be assumed to be strings.
   *
   * @param string $search
   * @return array
   */
  public function getGroupsBySearch ($search);

  /**
   * Fetch an array user info for a login string.
   * Elements:
   *	user_login		The login field that will match or be inserted into the users table.
   *	user_email
   *	user_nicename
   *	nickname
   *	display_name
   *	first_name
   *	last_name
   *
   * @param string $login
   * @return array
   */
  public function getUserInfo ($login);

  /**
   * Fetch an array of group ids a user is a member of.
   *
   * Throws an exception if the user isn't found in the underlying data-source.
   *
   * Note: IDs and values are defined by the underlying implementation and can
   * only be assumed to be strings.
   *
   * @param string $login
   * @return array
   */
  public function getGroupsForUser ($login);

  /**
   * Fetch a two-dimensional array user info for every member of a group.
   * Ex:
   *	array(
   *		array(
   *			'user_login' => '1',
   *			'user_email' => 'john.doe@example.com',
   *			...
   *		),
   *		array(
   *			'user_login' => '2',
   *			'user_email' => 'jane.doe@example.com',
   *			...
   *		),
   *		...
   *	);
   *
   *
   * Elements:
   *	user_login		The login field that will match or be inserted into the users table.
   *	user_email
   *	user_nicename
   *	nickname
   *	display_name
   *	first_name
   *	last_name
   *
   * Note: IDs and values are defined by the underlying implementation and can
   * only be assumed to be strings.
   *
   * @param string $groupId
   * @return array or NULL if group id not found
   */
  public function getGroupMemberInfo ($groupId);

}
