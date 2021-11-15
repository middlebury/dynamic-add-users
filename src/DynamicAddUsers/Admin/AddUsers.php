<?php

namespace DynamicAddUsers\Admin;

use DynamicAddUsers\Directory\DirectoryInterface;
use DynamicAddUsers\GroupSyncerInterface;
use DynamicAddUsers\UserManagerInterface;

/**
 * Class to generate the AddUsers interface that is presented to site-admins.
 */
class AddUsers {

  /**
   * Create a new instance.
   *
   * @param \DynamicAddUsers\Directory\DirectoryInterface $directory
   *   The directory service implementation for user/group lookup.
   * @param \DynamicAddUsers\UserManagerInterface $userManger
   *   The service implementation for user creation/updates.
   * @param \DynamicAddUsers\GroupSyncerInterface $groupSyncer
   *   The service implementation for synchronizing group memberships to roles.
   */
  public static function init(DirectoryInterface $directory, UserManagerInterface $userManager, GroupSyncerInterface $groupSyncer) {
    static $addUsers;
    if (!isset($addUsers)) {
      $addUsers = new static($directory, $userManager, $groupSyncer);
    }
    return $addUsers;
  }

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
   * Create a new instance.
   *
   * @param \DynamicAddUsers\Directory\DirectoryInterface $directory
   *   The directory service implementation for user/group lookup.
   * @param \DynamicAddUsers\UserManagerInterface $userManger
   *   The service implementation for user creation/updates.
   * @param \DynamicAddUsers\GroupSyncerInterface $groupSyncer
   *   The service implementation for synchronizing group memberships to roles.
   */
  protected function __consDYNADDUSERS_DB_VERSIONruct(DirectoryInterface $directory, UserManagerInterface $userManager, GroupSyncerInterface $groupSyncer) {
    $this->directory = $directory;
    $this->groupSyncer = $groupSyncer;
    $this->userManger = $userManager;

    add_action('admin_menu', [$this, 'adminMenu']);
    // Hooks for AJAX lookups of users/groups
    add_action("admin_head", [$this, 'adminHeadJavascript']);
    add_action('wp_ajax_dynaddusers_search_users', [$this, 'ajaxSearchUsers']);
    add_action('wp_ajax_dynaddusers_search_groups', [$this, 'ajaxSearchGroups']);
    add_action('admin_init', [$this, 'adminInit']);
  }

  public function adminInit () {
    // Redirect all requests to the WordPress built-in user creation page.
    global $pagenow;
    if ( $pagenow == 'user-new.php' ) {
      wp_redirect( admin_url( 'users.php?page=dynaddusers' ), 301 );
    }

    // Make sure that our autocomplete library is available.
    wp_enqueue_script('jquery-ui-autocomplete');
  }

  /**
   * Handler for the 'admin_menu' action. Create our menu item[s].
   */
  public function adminMenu () {
    // Add a new submenu under Users:
      add_submenu_page('users.php','Add New Users', 'Add New', 'administrator', 'dynaddusers', [$this, 'optionsPage']);

      // Re-write the Users submenu to replace the built-in 'Add New' submenu
      // with our plugin
    global $submenu;
    // Find our key
    $dynaddusersKey = null;
    if (!isset($submenu['users.php']))
      return;
    foreach ($submenu['users.php'] as $key => $array) {
      if ($array[2] == 'dynaddusers') {
        $dynaddusersKey = $key;
        break;
      }
    }
    if ($dynaddusersKey) {
      // wp-admin/menu.php hard-codes the user-new option at position 10.
      // Replace it with our menu
      unset($submenu['users.php'][10]);
      $submenu['users.php'][10] = $submenu['users.php'][$dynaddusersKey];
      unset($submenu['users.php'][$dynaddusersKey]);
      ksort($submenu['users.php']);
    }
  }

