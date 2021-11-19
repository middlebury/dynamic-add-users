<?php

namespace DynamicAddUsers;

/**
 * Methods for working with configurable/pluggable components of DynamicAddUsers.
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

  /**
   * Answer true if settings are valid, false otherwise.
   *
   * @return boolean
   *   True if settings are valid, false otherwise.
   */
  public function settingsValid();

  /**
   * Validate the settings and return an array of error messages.
   *
   * @return array
   *   Any error messages for settings. If empty, settings are validated.
   */
  public function checkSettings();

  /**
   * Answer an array of test argments that should be passed to our test function.
   *
   * This is a nested array that describes the form elements/arguments used by
   * this implementation. Options is only needed for select/radio/checkboxes
   * type fields.
   *
   * Format:
   *    [
   *      'argument' => [
   *        'label' => 'argument label',
   *        'description' => 'description of the argument.',
   *        'value' => 'current value',
   *        'type' => 'select',
   *        'options' => [
   *          'value' => 'label',
   *          'value2' => 'label2',
   *        ],
   *      ],
   *      'argument_2' => [
   *        'label' => 'argument label2',
   *        'description' => 'description of the argument.',
   *        'value' => 'current value',
   *        'type' => 'text',
   *      ],
   *    ]
   *
   * @return array
   */
  public function getTestArguments();

  /**
   * If possible, test the settings against the backing system.
   *
   * @param array $arguments
   *   An array of arguments [argument => value, argument_2 => value2] as
   *   described by getTestArguments().
   *
   * @return array
   *   An array of results with information about each test performed.
   *   Each result should indicatate success or failure as well as a message.
   *      [
   *        [
   *          'success' => true,
   *          'message' => 'Host exists at the URL provided.',
   *        ],
   *        [
   *          'success' => false,
   *          'message' => 'Query for xyz failed.',
   *        ],
   *      ]
   */
  public function testSettings(array $arguments = []);

}
