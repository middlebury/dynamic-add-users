<?php

namespace DynamicAddUsers\LoginMapper;


/**
 * A base class with common functionality for login mapper implementations.
 */
abstract class LoginMapperBase
{

  /**
   * Answer the current value of a setting.
   *
   * @param string $settingKey
   *   The setting key.
   * @return string
   *   The current setting value.
   */
  public function getSetting($settingKey) {
    return get_site_option($settingKey);
  }

  /**
   * Update the value of a setting.
   *
   * @param string $settingKey
   *   The setting key.
   * @param string $value
   *   The setting value.
   */
  public function updateSetting($settingKey, $value) {
    update_site_option($settingKey, $value);
  }

  /**
   * Answer true if settings are valid, false otherwise.
   *
   * @return boolean
   *   True if settings are valid, false otherwise.
   */
  public function settingsValid() {
    return empty($this->checkSettings());
  }

}