  function optionsPage () {
    ob_start();
    if (isset($_POST['user']) && $_POST['user']) {
      try {
        $info = $this->directory->getUserInfo($_POST['user']);
        if (!is_array($info)) {
          print "Could not find user '".$_POST['user']."'.";
        } else {
          try {
            // Get or create the user object.
            $user = $this->userManager->getOrCreateUser($info);
          } catch (Exception $e) {
            print "Error: ".htmlentities($e->getMessage());
          }
        }
      } catch (Exception $e) {
        // If a users wasn't found/created via info in the CAS directory, look in
        // the local user database for a matching user.
        if ($e->getCode() >= 400 && $e->getCode() < 500) {
          $user = get_user_by('login', $_POST['user']);
        }
      }

      if (empty($user)) {
        print "Could not find user '".esc_attr($_POST['user'])."'.";
      } else {
        try {
          $this->userManager->addUserToBlog($user, $_POST['role']);
          print "Added ".$user->display_name.' as '.strip_tags($_POST['role']);
        } catch (Exception $e) {
          print $e->getMessage();
        }
      }
    }
    $userResults = ob_get_clean();

    ob_start();
    $sync = true;
    if (!empty($_POST['group'])) {
      try {
        if (isset($_POST['group_sync']) && $_POST['group_sync'] == 'sync') {
          $this->groupSyncer->keepGroupInSync($_POST['group'], strip_tags($_POST['role']));
          $changes = $this->groupSyncer->syncGroup(get_current_blog_id(), $_POST['group'], strip_tags($_POST['role']));
          if (count($changes)) {
            print implode("\n<br/>", $changes);
          } else {
            print "No changes to synchronize.";
          }
        } else {
          $sync = false;
          $memberInfo = $this->directory->getGroupMemberInfo($_POST['group']);
          if (!is_array($memberInfo)) {
            print "Could not find members for '".$_POST['group']."'.";
          } else {
            foreach ($memberInfo as $info) {
              try {
                $user = $this->userManager->getOrCreateUser($info);
                $this->userManager->addUserToBlog($user, $_POST['role']);
                print "Added ".$user->display_name.' as '.$this->article($_POST['role']).' '.strip_tags($_POST['role']);
              } catch (Exception $e) {
                print esc_html($e->getMessage());
              }
              print "\n\t<br/>";
            }
          }
        }
      } catch (Exception $e) {
        if ($e->getCode() == 404) {
          print "Group '".esc_html($_POST['group'])."' was not found. You may want to stop syncing.";
        } else {
          print "Error: " . esc_html($e->getMessage());
        }
      }
    }
    $groupResults = ob_get_clean();

    ob_start();
    if (!empty($_POST['sync_group_id'])) {
      if (!empty($_POST['stop_syncing_and_remove_users'])) {
        try {
          $this->groupSyncer->removeUsersInGroup($_POST['sync_group_id']);
          $this->groupSyncer->stopSyncingGroup($_POST['sync_group_id']);
        } catch (Exception $e) {
          if ($e->getCode() == 404) {
            print "Group '".esc_html($_POST['sync_group_id'])."' was not found. You may want to stop syncing.";
          } else {
            print "Error: " . esc_html($e->getMessage());
          }
        }
      } else if (!empty($_POST['stop_syncing'])) {
        $this->groupSyncer->stopSyncingGroup($_POST['sync_group_id']);
      } else {
        try {
          $changes = $this->groupSyncer->syncGroup(get_current_blog_id(), $_POST['sync_group_id'], $_POST['role']);
          print "<strong>Synchronizing  ". DirectoryBase::convertDnToDisplayPath($_POST['sync_group_id']) . ":</strong>\n<br/>";
          print " &nbsp; &nbsp; ";
          if (count($changes)) {
            print implode("\n<br/> &nbsp; &nbsp; ", $changes);
          } else {
            print "No changes to synchronize.";
          }
        } catch (Exception $e) {
          if ($e->getCode() == 404) {
            print "Group '".esc_html($_POST['sync_group_id'])."' was not found. You may want to stop syncing.";
          } else {
            print "Error: " . esc_html($e->getMessage());
          }
        }
      }
    }
    $groupSyncResults = ob_get_clean();

    print "\n<div class='wrap'>";
    print "\n<div id='icon-users' class='icon32'> <br/> </div>";
    print "\n<h2>Add New Users</h2>";
    print "\n<p>Search for users or groups by name or email address to add them to your blog.</p>";
    print "\n<form id='dynaddusers_user_form' action='".$_SERVER['REQUEST_URI']."' method='post'>";
    print "\n<h3>Add An Individual User</h3>";
    print "\n<input type='text' id='dynaddusers_user_search' name='user_search' value='' size='50'/>";
    print "\n<input type='hidden' id='dynaddusers_user' name='user' value=''/>";
    print "\n<input type='submit' value='Add User'/>";
    print "\n as ";
    $this->printRoleElement();
    print "\n</form>";
    if (strlen($userResults)) {
      print "\n<p style='border: 1px solid red; color: red; padding: 0.5em;'>".$userResults."</p>";
    }

    print "\n<form id='dynaddusers_group_form' action='".$_SERVER['REQUEST_URI']."' method='post'>";
    print "\n<h3>Add Users By Group</h3>";
    print "\n<input type='text' id='dynaddusers_group_search' name='group_search' value='' size='50'/>";
    print "\n<input type='hidden' id='dynaddusers_group' name='group' value=''/>";
    print "\n<input type='submit' value='Add Group Members'/>";
    print "\n as ";
    $this->printRoleElement();
    print "\n<br/>";
    print "\n<label><input type='radio' id='dynaddusers_sync' name='group_sync' value='sync' ".($sync?"checked='checked'":"")."/> Keep in Sync</label>";
    print "\n &nbsp; &nbsp; <label><input type='radio' id='dynaddusers_sync' name='group_sync' value='once' ".(!$sync?"checked='checked'":"")."/> Add once</label>";
    print "\n</form>";
    if (strlen($groupResults)) {
      print "\n<p style='border: 1px solid red; color: red; padding: 0.5em;'>".$groupResults."</p>";
    }

    print "\n<h3>Synced Groups</h3>";
    print "\n<p>Users who are members of synced groups will automatically be added-to or removed-from the site each time they log into WordPress. If you wish to fully synchronize a group so that you can see all potential users in the WordPress user-list, press the <em>Sync Now</em> button.</p>";
    $groups = $this->groupSyncer->getSyncedGroups();
    if (!count($groups)) {
      print "\n<p><em>none</em></p>";
    } else {
      print "\n<table id='dynaddusers_groups'>";
      print "\n<thead>";
      print "\n\t<tr><th>Group</th><th>Role</th><th>Actions</th></tr>";
      print "\n</thead>";
      print "\n<tbody>";
      foreach ($groups as $group) {
        print "\n\t<tr>";
        print "\n\t\t<td>";
        print DirectoryBase::convertDnToDisplayPath($group->group_id);
        print "\n\t\t</td>";
        print "\n\t\t<td style='padding-left: 10px; padding-right: 10px;'>";
        print $group->role;
        print "\n\t\t</td>";
        print "\n\t\t<td>";
        print "\n\t\t\t<form action='"."' method='post'>";
        print "\n\t\t\t<input type='hidden' name='sync_group_id' value='".htmlentities($group->group_id)."'/>";
        print "\n\t\t\t<input type='hidden' name='role' value='".$group->role."'/>";
        print "\n\t\t\t<input type='submit' name='sync_now' value='Sync Now'/>";
        print "\n\t\t\t<input type='submit' name='stop_syncing' value='Stop Syncing'/>";
        print "\n\t\t\t<input type='submit' name='stop_syncing_and_remove_users' value='Stop Syncing And Remove Users'/>";
        print "\n\t\t\t</form>";
        print "\n\t\t</td>";
        print "\n\t</tr>";
      }
      print "\n</tbody>";
      print "\n</table>";
    }
    if (strlen($groupSyncResults)) {
      print "\n<p style='border: 1px solid red; color: red; padding: 0.5em;'>".$groupSyncResults."</p>";
    }

    print "\n</div>";
  }

