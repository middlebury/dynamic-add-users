<?php

namespace DynamicAddUsers\Admin;

use DynamicAddUsers\DynamicAddUsersPluginInterface;
use DynamicAddUsers\ConfigurableInterface;

/**
 * Class to generate the AddUsers interface that is presented to site-admins.
 */
class NetworkSettings {

  /**
   * Create a new instance.
   *
   * @param \DynamicAddUsers\DynamicAddUsersPluginInterface $plugin
   *   The plugin instance.
   */
  public static function init(DynamicAddUsersPluginInterface $plugin) {
    static $networkSettings;
    if (!isset($networkSettings)) {
      $networkSettings = new static($plugin);
    }
    return $networkSettings;
  }

  /**
   * @var \DynamicAddUsers\DynamicAddUsersPluginInterface $plugin
   *   The plugin instance.
   */
  protected $plugin;

  /**
   * Create a new instance.
   *
   * @param \DynamicAddUsers\DynamicAddUsersPluginInterface $plugin
   *   The plugin instance.
   */
  protected function __construct(DynamicAddUsersPluginInterface $plugin) {
    $this->plugin = $plugin;

    add_action('network_admin_menu', [$this, 'networkAdminMenu']);
  }

  /**
   * Handler for the 'network_admin_menu' action. Create our menu item[s].
   */
  public function networkAdminMenu () {
    add_submenu_page('settings.php', 'Dynamic Add Users', 'Dynamic Add Users', 'manage_network', 'dynamic_add_users', [$this, 'settingsController']);
  }

  /**
   * Common entry point for our settings pages.
   */
  public function settingsController () {
    if (!current_user_can('manage_network')) {
      wp_die('You don\'t have permissions to use this page.');
    }

    print "\n<div class='wrap'>";

    if (isset($_GET['tab']) && $_GET['tab'] == 'test') {
      $this->printTabMenu('test');
      $this->testPage();
    }
    else {
      $this->printTabMenu('settings');
      $this->settingsPage();
    }

    print "\n</div>";
  }

  public function printTabMenu($currentTab = 'settings') {
    print "\n<h2 class='nav-tab-wrapper'>";
    print "\n\t<a class='nav-tab " . ($currentTab == 'settings' ? 'nav-tab-active' : '') . "' href='" . network_admin_url( 'settings.php?page=dynamic_add_users&tab=settings' ) . "'>Settings</a>";
    print "\n\t<a class='nav-tab " . ($currentTab == 'test' ? 'nav-tab-active' : '') . "' href='" . network_admin_url( 'settings.php?page=dynamic_add_users&tab=test' ) . "'>Test</a>";
    print "\n</h2>";
  }

