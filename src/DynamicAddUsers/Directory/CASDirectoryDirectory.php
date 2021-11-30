<?php

namespace DynamicAddUsers\Directory;

use DynamicAddUsers\Directory\DirectoryInterface;
use DynamicAddUsers\Directory\DirectoryBase;
use Exception;

/**
 * A Directory implementation that sources user and Group information from
 * Middlebury's CAS Directory web service.
 *
 * See: https://mediawiki.middlebury.edu/LIS/CAS_Directory
 */
class CasDirectoryDirectory extends DirectoryBase implements DirectoryInterface
{

  /**
   * Answer an identifier for this implementation.
   *
   * @return string
   *   The implementation id.
   */
  public static function id() {
    return 'cas_directory';
  }

  /**
   * Answer a label for this implementation.
   *
   * @return string
   *   The implementation label.
   */
  public static function label() {
    return 'CAS Directory';
  }

  /** API Methods **/

  /**
   * Fetch an array user logins and display names for a given search string.
   * Ex: array('1' => 'John Doe', '2' => 'Jane Doe');
   *
   * @param string $search
   * @return array
   */
  protected function getUsersBySearchFromDirectory ($search) {
    $xpath = $this->query(array(
      'action' => 'search_users',
      'query' => $search,
    ));
    $matches = array();
    foreach($xpath->query('/cas:results/cas:entry') as $entry) {
      $matches[] = $this->extractUserInfo($entry, $xpath);
    }
    return $matches;
  }

  /**
   * Fetch an array group ids and display names for a given search string.
   * Ex: array('100' => 'All Students', '5' => 'Faculty');
   *
   * Re-write this method to use your own searching logic if you do not wish
   * to make use of the same web-service.
   *
   * @param string $search
   * @return array
   */
  public function getGroupsBySearchFromDirectory ($search) {
    $xpath = $this->query(array(
      'action' => 'search_groups',
      'query' => $search,
    ));
    $matches = array();
    foreach($xpath->query('/cas:results/cas:entry') as $entry) {
      $matches[$this->extractGroupId($entry, $xpath)] = $this->extractGroupDisplayName($entry, $xpath);
    }
    return $matches;
  }

  /**
   * Fetch an array user info for a login string.
   * Elements:
   *  user_login    The login field that will match or be inserted into the users table.
   *  user_email
   *  user_nicename
   *  nickname
   *  display_name
   *  first_name
   *  last_name
   *
   * Re-write this method to use your own searching logic if you do not wish
   * to make use of the same web-service.
   *
   * @param string $login
   * @return array
   */
  public function getUserInfo ($login) {
    $xpath = $this->query(array(
      'action' => 'get_user',
      'id' => $login,
    ));
  //   var_dump($xpath->document->saveXML());
    $entries = $xpath->query('/cas:results/cas:entry');
    if ($entries->length < 1) {
      throw new \Exception('Could not get user. Expecting 1 entry, found '.$entries->length, 404);
    }
    else if ($entries->length > 1) {
      throw new \Exception('Could not get user. Expecting 1 entry, found '.$entries->length);
    }

    $entry = $entries->item(0);

    return $this->extractUserInfo($entry, $xpath);
  }

  /**
   * Fetch an array of group ids a user is a member of.
   *
   * Throws an exception if the user isn't found in the underlying data-source.
   *
   * @param string $login
   * @return array
   */
  public function getGroupsForUser ($login) {
    $xpath = $this->query(array(
      'action'  => 'get_user',
      'id'    => $login,
      'include_membership' => 'TRUE',
    ));
    $entries = $xpath->query('/cas:results/cas:entry');
    if ($entries->length < 1) {
      throw new \Exception('Could not get user. Expecting 1 entry, found '.$entries->length, 404);
    }
    else if ($entries->length > 1) {
      throw new \Exception('Could not get user. Expecting 1 entry, found '.$entries->length);
    }

    $groups = [];
    foreach ($xpath->query('cas:attribute[@name="MemberOf"]', $entries->item(0)) as $attribute) {
      $groups[] = $attribute->getAttribute('value');
    }
    return $groups;
  }