  public function adminHeadJavascript () {
  ?>

    <script type="text/javascript" >
    // <![CDATA[

    jQuery(document).ready( function($) {
      if(!$("#dynaddusers_user_search").length) {
        return;
      }
      $("#dynaddusers_user_search").autocomplete({
        source: ajaxurl + "?action=dynaddusers_search_users",
        delay: 600,
        minLength: 3,
        select: function (event, ui) {
          this.value = ui.item.label;
          $('#dynaddusers_user').val(ui.item.value);
          event.preventDefault();
        },
        focus: function (event, ui) {
          this.value = ui.item.label;
          event.preventDefault();
        },
        change: function (event, ui) {
          // Ensure that the hidden is set, though it should be by select.
          if (ui.item && ui.item.value) {
            $('#dynaddusers_user').val(ui.item.value);
          }
          // Clear out the hidden field is cleared if we don't have a chosen item or delete the choice.
          else {
            $('#dynaddusers_user').val('');
          }
        }
      });

      $("#dynaddusers_group_search").autocomplete({
        source: ajaxurl + "?action=dynaddusers_search_groups",
        delay: 600,
        minLength: 3,
        select: function (event, ui) {
          this.value = ui.item.label;
          $('#dynaddusers_group').val(ui.item.value);
          event.preventDefault();
        },
        focus: function (event, ui) {
          this.value = ui.item.label;
          event.preventDefault();
        },
        change: function (event, ui) {
          // Ensure that the hidden is set, though it should be by select.
          if (ui.item && ui.item.value) {
            $('#dynaddusers_group').val(ui.item.value);
          }
          // Clear out the hidden field is cleared if we don't have a chosen item or delete the choice.
          else {
            $('#dynaddusers_group').val('');
          }
        }
      });

      // Check for users being selected
      $('#dynaddusers_user_form').submit(function() {
        if (!$('#dynaddusers_user').val()) {
          alert('Please select a user from the search results.');
          return false;
        }
      });
      $('#dynaddusers_group_form').submit(function() {
        if (!$('#dynaddusers_group').val()) {
          alert('Please select a group from the search results.');
          return false;
        }
      });
    });

    // ]]>
    </script>

  <?php
  }

