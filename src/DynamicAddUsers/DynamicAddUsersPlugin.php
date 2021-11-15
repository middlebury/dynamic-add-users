<?php

namespace DynamicAddUsers;

require_once( dirname(__FILE__) . '/DynamicAddUsersPluginInterface.php' );
require_once( dirname(__FILE__) . '/Directory/CASDirectoryDirectory.php' );
require_once( dirname(__FILE__) . '/Directory/DirectoryBase.php' );
require_once( dirname(__FILE__) . '/UserManager.php' );
require_once( dirname(__FILE__) . '/GroupSyncer.php' );
require_once( dirname(__FILE__) . '/LoginMapper/WpSamlAuthLoginMapper.php' );
require_once( dirname(__FILE__) . '/Admin/AddUsers.php' );

use DynamicAddUsers\Directory\CASDirectoryDirectory;
use DynamicAddUsers\Directory\DirectoryBase;
use DynamicAddUsers\UserManager;
use DynamicAddUsers\GroupSyncer;
use DynamicAddUsers\LoginMapper\WpSamlAuthLoginMapper;
use DynamicAddUsers\Admin\AddUsers;
use WP_User;
use Exception;

/**
 * Central plugin class.
 */
class DynamicAddUsersPlugin implements DynamicAddUsersPluginInterface
{

  /**
   * Initialize the plugin.
   */
  public function __construct() {
    // Check the database tables.
    add_action( 'plugins_loaded', [$this, 'checkDatabaseState'] );
    // Set up login actions.
    $this->getLoginMapper();
    // Register our AddUsers interface.
    AddUsers::init($this->getDirectory(), $this->getUserManager(), $this->getGroupSyncer());
  }

  /*******************************************************
   * Service access.
   *******************************************************/

  /**
   * @var \DynamicAddUsers\Directory\DirectoryInterface $directory
   *   The directory service implementation for user/group lookup.
   */
  protected $directory;

  /**
   * @var \DynamicAddUsers\UserManagerInterface $userManger
   *   The service implementation for user creation/updates.
   */
  protected $userManger;

  /**
   * @var \DynamicAddUsers\GroupSyncerInterface $groupSyncer
   *   The service implementation for synchronizing group memberships to roles.
   */
  protected $groupSyncer;

  /**
   * @var \DynamicAddUsers\LoginMapper\LoginMapperInterface $loginMapper
   *   The service implementation for mapping login attributes to external IDs.
   */
  protected $loginMapper;

  /**
   * Answer the currently configured DirectoryInterface implementation.
   *
   * @return \DynamicAddUsers\Directory\DirectoryInterface
   */
  public function getDirectory() {
    if (!isset($this->directory)) {
      if (!defined('DYNADDUSERS_CAS_DIRECTORY_URL')) {
        throw new Exception('DYNADDUSERS_CAS_DIRECTORY_URL must be defined.');
      }
      if (!defined('DYNADDUSERS_CAS_DIRECTORY_ADMIN_ACCESS')) {
        throw new Exception('DYNADDUSERS_CAS_DIRECTORY_ADMIN_ACCESS must be defined.');
      }
      $this->directory = new CasDirectoryDirectory(DYNADDUSERS_CAS_DIRECTORY_URL, DYNADDUSERS_CAS_DIRECTORY_ADMIN_ACCESS);
    }
    return $this->directory;
  }

  /**
   * Answer the currently configured UserManagerInterface implementation.
   *
   * @return \DynamicAddUsers\UserManagerInterface
   */
  public function getUserManager() {
    if (!isset($this->userManager)) {
      $this->userManager = new UserManager($this->getDirectory());
    }
    return $this->userManager;
  }

  /**
   * Answer the currently configured GroupSyncerInterface implementation.
   *
   * @return \DynamicAddUsers\GroupSyncerInterface
   */
  public function getGroupSyncer() {
    if (!isset($this->groupSyncer)) {
      $this->groupSyncer = new GroupSyncer($this->getDirectory(), $this->getUserManager());
    }
    return $this->groupSyncer;
  }