  /**
   * Fetch a two-dimensional array user info for every member of a group.
   * Ex:
   *  array(
   *    array(
   *      'user_login' => '1',
   *      'user_email' => 'john.doe@example.com',
   *      ...
   *    ),
   *    array(
   *      'user_login' => '2',
   *      'user_email' => 'jane.doe@example.com',
   *      ...
   *    ),
   *    ...
   *  );
   *
   *
   * Elements:
   *  user_login    The login field that will match or be inserted into the users table.
   *  user_email
   *  user_nicename
   *  nickname
   *  display_name
   *  first_name
   *  last_name
   *
   * @param string $groupId
   * @return array or NULL if group id not found
   */
  public function getGroupMemberInfo ($groupId) {
    $xpath = $this->query(array(
      'action'  => 'get_group_members',
      'id'    => $groupId,
    ));
  //   var_dump($xpath->document->saveXML());
    $memberInfo = array();
    foreach($xpath->query('/cas:results/cas:entry') as $entry) {
      try {
        $memberInfo[] = $this->extractUserInfo($entry, $xpath);
      } catch (\Exception $e) {
        if ($e->getCode() == 65004) {
          // Ignore any groups that we encounter
        } else {
          throw $e;
        }
      }
    }
    return $memberInfo;
  }

  /** Internal Methods **/

  /**
   * Execute a query against our underlying directory service.
   *
   * @param array $parameters
   * @return \DOMXPath
   *
   */
  protected function query(array $parameters) {
    $args = [
      'timeout' => 120,
      'user-agent' => 'WordPress DynamicAddUsers',
    ];
    $args['headers']["Admin-Access"] = $this->getSetting('dynamic_add_users__cas_directory__access_token');
    $response = wp_remote_get($this->getSetting('dynamic_add_users__cas_directory__directory_url') . '?' . http_build_query($parameters), $args);
    if ( !is_array( $response )) {
      throw new Exception('Could not load XML information for '.print_r($parameters, true));
    }
    $xml_string = $response['body'];
    if (!$xml_string || $response['response']['code'] >= 300)
      throw new \Exception('Could not load XML information for '.print_r($parameters, true), $response['response']['code']);
    $doc = new \DOMDocument;
    if (!$doc->loadXML($xml_string))
      throw new \Exception('Could not load XML information for '.print_r($parameters, true), $response['response']['code']);

    $xpath = new \DOMXPath($doc);
    $xpath->registerNamespace('cas', 'http://www.yale.edu/tp/cas');

    return $xpath;
  }

  /**
   * Answer the user info matching a cas:entry element.
   *
   * @param \DOMElement $entry
   * @param \DOMXPath $xpath
   * @return array
   */
  protected function extractUserInfo (\DOMElement $entry, \DOMXPath $xpath) {
    $info = array();
    $info['user_login'] = $this->extractLogin($entry, $xpath);
    $info['user_email'] = $this->extractAttribute('EMail', $entry, $xpath);

    preg_match('/^(.+)@(.+)$/', $info['user_email'], $matches);
    $emailUser = $matches[1];
    $emailDomain = $matches[2];
    if ($login = $this->extractAttribute('Login', $entry, $xpath))
      $nicename = $login;
    else
      $nicename = $emailUser;

    $info['user_nicename'] = $nicename;
    $info['nickname'] = $nicename;
    $info['first_name'] = $this->extractAttribute('FirstName', $entry, $xpath);
    $info['last_name'] = $this->extractAttribute('LastName', $entry, $xpath);
    $info['display_name'] = $info['first_name']." ".$info['last_name'];
    return $info;
  }

  /**
   * Answer the login field for an cas:entry element.
   *
   * @param \DOMElement $entry
   * @param \DOMXPath $xpath
   * @return string
   */
  protected function extractLogin (\DOMElement $entry, \DOMXPath $xpath) {
    $elements = $xpath->query('./cas:user', $entry);
    if ($elements->length !== 1) {
      if ($xpath->query('./cas:group', $entry)->length)
        throw new \Exception('Could not get user login. Expecting one cas:user element, found a cas:group instead.', 65004);
      else
        throw new \Exception('Could not get user login. Expecting one cas:user element, found '.$elements->length.'.');
    }
    return $elements->item(0)->nodeValue;
  }

