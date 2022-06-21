<?php

namespace DynamicAddUsers\Directory;

use DynamicAddUsers\Directory\DirectoryInterface;
use DynamicAddUsers\Directory\DirectoryBase;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ClientException as HttpClientException;
use Microsoft\Graph\Graph;
use Microsoft\Graph\Model\Group;
use Microsoft\Graph\Model\OpenTypeExtension;
use Microsoft\Graph\Model\Site;
use Microsoft\Graph\Model\User;
use Exception;

/**
 * A Directory implementation backed by Microsoft's Graph API and Azure AD.
 */
class MicrosoftGraphDirectory extends DirectoryBase implements DirectoryInterface
{

  /**
   * Answer an identifier for this implementation.
   *
   * @return string
   *   The implementation id.
   */
  public static function id() {
    return 'microsoft_graph';
  }

  /**
   * Answer a label for this implementation.
   *
   * @return string
   *   The implementation label.
   */
  public static function label() {
    return 'Microsoft Graph';
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
    $matches = [];

    $path = "/users";
    $path .= "?\$filter=startswith(displayName, '" . urlencode($search) ."') or startswith(givenName, '" . urlencode($search) ."') or startswith(surname, '" . urlencode($search) ."') or startswith(mail, '" . urlencode($search) ."')&\$count=true&\$top=10&\$orderby=displayName&\$select=id,displayName,mail,givenName,surname,userPrincipalName,extension_a5f5e158fc8b49ce98aa89ab99fd2a76_middleburyCollegeUID";

    // print_r($path);

    $result = $this->getGraph()
      ->createRequest("GET", $path)
      ->addHeaders(['ConsistencyLevel' => 'eventual'])
      ->setReturnType(User::class)
      ->execute();
    if (is_array($result)) {
      foreach ($result as $user) {
        $matches[$user->getId()] = $this->extractUserInfo($user);
      }
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
    $matches = [];

    $path = "/groups";
    $path .= "?\$filter=startswith(displayName, '" . urlencode($search) ."') or startswith(mail, '" . urlencode($search) ."')&\$count=true&\$top=10&\$orderby=displayName&\$select=id,displayName,mail,description,groupTypes";

    // print_r($path);

    $result = $this->getGraph()
      ->createRequest("GET", $path)
      ->addHeaders(['ConsistencyLevel' => 'eventual'])
      ->setReturnType(Group::class)
      ->execute();
    if (is_array($result)) {
      foreach ($result as $group) {
        // print_r($group);
        $matches[$group->getId()] = $group->getDisplayName();
        if ($group->getDescription()) {
          $matches[$group->getId()] .= ' (' . $group->getDescription() . ')';
        }
      }
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
    // First search by MiddleburyCollegeUID.
    $path = "/users?\$filter=extension_a5f5e158fc8b49ce98aa89ab99fd2a76_middleburyCollegeUID eq '" . urlencode($login) ."'&\$count=true&\$top=10&\$orderby=displayName&\$select=id,displayName,mail,givenName,surname,userPrincipalName,extension_a5f5e158fc8b49ce98aa89ab99fd2a76_middleburyCollegeUID";
    $result = $this->getGraph()
      ->createRequest("GET", $path)
      ->addHeaders(['ConsistencyLevel' => 'eventual'])
      ->setReturnType(User::class)
      ->execute();

    // If not found and the login ends in 'ext', search on userPrincipalName.
    if (empty($result) && preg_match('/ext$/i', $login)) {
      $upn = preg_replace('/^(.+)ext$/i', '\1#EXT#@middleburycollege.onmicrosoft.com', $login);
      $path = "/users?\$filter=userPrincipalName eq '" . urlencode($upn) ."'&\$count=true&\$top=10&\$orderby=displayName&\$select=id,displayName,mail,givenName,surname,userPrincipalName,extension_a5f5e158fc8b49ce98aa89ab99fd2a76_middleburyCollegeUID";
      $result = $this->getGraph()
        ->createRequest("GET", $path)
        ->addHeaders(['ConsistencyLevel' => 'eventual'])
        ->setReturnType(User::class)
        ->execute();
    }

    if (count($result) < 1) {
      throw new \Exception('Could not get user. Expecting 1 entry, found '.count($result), 404);
    }
    else if (count($result) > 1) {
      throw new \Exception('Could not get user. Expecting 1 entry, found '.count($result));
    }

    return $this->extractUserInfo($result[0]);
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
    $memberInfo = [];

    $path = "/groups/" . urlencode($groupId) . "/transitiveMembers" ;
    $path .= "?\$select=id,displayName,mail,givenName,surname,userPrincipalName,extension_a5f5e158fc8b49ce98aa89ab99fd2a76_middleburyCollegeUID";

    $result = $this->getGraph()
      ->createRequest("GET", $path)
      ->setReturnType(User::class)
      ->execute();
    if (is_array($result)) {
      foreach ($result as $user) {
        $memberInfo[$user->getId()] = $this->extractUserInfo($user);
      }
    }

    return $memberInfo;
  }

  /** Internal Methods **/

  /**
   * @var \GuzzleHttp\Client $httpClient
   *   An HTTP Client for web service requests.
   */
  protected $httpClient;

  /**
   * Answer our HttpClient
   *
   * @return \GuzzleHttp\Client
   */
  protected function httpClient() {
    if (!isset($this->httpClient)) {
      $this->httpClient = new HttpClient();
    }
    return $this->httpClient;
  }

  /**
   * @var string $token
   *  The access token to use for requests.
   */
  protected $token;

  /**
   * Get an O365 Access token.
   */
  public function getAccessToken() {
    if (empty($this->token)) {
      $tenantId = $this->getSetting('dynamic_add_users__microsoft_graph__tenant_id');
      if (empty($tenantId)) {
        throw new \Exception('The Tenant ID must be configured.');
      }
      $appId = $this->getSetting('dynamic_add_users__microsoft_graph__application_id');
      if (empty($appId)) {
        throw new \Exception('The Application ID must be configured.');
      }
      $appSecret = $this->getSetting('dynamic_add_users__microsoft_graph__application_secret');
      if (empty($appSecret)) {
        throw new \Exception('The Application Secret must be configured.');
      }

      try {
        $url = 'https://login.microsoftonline.com/' . $tenantId . '/oauth2/v2.0/token';
        $response = $this->httpClient()->post($url, [
          'form_params' => [
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
          ],
        ]);
        $token_response = json_decode($response->getBody()->getContents());
        $this->token = $token_response->access_token;
      }
      catch (HttpClientException $e) {
        if ($e->hasResponse()) {
          $response = $e->getResponse();
          $response_info = json_decode($response->getBody()->getContents());
          throw new \Exception($response_info->error . ': ' . $response_info->error_description);
        }
        throw $e;
      }
    }
    return $this->token;
  }

  /**
   * @var \Microsoft\Graph\Graph $graph
   *  Answer our MS Graph API.
   */
  protected $graph;

  /**
   * Answer our already-configured O365 API.
   *
   * @return \Microsoft\Graph\Graph
   *   The Graph object.
   */
  public function getGraph() {
    if (empty($this->graph)) {
      $this->graph = new Graph();
      $this->graph->setAccessToken($this->getAccessToken());
    }
    return $this->graph;
  }

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
   * Answer the user info matching an MS Graph User object.
   *
   * @param \Microsoft\Graph\Model\User $user
   * @return array
   */
  protected function extractUserInfo (User $user) {
    $info = array();
    $properties = $user->getProperties();
    // print_r($properties);
    if (empty($properties['extension_a5f5e158fc8b49ce98aa89ab99fd2a76_middleburyCollegeUID'])) {
      $login = preg_replace('/@middleburycollege.onmicrosoft.com$/', '', $user->getUserPrincipalName());
    } else {
      $login = $properties['extension_a5f5e158fc8b49ce98aa89ab99fd2a76_middleburyCollegeUID'];
    }
    $info['user_login'] = $login;
    $info['user_email'] = $user->getMail();

    preg_match('/^(.+)@(.+)$/', $info['user_email'], $matches);
    $emailUser = $matches[1];
    $emailDomain = $matches[2];

    $info['user_nicename'] = $emailUser;
    $info['nickname'] = $user->getGivenName();
    $info['first_name'] = $user->getGivenName();
    $info['last_name'] = $user->getSurname();
    $info['display_name'] = $user->getGivenName()." ".$user->getSurname();
    if (empty($info['display_name'])) {
      if (!empty($user->getDisplayName())) {
        $info['display_name'] = $user->getDisplayName();
      } else {
        $info['display_name'] = $user->getUserPrincipalName();
      }
    }
    if (empty($info['nickname'])) {
      $info['display_name'] = $info['display_name'];
    }
    return $info;
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
      'dynamic_add_users__microsoft_graph__tenant_id' => [
        'label' => 'Tenant ID',
        'description' => 'The tenant id to communicate with.',
        'value' => $this->getSetting('dynamic_add_users__microsoft_graph__tenant_id'),
        'type' => 'text',
      ],
      'dynamic_add_users__microsoft_graph__application_id' => [
        'label' => 'Application ID',
        'description' => 'The application id to authenticate as.',
        'value' => $this->getSetting('dynamic_add_users__microsoft_graph__application_id'),
        'type' => 'text',
      ],
      'dynamic_add_users__microsoft_graph__application_secret' => [
        'label' => 'Application Secret',
        'description' => 'The password or secret used to authenticate with.',
        'value' => $this->getSetting('dynamic_add_users__microsoft_graph__application_secret'),
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
    if (empty($this->getSetting('dynamic_add_users__microsoft_graph__tenant_id'))) {
      $messages[] = 'The Tennant ID must be specified.';
    }
    if (empty($this->getSetting('dynamic_add_users__microsoft_graph__application_id'))) {
      $messages[] = 'The Application ID must be specified.';
    }
    if (empty($this->getSetting('dynamic_add_users__microsoft_graph__application_secret'))) {
      $messages[] = 'The Application Secret must be specified.';
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
        'message' => 'Settings have the correct format for Tenant ID, Application ID, and Application Secret.',
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