  /**
   * Page for viewing/saving options.
   */
  function settingsPage () {

    // Save our form values.
    if ($_POST) {
      check_admin_referer('dynamic_add_users_settings');
      try {
        if ($_POST['form_section'] == 'directory') {
          $this->saveImplementationOptions($this->plugin->getDirectory());
        }
        elseif ($_POST['form_section'] == 'login_mapper') {
          $this->saveImplementationOptions($this->plugin->getLoginMapper());
        }
        else {
            $this->plugin->setDirectoryImplementation($_POST['dynamic_add_users_directory_impl']);
            $this->plugin->setLoginMapperImplementation($_POST['dynamic_add_users_login_mapper_impl']);
        }
      }
      catch(Exception $e) {
        print "<div id='message' class='error'><p>Error saving options: " . esc_html($e->getMessage()) . "</p></div>";
      }
      print "<div id='message' class='updated'><p>Options Saved</p></div>";
    }

    // Print out our form.
    print "\n<form method='post' action=''>";
    wp_nonce_field( 'dynamic_add_users_settings' );
    print "\n\t<input type='hidden' name='form_section' value='general'>";

    print "\n<h2>Dynamic Add Users settings</h2>";
    print "\n<table class='form-table'>";

    print "\n\t<tr valign='top'>";
    print "\n\t\t<th scope='row'>Directory implementation</th>";
    print "\n\t\t<td>";
    print '<select name="dynamic_add_users_directory_impl" id="dynamic_add_users_directory_impl">';
    $current = $this->plugin->getDirectory()::id();
    foreach ($this->plugin->getDirectoryImplementations() as $id => $label) {
      print '<option value="' . esc_attr($id) . '"' . (($id == $current)? ' selected="selected"':'') . '>' . esc_attr($label) .'</option>';
    }
    print "</select>";
    print "\n\t\t\t<p class='description'>The directory implementation to use for user/group lookup.</p>";
    print "\n\t\t</td>";
    print "\n\t</tr>";

    print "\n\t<tr valign='top'>";
    print "\n\t\t<th scope='row'>Login Mapper implementation</th>";
    print "\n\t\t<td>";
    print '<select name="dynamic_add_users_login_mapper_impl" id="dynamic_add_users_login_mapper_impl">';
    $current = $this->plugin->getLoginMapper()::id();
    foreach ($this->plugin->getLoginMapperImplementations() as $id => $label) {
      print '<option value="' . esc_attr($id) . '"' . (($id == $current)? ' selected="selected"':'') . '>' . esc_attr($label) .'</option>';
    }
    print "</select>";
    print "\n\t\t\t<p class='description'>The implementation to use for mapping login attributes to external user IDs that will be recognized by the directory.</p>";
    print "\n\t\t</td>";
    print "\n\t</tr>";

    print "\n</table>";
    submit_button();
    print "\n</form>";


    // Directory settings.
    $this->printServiceForm('directory', 'Directory', $this->plugin->getDirectory());

    // LoginMapper settings.
    $this->printServiceForm('login_mapper', 'Login Mapper', $this->plugin->getLoginMapper());

  }

  /**
   * Print out the form for one of our services.
   *
   * @param string $serviceType
   *   The type of service
   * @param string $serviceTypeLabel
   *   A label for type of service
   * @param \DynamicAddUsers\ConfigurableInterface $service
   *   The service to print the form for.
   */
  protected function printServiceForm($serviceType, $serviceTypeLabel, ConfigurableInterface $service) {
    print "\n<form method='post' action=''>";
    wp_nonce_field( 'dynamic_add_users_settings' );
    print "\n\t<input type='hidden' name='form_section' value='" . $serviceType . "'>";
    print "\n<h3>" . $serviceTypeLabel . " settings for '" . $service::label() . "'</h3>";
    if (!$service->settingsValid()) {
      print "\n<div class='error'>";
      print "\n\t<ul>";
      foreach ($service->checkSettings() as $message) {
        print "\n\t\t<li><strong>" . $service::label() . ":</strong> " . esc_html($message) . "</li>";
      }
      print "\n\t</ul>";
      print "\n</div>";
    }
    print "\n<table class='form-table'>";
    $this->printSettingsFormElements($service->getSettings());
    print "\n</table>";
    submit_button();
    print "\n</form>";
  }

  /**
   * Helper function to print out settings form elements.
   *
   * @param array $settings
   *   The description of the form elements.
   */
  protected function printSettingsFormElements(array $settings) {
    if (empty($settings)) {
      print "\n\t<tr valign='top'>";
      print "\n\t\t<td>";
      print "\n\t\t\t<p class='description'>This implementation has no settings.</p>";
      print "\n\t\t</td>";
      print "\n\t</tr>";
    }
    else {
      foreach ($settings as $settingId => $setting) {
        print "\n\t<tr valign='top'>";
        print "\n\t\t<th scope='row'>" . $setting['label'] . "</th>";
        print "\n\t\t<td>";
        if ($setting['type'] == 'select') {
          print '<select name="' . $settingId . '" id="' . $settingId . '">';
          foreach ($setting['options'] as $value => $label) {
            print '<option value="' . esc_attr($value) . '"' . (($value == $setting['value'])? ' selected="selected"':'') . '>' . esc_attr($label) .'</option>';
          }
          print "</select>";
        }
        elseif ($setting['type'] == 'password') {
          print '<input type="password" size="80" name="' . $settingId . '" id="' . $settingId . '" value="**********">';
        }
        else {
          print '<input type="text" size="80" name="' . $settingId . '" id="' . $settingId . '" value="' . esc_attr($setting['value']) . '">';
        }
        print "\n\t\t\t<p class='description'>" . $setting['description'] . "</p>";
        print "\n\t\t</td>";
        print "\n\t</tr>";
      }
    }
  }