  /**
   * Answer the login field for an cas:entry element.
   *
   * @param string $attribute
   * @param \DOMElement $entry
   * @param \DOMXPath $xpath
   * @return string
   */
  protected function extractAttribute ($attribute, \DOMElement $entry, \DOMXPath $xpath) {
    $elements = $xpath->query('./cas:attribute[@name = "'.$attribute.'"]', $entry);
    if (!$elements->length)
      return '';
    return $elements->item(0)->getAttribute('value');
  }

  /**
   * Answer the group id field for an cas:entry element.
   *
   * @param \DOMElement $entry
   * @param \DOMXPath $xpath
   * @return string
   */
  protected function extractGroupId (\DOMElement $entry, \DOMXPath $xpath) {
    $elements = $xpath->query('./cas:group', $entry);
    if ($elements->length !== 1)
      throw new \Exception('Could not get group id. Expecting one cas:group element, found '.$elements->length.'.');
    return $elements->item(0)->nodeValue;
  }

  /**
   * Answer the group display name for an cas:entry element.
   *
   * @param \DOMElement $entry
   * @param \DOMXPath $xpath
   * @return string
   */
  protected function extractGroupDisplayName (\DOMElement $entry, \DOMXPath $xpath) {
    $displayName = $this->extractAttribute('DisplayName', $entry, $xpath);
    $id = $this->extractGroupId($entry, $xpath);
    $displayName .= " (" . self::convertDnToDisplayPath($id) . ")";

    return $displayName;
  }

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
  public function getSettings() {
    return [
      'dynamic_add_users__cas_directory__directory_url' => [
        'label' => 'Directory URL',
        'description' => 'URL of the CAS Directory web service. Example: https://login.middlebury.edu/directory/',
        'value' => $this->getSetting('dynamic_add_users__cas_directory__directory_url'),
        'type' => 'text',
      ],
      'dynamic_add_users__cas_directory__access_token' => [
        'label' => 'Access Token',
        'description' => 'The access token passed to the CAS Directory web service in an Admin-Access header.',
        'value' => $this->getSetting('dynamic_add_users__cas_directory__access_token'),
        'type' => 'password',
      ],
    ];
  }