  /**
   * Answer the currently configured LoginMapper implementation.
   *
   * @return \DynamicAddUsers\LoginMapper\LoginMapperInterface
   */
  public function getLoginMapper() {
    if (!isset($this->loginMapper)) {
      $this->loginMapper = new WpSamlAuthLoginMapper();
      $this->loginMapper->setup($this);
    }
    return $this->loginMapper;
  }

  /*******************************************************
   * Login flow.
   *******************************************************/

  /**
   * Action to take on user login.
   *
   * LoginMapperInterface implementations *should* call this function after
   * attempting to map a login response to an external user identifier.
   *
   * Flow of actions:
   *   1. A LoginMapperInterface implementation hooks into the authentication
   *      plugin's post-authentication action and maps the user attributes to an
   *      external user-id that is valid in the DirectoryInterface implementation.
   *   2. The LoginMapperInterface implementation calls onLogin().
   *   3. onLogin() looks up a user's groups in the
   *      DirectoryInterface implementation.
   *   4. onLogin() passes the user and their groups to the
   *      GroupSyncerInterface implementation to set appropriate roles in target
   *      sites.
   *   5. onLogin() finishes and triggers the 'dynaddusers_update_user_on_login'
   *      action to allow other modules to take actions on login.
   *
   *
   * @param WP_User $user
   *   The user who has authenticated.
   * @param optional string $external_user_id
   *   If the login attributes map to an external user identifier that can be
   *   looked up in the directory service, that ID should be passed here.
   */
  function onLogin(WP_User $user, $external_user_id = NULL) {
    // Default to no groups.
    $groups = [];

    if (!is_null($external_user_id)) {
      try {
        $groups = $this->getDirectory()->getGroupsForUser($external_user_id);
        $this->getGroupSyncer()->syncUser($user->ID, $groups);
      } catch (Exception $e) {
        if ($e->getCode() == 404 || $e->getCode() == 400) {
          // Skip if not found in the data source.
          trigger_error('DynamicAddUsers: Tried to update user groups for  ' . $user->id . ' / '. $external_user_id . ' but they were not found the directory service.', E_USER_NOTICE);
        } else {
          throw $e;
        }
      }
    }

    // Let other modules take action based on user groups.
    // See dynaddusers_update_user_on_login(WP_User $user, array $groups) for
    // an example.
    do_action('dynaddusers_update_user_on_login', $user, $groups);
  }


  /*******************************************************
   * Database and install.
   *******************************************************/
  const DYNADDUSERS_DB_VERSION = '0.1';

  /**
   * Validate that our database state is current.
   */
  public function checkDatabaseState() {
    if (get_site_option( 'dynaddusers_db_version' ) != self::DYNADDUSERS_DB_VERSION) {
      $this->installOrUpdateDatabase();
    }
  }

  /**
   * Install hook.
   */
  protected function installOrUpdateDatabase () {
    global $wpdb;

    $groups = $wpdb->base_prefix . "dynaddusers_groups";
    $synced = $wpdb->base_prefix . "dynaddusers_synced";
    if ($wpdb->get_var("SHOW TABLES LIKE '$groups'") != $groups) {
      require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

      $sql = "CREATE TABLE " . $groups . " (
        blog_id int(11) NOT NULL,
        group_id varchar(255) NOT NULL,
        role varchar(25) NOT NULL,
        last_sync datetime default NULL,
        PRIMARY KEY  (blog_id,group_id),
        KEY last_sync (last_sync)
      );";
      dbDelta($sql);

      $sql = "CREATE TABLE " . $synced . " (
        blog_id int(11) NOT NULL,
        group_id varchar(255) NOT NULL,
        user_id int(11) NOT NULL,
        PRIMARY KEY  (blog_id,group_id,user_id)
      );";
      dbDelta($sql);

      add_option("dynaddusers_db_version", self::DYNADDUSERS_DB_VERSION);
    }
  }

}
