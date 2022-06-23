<?php

namespace DynamicAddUsers;

use DynamicAddUsers\Directory\CASDirectoryDirectory;
use DynamicAddUsers\Directory\DirectoryInterface;
use DynamicAddUsers\Directory\NullDirectory;
use DynamicAddUsers\Directory\MicrosoftGraphDirectory;
use DynamicAddUsers\UserManager;
use DynamicAddUsers\GroupSyncer;
use DynamicAddUsers\LoginMapper\NullLoginMapper;
use DynamicAddUsers\LoginMapper\UserLoginLoginMapper;
use DynamicAddUsers\LoginMapper\WpSamlAuthLoginMapper;
use DynamicAddUsers\Admin\AddUsers;
use DynamicAddUsers\Admin\NetworkSettings;
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
    $this->getLoginMapper()->setup($this);
    // Register our AddUsers interface.
    AddUsers::init($this);
    // Register our NetworkSettings interface.
    NetworkSettings::init($this);
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
      // Try to load the configured directory implementation.
      $implementationId = get_site_option('dynamic_add_users__directory_impl', 'null_directory');
      foreach ($this->getImplementingClasses('DynamicAddUsers\Directory\DirectoryInterface') as $class) {
        if ($class::id() == $implementationId) {
          $this->directory = new $class();
        }
      }

      // Set a null directory by default to allow bootstrapping of bad values.
      if (!isset($this->directory)) {
        $this->directory = new NullDirectory();
      }
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
      // Try to load the configured directory implementation.
      $implementationId = get_site_option('dynamic_add_users__login_mapper_impl', 'null_login_mapper');
      foreach ($this->getImplementingClasses('DynamicAddUsers\LoginMapper\LoginMapperInterface') as $class) {
        if ($class::id() == $implementationId) {
          $this->loginMapper = new $class();
        }
      }

      // Set a null directory by default to allow bootstrapping of bad values.
      if (!isset($this->loginMapper)) {
        $this->loginMapper = new NullLoginMapper();
      }
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
          trigger_error('DynamicAddUsers: Tried to update user groups for  ' . $user->ID . ' / '. $external_user_id . ' but they were not found the directory service.', E_USER_NOTICE);
        } else {
          throw $e;
        }
      }
    }

    // Let other modules take action based on user groups.
    // See dynamic_add_users__update_user_on_login(WP_User $user, array $groups) for
    // an example.
    do_action('dynamic_add_users__update_user_on_login', $user, $groups);
  }


  /*******************************************************
   * Database and install.
   *******************************************************/
  const DYNADDUSERS_DB_VERSION = '0.2';

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
    $charset_collate = $wpdb->get_charset_collate();

    $synced_groups_table_sql = "CREATE TABLE " . $groups . " (
      blog_id int(11) NOT NULL,
      group_id varchar(255) NOT NULL,
      group_label VARCHAR(255) NULL,
      role varchar(25) NOT NULL,
      last_sync datetime default NULL,
      PRIMARY KEY  (blog_id,group_id),
      KEY last_sync (last_sync)
    ) $charset_collate;";

    $synced_users_table_sql = "CREATE TABLE " . $synced . " (
      blog_id int(11) NOT NULL,
      group_id varchar(255) NOT NULL,
      user_id int(11) NOT NULL,
      PRIMARY KEY  (blog_id,group_id,user_id)
    ) $charset_collate;";

    if ($wpdb->get_var("SHOW TABLES LIKE '$groups'") != $groups ) {
      require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
      dbDelta($synced_groups_table_sql);
      dbDelta($synced_users_table_sql);
      add_site_option("dynaddusers_db_version", self::DYNADDUSERS_DB_VERSION);
    }

    // Upgrade the schema if needed.
    if ( version_compare( get_site_option("dynaddusers_db_version", '0.1'), self::DYNADDUSERS_DB_VERSION ) < 0 ) {
      require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
      dbDelta($synced_groups_table_sql);
      dbDelta($synced_users_table_sql);
      update_site_option("dynaddusers_db_version", self::DYNADDUSERS_DB_VERSION);
    }

  }

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
  public function getDirectoryImplementations() {
    $implementations = [];
    foreach ($this->getImplementingClasses('DynamicAddUsers\Directory\DirectoryInterface') as $class) {
      $implementations[$class::id()] = $class::label();
    }
    return $implementations;
  }

  /**
   * Set the directory implementation to use.
   *
   * @param string $id
   *   The identifier of the implementation that should be used.
   */
  public function setDirectoryImplementation($id) {
    $implementations = $this->getDirectoryImplementations();
    if (isset($implementations[$id])) {
      update_site_option('dynamic_add_users__directory_impl', $id);
      unset($this->directory);
    }
    else {
      throw new Exception('Directory ID, '.esc_attr($id).' is not one of [' . implode(', ', array_keys($implementations)) . '].');
    }
  }

  /**
   * Answer an array of LoginMapper implementations that can be configured.
   *
   * Format:
   *   [id => label]
   *
   * @return array
   */
  public function getLoginMapperImplementations() {
    $implementations = [];
    foreach ($this->getImplementingClasses('DynamicAddUsers\LoginMapper\LoginMapperInterface') as $class) {
      $implementations[$class::id()] = $class::label();
    }
    return $implementations;
  }

  /**
   * Set the LoginMapper implementation to use.
   *
   * @param string $id
   *   The identifier of the implementation that should be used.
   */
  public function setLoginMapperImplementation($id) {
    $implementations = $this->getLoginMapperImplementations();
    if (isset($implementations[$id])) {
      update_site_option('dynamic_add_users__login_mapper_impl', $id);
      unset($this->loginMapper);
    }
    else {
      throw new Exception('Login Mapper ID, '.esc_attr($id).' is not one of [' . implode(', ', array_keys($implementations)). '].');
    }
  }

  /**
   * Answer an array of class-names that implement an interface.
   *
   * From: https://stackoverflow.com/a/12230576/15872
   *
   * @return array
   *   An array of class-names.
   */
  protected function getImplementingClasses( $interfaceName ) {
    // Ensure that our in-plugin implemenations get autoloaded so that they are
    // discoverable by accessing a class constant.
    // If other plugins provide implemenations they should ensure
    // that they get loaded via include_once() or a similar mechanism.
    static $loaded;
    if (!$loaded) {
      NullDirectory::load;
      CASDirectoryDirectory::load;
      MicrosoftGraphDirectory::load;
      NullLoginMapper::load;
      UserLoginLoginMapper::load;
      WpSamlAuthLoginMapper::load;
    }

    return array_filter(
        get_declared_classes(),
        function( $className ) use ( $interfaceName ) {
            return in_array( $interfaceName, class_implements( $className ) );
        }
    );
  }

}