  // Fullfill the search-users hook
  public function ajaxSearchUsers () {
    while (ob_get_level())
      ob_end_clean();
    header('Content-Type: text/json');
    $results = array();
    if ($_REQUEST['term']) {
      foreach ($this->directory->getUsersBySearch($_REQUEST['term']) as $user) {
        $results[] = array(
          'value' => $user['user_login'],
          'label' => $user['display_name']." (".$user['user_email'].")",
        );
      }
    }
    print json_encode($results);
    exit;
  }

  // Fullfill the search-groups hook
  public function ajaxSearchGroups () {
    while (ob_get_level())
      ob_end_clean();
    header('Content-Type: text/json');
    $results = array();
    if ($_REQUEST['term']) {
      foreach ($this->directory->getGroupsBySearch($_REQUEST['term']) as $id => $displayName) {
        $results[] = array(
          'value' => $id,
          'label' => $displayName,
        );
      }
    }
    print json_encode($results);
    exit;
  }

  /**
   * Print out the role form element
   *
   * @return void
   * @since 1/8/10
   */
  protected function printRoleElement () {
    print "\n<select name='role' id='role'>";
    $role = isset($_POST['role']) ? $_POST['role'] : get_option('default_role');
    wp_dropdown_roles($role);
    print "\n</select>";
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
