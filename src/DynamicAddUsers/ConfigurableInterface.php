<?php

namespace DynamicAddUsers;

/**
 *
 */
interface ConfigurableInterface
{

  /**
   * Answer an identifier for this implementation.
   *
   * @return string
   *   The implementation id.
   */
  public static function id();

  /**
   * Answer a label for this implementation.
   *
   * @return string
   *   The implementation label.
   */
  public static function label();

  /**
   * Answer an array of settings used by this directory.
   *
   * This is a nested array that describes the form elements/settings used by
   * this implementation. Options is only needed for select/radio/checkboxes
   * type fields.
   *
   * Format:
   *    [
   *      'setting-key' => [
   *        'label' => 'setting label',
   *        'description' => 'description of the setting.',
   *        'value' => 'current value',
   *        'type' => 'select',
   *        'options' => [
   *          'value' => 'label',
   *          'value2' => 'label2',
   *        ],
   *      ],
   *      'setting-key-2' => [
   *        'label' => 'setting label2',
   *        'description' => 'description of the setting.',
   *        'value' => 'current value',
   *        'type' => 'text',
   *      ],
   *    ]
   *
   * @return array
   */
  public function getSettings();

  /**
   * Answer the current value of a setting.
   *
   * @param string $settingKey
   *   The setting key.
   * @return string
   *   The current setting value.
   */
  public function getSetting($settingKey);

  /**
   * Update the value of a setting.
   *
   * @param string $settingKey
   *   The setting key.
   * @param string $value
   *   The setting value.
   */
  public function updateSetting($settingKey, $value);

}