  /**
   * Save the options for one of our implementations.
   *
   * @param \DynamicAddUsers\ConfigurableInterface $service
   *   The service to save options for.
   */
  protected function saveImplementationOptions(ConfigurableInterface $service) {
    foreach ($service->getSettings() as $settingId => $setting) {
      if (isset($_POST[$settingId])) {
        // Password fields don't write the original value to HTML to prevent
        // exposure, so only change if the value passed isn't just asterisks.
        if ($setting['type'] == 'password') {
          if (!preg_match('/^\*+$/', $_POST[$settingId])) {
            $service->updateSetting($settingId, $_POST[$settingId]);
          }
        }
        else {
          $service->updateSetting($settingId, $_POST[$settingId]);
        }
      }
    }
  }

  function testPage () {
    // Directory test.
    $this->serviceTestForm('directory', 'Directory', $this->plugin->getDirectory());

    // LoginMapper settings.
    $this->serviceTestForm('login_mapper', 'Login Mapper', $this->plugin->getLoginMapper());
  }

  /**
   * Print out the test form for one of our services.
   *
   * @param string $serviceType
   *   The type of service
   * @param string $serviceTypeLabel
   *   A label for type of service
   * @param \DynamicAddUsers\ConfigurableInterface $service
   *   The service to print the form for.
   */
  protected function serviceTestForm($serviceType, $serviceTypeLabel, ConfigurableInterface $service) {
    $formElements = $service->getTestArguments();
    $args = [];
    if ($_POST && $_POST['form_section'] == $serviceType) {
      check_admin_referer('dynamic_add_users_test');
      $args = $this->getSubmittedValues($formElements);
      // Populate the last-submitted values to the form to allow re-playing the
      // test.
      foreach ($args as $key => $value) {
        $formElements[$key]['value'] = $value;
      }
    }
    print "\n<form method='post' action=''>";
    wp_nonce_field( 'dynamic_add_users_test' );
    print "\n\t<input type='hidden' name='form_section' value='" . $serviceType . "'>";
    print "\n<h3>Test the '" . $service::label() . "' " . $serviceTypeLabel . "</h3>";
    print "\n<table class='form-table'>";
    $this->printSettingsFormElements($formElements);
    print "\n</table>";
    submit_button("Test the '" . $service::label() . "' " . $serviceTypeLabel);
    print "\n</form>";

    if ($_POST && $_POST['form_section'] == $serviceType) {
      check_admin_referer('dynamic_add_users_test');
      $this->runTest($serviceTypeLabel, $service, $args);
    }
  }

  /**
   * Answer arguments submitted in Post for this service.
   *
   * @param array $formElements
   *   The description of the form elements.
   */
  protected function getSubmittedValues(array $formElements) {
    $args = [];
    foreach ($formElements as $key => $info) {
      if (isset($_POST[$key])) {
        $args[$key] = $_POST[$key];
      }
    }
    return $args;
  }

  /**
   * Print out the test form for one of our services.
   *
   * @param string $serviceTypeLabel
   *   A label for type of service
   * @param \DynamicAddUsers\ConfigurableInterface $service
   *   The service to print the form for.
   * @param array $args
   *   The submitted form args for testing.
   */
  protected function runTest($serviceTypeLabel, ConfigurableInterface $service, array $args) {
    foreach ($service->testSettings($args) as $result) {
      print "\n<div class='" . ($result['success']? 'updated':'error') . "'><strong>" . $service::label() . ":</strong> " . $result['message'] . "</div>";
    }
  }

}