  /**
   * Validate the settings and return an array of error messages.
   *
   * @return array
   *   Any error messages for settings. If empty, settings are validated.
   */
  public function checkSettings() {
    $messages = [];
    if (!filter_var($this->getSetting('dynamic_add_users__cas_directory__directory_url'), FILTER_VALIDATE_URL, FILTER_FLAG_PATH_REQUIRED)) {
      $messages[] = 'Directory URL must be a valid URL with path. \'' . $this->getSetting('dynamic_add_users__cas_directory__directory_url') . '\' given.';
    }
    if (empty($this->getSetting('dynamic_add_users__cas_directory__access_token'))) {
      $messages[] = 'The Access Token must be specified.';
    }
    return $messages;
  }

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
  public function getTestArguments() {
    return [
      'query' => [
        'label' => 'Query',
        'description' => 'Query to send to the the directory service for user search/lookup or group search. Values should be: <dl><dt>User Lookup:</dt><dd>An external user-id</dd><dt>User Search:</dt><dd>A partial name/email.</dd><dt>Group Lookup:</dt><dd>A Group Id</dd><dt>Group Search:</dt><dd>A partial group name.</dd></dl>',
        'value' => '',
        'type' => 'text',
      ],
      'query_type' => [
        'label' => 'Query Type',
        'description' => 'What type of search to do.',
        'value' => 'user_search',
        'type' => 'select',
        'options' => [
          'user_lookup' => 'User Lookup',
          'user_search' => 'User Search',
          'group_lookup' => 'Group Lookup',
          'group_search' => 'Group Search',
        ],
      ],
    ];
  }

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
  public function testSettings(array $arguments = []) {
    $messages = [];

    // test the setting existance.
    $checkSettingsMessages = $this->checkSettings();
    if (empty($checkSettingsMessages)) {
      $messages[] = [
        'success' => TRUE,
        'message' => 'Settings have the correct format for Directory URL and Access Token.',
      ];
    }
    else {
      foreach ($checkSettingsMessages as $checkSettingsMessage) {
        $messages[] = [
          'success' => FALSE,
          'message' => $checkSettingsMessage,
        ];
      }
    }

    if (empty($arguments['query'])) {
      $messages[] = [
        'success' => FALSE,
        'message' => 'You must specify a query to execute this test.',
      ];
      return $messages;
    }

    switch($arguments['query_type']) {
      case 'user_lookup':
        try {
          $info = $this->getUserInfo($arguments['query']);
          $messages[] = [
            'success' => TRUE,
            'message' => 'Found user info for "' . esc_html($arguments['query']) . '": <pre>' . esc_html(print_r($info, true)) . '</pre>',
          ];
        }
        catch (Exception $e) {
          $messages[] = [
            'success' => FALSE,
            'message' => 'Failed to lookup user info for "' . esc_html($arguments['query']) . '" Code: ' . $e->getCode() . ' Message: ' . esc_html($e->getMessage()),
          ];
        }
        try {
          $groups = $this->getGroupsForUser($arguments['query']);
          $messages[] = [
            'success' => TRUE,
            'message' => 'Found groups for "' . esc_html($arguments['query']) . '": <pre>' . esc_html(print_r($groups, true)) . '</pre>',
          ];
        }
        catch (Exception $e) {
          $messages[] = [
            'success' => FALSE,
            'message' => 'Failed to lookup user groups for "' . esc_html($arguments['query']) . '" Code: ' . $e->getCode() . ' Message: ' . esc_html($e->getMessage()),
          ];
        }
        break;
      case 'user_search':
        try {
          $infos = $this->getUsersBySearchFromDirectory($arguments['query']);
          $messages[] = [
            'success' => TRUE,
            'message' => 'Found users matching "' . esc_html($arguments['query']) . '": <pre>' . esc_html(print_r($infos, true)) . '</pre>',
          ];
        }
        catch (Exception $e) {
          $messages[] = [
            'success' => FALSE,
            'message' => 'Failed to search users matching "' . esc_html($arguments['query']) . '" Code: ' . $e->getCode() . ' Message: ' . esc_html($e->getMessage()),
          ];
        }
        break;
      case 'group_lookup':
        try {
          $info = $this->getGroupMemberInfo($arguments['query']);
          $messages[] = [
            'success' => TRUE,
            'message' => 'Found user info for members of "' . esc_html($arguments['query']) . '": <pre>' . esc_html(print_r($info, true)) . '</pre>',
          ];
        }
        catch (Exception $e) {
          $messages[] = [
            'success' => FALSE,
            'message' => 'Failed to lookup group members for "' . esc_html($arguments['query']) . '" Code: ' . $e->getCode() . ' Message: ' . esc_html($e->getMessage()),
          ];
        }
        break;
      case 'group_search':
        try {
          $groups = $this->getGroupsBySearchFromDirectory($arguments['query']);
          $messages[] = [
            'success' => TRUE,
            'message' => 'Found groups matching "' . esc_html($arguments['query']) . '": <pre>' . esc_html(print_r($groups, true)) . '</pre>',
          ];
        }
        catch (Exception $e) {
          $messages[] = [
            'success' => FALSE,
            'message' => 'Failed to search groups matching "' . esc_html($arguments['query']) . '" Code: ' . $e->getCode() . ' Message: ' . esc_html($e->getMessage()),
          ];
        }
        break;

      default:
        $messages[] = [
          'success' => FALSE,
          'message' => 'Unknown Query Type: "' . esc_html($arguments['query_type']) .'"',
        ];
    }

    return $messages;
  }

}
