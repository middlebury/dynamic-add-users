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

  /**
   * Answer a description for this implementation.
   *
   * @return string
   *   The description text.
   */
  public static function description() {
    return "This implementation looks up users and groups against <a href=\"https://docs.microsoft.com/en-us/graph/api/overview?view=graph-rest-1.0\">Microsoft's Graph API</a>. Groups are referenced by their ID property in AzureAD (a GUID value like <code>20f74211-e62b-40f9-b00a-513a01a2e431</code>). Users are referenced by a configurable primary identifier attribute and an optional secondary/fall-back identifier.";
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
    $path .= "?\$filter=startswith(displayName, '" . urlencode($search) ."') or startswith(givenName, '" . urlencode($search) ."') or startswith(surname, '" . urlencode($search) ."') or startswith(mail, '" . urlencode($search) ."')"
      . " &\$count=true&\$top=10&\$orderby=displayName"
      . " &\$select=".implode(',', $this->getUserGraphProperties());

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
  protected function getGroupsBySearchFromDirectory ($search) {
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
        if ($group->getDescription() && $group->getDescription() != $group->getDisplayName()) {
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
    return $this->extractUserInfo($this->fetchUserForLogin($login));
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
    $user = $this->fetchUserForLogin($login);
    $path = "/users/".$user->getId()."/transitiveMemberOf?\$select=id,displayName,mail,description,groupTypes";
    $result = $this->getGraph()
      ->createRequest("GET", $path)
      ->addHeaders(['ConsistencyLevel' => 'eventual'])
      ->setReturnType(Group::class)
      ->execute();
    $groups = [];
    if (is_array($result)) {
      foreach ($result as $group) {
        $groups[$group->getId()] = $group->getDisplayName();
        if ($group->getDescription()) {
          $groups[$group->getId()] .= ' (' . $group->getDescription() . ')';
        }
      }
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
    $path .= "?\$select=" . implode(',', $this->getUserGraphProperties());

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
   * Answer an MS Graph User object matching a login string.
   *
   * @param string $login
   * @return Microsoft\Graph\Model\User
   */
  protected function fetchUserForLogin($login) {
    // First search by the primary unique ID.
    try {
      return $this->fetchUserByProperty($this->getPrimaryUniqueIdProperty(), $this->getPrimaryUniqueIdFromLogin($login));
    } catch (\Exception $e) {
      // If we didn't find an account based on the primary id, try a secondary ID if configured.
      if ($e->getCode() == 404 && !empty($this->getSecondaryUniqueIdProperty())) {
        return $this->fetchUserByProperty($this->getSecondaryUniqueIdProperty(), $this->getSecondaryUniqueIdFromLogin($login));
      } else {
        // If we don't support secondary ids or get another error, just throw it.
        throw $e;
      }
    }
  }

  /**
   * Answer an MS Graph User object matching a login string.
   *
   * @param string $property
   *   The MSGraph property to match.
   * @param string $value
   *   The user-id value to match.
   * @return Microsoft\Graph\Model\User
   */
  protected function fetchUserByProperty($property, $value) {
    $path = "/users?\$filter=" . $property . " eq '" . urlencode($value) . "'"
      . "&\$count=true&\$top=10&\$orderby=displayName"
      . "&\$select=" . implode(",", $this->getUserGraphProperties());
    $result = $this->getGraph()
      ->createRequest("GET", $path)
      ->addHeaders(['ConsistencyLevel' => 'eventual'])
      ->setReturnType(User::class)
      ->execute();

    if (count($result) < 1) {
      throw new \Exception('Could not get user. Expecting 1 entry, found '.count($result), 404);
    }
    else if (count($result) > 1) {
      throw new \Exception('Could not get user. Expecting 1 entry, found '.count($result));
    }

    return $result[0];
  }

  protected function getUserGraphProperties() {
    $properties = [
      'id',
      'displayName',
      'mail',
      'givenName',
      'surname',
      $this->getPrimaryUniqueIdProperty(),
    ];
    if (!empty($this->getSecondaryUniqueIdProperty())) {
      $properties .= $this->getSecondaryUniqueIdProperty();
    }
    return $properties;
  }

  /**
   * Answer the user info matching an MS Graph User object.
   *
   * @param \Microsoft\Graph\Model\User $user
   * @return array
   */
  protected function extractUserInfo (User $user) {
    $info = array();

    $info['user_login'] = $this->getLoginForGraphUser($user);
    $info['user_email'] = $user->getMail();

    preg_match('/^(.+)@(.+)$/', $info['user_email'], $matches);
    $emailUser = $matches[1];
    $emailDomain = $matches[2];

    $info['user_nicename'] = $emailUser;
    $info['nickname'] = $user->getGivenName();
    $info['first_name'] = $user->getGivenName();
    $info['last_name'] = $user->getSurname();
    $info['display_name'] = trim($user->getGivenName()." ".$user->getSurname());
    if (empty($info['display_name'])) {
      $info['display_name'] = trim($user->getDisplayName());
    }
    if (empty($info['display_name'])) {
      $info['display_name'] = $emailUser;
    }
    if (empty($info['nickname'])) {
      $info['nickname'] = $emailUser;
    }
    return $info;
  }

  /**
   * Answer the user login matching an MS Graph User object.
   *
   * @param \Microsoft\Graph\Model\User $user
   * @return array
   */
  protected function getLoginForGraphUser (User $user) {
    $properties = $user->getProperties();
    // print_r($properties);

    // Primary Unique ID.
    if (!empty($properties[$this->getPrimaryUniqueIdProperty()])) {
      $login = $properties[$this->getPrimaryUniqueIdProperty()];
      // Strip a suffix if configured.
      if (in_array($this->getSetting('dynamic_add_users__primary_unique_id__transform'), ['strip_suffix', 'strip_suffix_and_convert_ext'])) {
        $login = preg_replace('/' . preg_quote($this->getSetting('dynamic_add_users__primary_unique_id__suffix')) . '$/', '', $login);
      }
      // Convert /#EXT#$/ to 'ext' if configured.
      if ($this->getSetting('dynamic_add_users__primary_unique_id__transform') == 'strip_suffix_and_convert_ext') {
        $login = preg_replace('/#EXT#$/', 'ext', $login);
      }

      if (empty($login)) {
        throw new \Exception('Primary Unique ID "' . $properties[$this->getPrimaryUniqueIdProperty()] . '" in the ' . $this->getPrimaryUniqueIdProperty() . ' property resulted in an empty login after transform.');
      }
      return $login;
    }
    // Secondary/Fallback unique ID.
    else {
      $login = $properties[$this->getSecondaryUniqueIdProperty()];
      // Strip a suffix if configured.
      if (in_array($this->getSetting('dynamic_add_users__secondary_unique_id__transform'), ['strip_suffix', 'strip_suffix_and_convert_ext'])) {
        $login = preg_replace('/' . preg_quote($this->getSetting('dynamic_add_users__secondary_unique_id__suffix')) . '$/', '', $login);
      }
      // Convert /#EXT#$/ to 'ext' if configured.
      if ($this->getSetting('dynamic_add_users__secondary_unique_id__transform') == 'strip_suffix_and_convert_ext') {
        $login = preg_replace('/#EXT#$/', 'ext', $login);
      }

      if (empty($login)) {
        throw new \Exception('Secondary Unique ID "' . $properties[$this->getSecondaryUniqueIdProperty()] . '" in the ' . $this->getSecondaryUniqueIdProperty() . ' property resulted in an empty login after transform.');
      }
      return $login;
    }
  }

  /**
   * Answer a primary user-id value to lookup in Graph for a WordPress login.
   *
   * @param string $login
   *   The WordPress login.
   *
   * @return string
   *   A transformed ID to use in MS Graph lookups.
   */
  protected function getPrimaryUniqueIdFromLogin($login) {
    // Append a suffix that we would have stripped if configured.
    if ($this->getSetting('dynamic_add_users__primary_unique_id__transform') == 'strip_suffix') {
      return $login . $this->getSetting('dynamic_add_users__primary_unique_id__suffix');
    }
    // Convert /ext$/ to '#EXT#' and append a suffix that we would have stripped if configured.
    if ($this->getSetting('dynamic_add_users__primary_unique_id__transform') == 'strip_suffix_and_convert_ext') {
      return preg_replace('/^(.+)ext$/i', '\1#EXT#' . $this->getSetting('dynamic_add_users__primary_unique_id__suffix'), $login);
    }
    // Return the login unmodified.
    else {
      return $login;
    }
  }

  /**
   * Answer the primary unique-id property key.
   *
   * @return string
   *   The property in MS Graph that holds the primary unique-id.
   */
  protected function getPrimaryUniqueIdProperty() {
    return $this->getSetting('dynamic_add_users__primary_unique_id__property');
  }

  /**
   * Answer the secondary unique-id property key.
   *
   * @return string
   *   The property in MS Graph that holds a secondary/fall-back unique-id.
   */
  protected function getSecondaryUniqueIdProperty() {
    return $this->getSetting('dynamic_add_users__secondary_unique_id__property');
  }

  /**
   * Answer a secondary user-id value to lookup in Graph for a WordPress login.
   *
   * @param string $login
   *   The WordPress login.
   *
   * @return string
   *   A transformed ID to use in MS Graph lookups.
   */
  protected function getSecondaryUniqueIdFromLogin($login) {
    // Append a suffix that we would have stripped if configured.
    if ($this->getSetting('dynamic_add_users__secondary_unique_id__transform') == 'strip_suffix') {
      return $login . $this->getSetting('dynamic_add_users__secondary_unique_id__suffix');
    }
    // Convert /ext$/ to '#EXT#' and append a suffix that we would have stripped if configured.
    if ($this->getSetting('dynamic_add_users__secondary_unique_id__transform') == 'strip_suffix_and_convert_ext') {
      return preg_replace('/^(.+)ext$/i', '\1#EXT#' . $this->getSetting('dynamic_add_users__secondary_unique_id__suffix'), $login);
    }
    // Return the login unmodified.
    else {
      return $login;
    }
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
      'dynamic_add_users__primary_unique_id__property' => [
        'label' => 'Primary Unique ID property',
        'description' => 'If specified, this property key will be used as the primary ID for user accounts and checked first. Example: <code>extension_a5f5e158fc8b49ce98aa89ab99fd2a76_middleburyCollegeUID</code>',
        'value' => $this->getSetting('dynamic_add_users__primary_unique_id__property'),
        'type' => 'text',
      ],
      'dynamic_add_users__primary_unique_id__transform' => [
        'label' => 'Primary Unique ID transform',
        'description' => 'Select a transform (if any) for the primary unique id',
        'value' => $this->getSetting('dynamic_add_users__primary_unique_id__transform'),
        'type' => 'select',
        'options' => [
          'none' => 'None',
          'strip_suffix' => 'Strip suffix',
          'strip_suffix_and_convert_ext' => 'Strip suffix and convert #EXT#',
        ],
      ],
      'dynamic_add_users__primary_unique_id__suffix' => [
        'label' => 'Primary Unique ID suffix',
        'description' => 'If specified and one of the "Strip suffix" is chosen, this suffix will be stripped from the unique-id to store the shorter version as the WordPress username, then added to the username when doing lookups in the directory. WordPress limits user logins to 60 characters, so long email addresses or UPNs may need to have a suffix converted. <br><br> Example: <ul><li>Use "Strip suffix and convert #EXT#" with a suffix of <code>@middleburycollege.onmicrosoft.com</code> to convert a UPN like <code>johndoe_example.com#EXT#@middleburycollege.onmicrosoft.com</code> to and from a WordPress username like <code>johndoe_example.comext</code></li><li>Use "Strip suffix" with a suffix of "@middlebury.edu" to convert a UPN like <code>johndoe@middlebury.edu</code> to and from a WordPress username like <code>johndoe</code></li></ul>',
        'value' => $this->getSetting('dynamic_add_users__primary_unique_id__suffix'),
        'type' => 'text',
      ],
      'dynamic_add_users__secondary_unique_id__property' => [
        'label' => 'Secondary Unique ID property',
        'description' => 'If specified, this property key will be used as the secondary ID for user accounts and used as a fall-back if the user account does not have the primary ID property. For example, if an institutional ID property is used as the primary ID, then guest accounts without this institutional id might fall back to the userPrincipalName as a secondary ID. Example: <code>userPrincipalName</code>',
        'value' => $this->getSetting('dynamic_add_users__secondary_unique_id__property'),
        'type' => 'text',
      ],
      'dynamic_add_users__secondary_unique_id__transform' => [
        'label' => 'Secondary Unique ID transform',
        'description' => 'Select a transform (if any) for the secondary unique id',
        'value' => $this->getSetting('dynamic_add_users__secondary_unique_id__transform'),
        'type' => 'select',
        'options' => [
          'none' => 'None',
          'strip_suffix' => 'Strip Suffix',
          'strip_suffix_and_convert_ext' => 'Strip suffix and convert #EXT#',
        ],
      ],
      'dynamic_add_users__secondary_unique_id__suffix' => [
        'label' => 'Secondary Unique ID suffix',
        'description' => 'If specified and one of the "Strip suffix" is chosen, this suffix will be stripped from the unique-id to store the shorter version as the WordPress username, then added to the username when doing lookups in the directory. WordPress limits user logins to 60 characters, so long email addresses or UPNs may need to have a suffix converted. <br><br> Example: <ul><li>Use "Strip suffix and convert #EXT#" with a suffix of <code>@middleburycollege.onmicrosoft.com</code> to convert a UPN like <code>johndoe_example.com#EXT#@middleburycollege.onmicrosoft.com</code> to and from a WordPress username like <code>johndoe_example.comext</code></li><li>Use "Strip suffix" with a suffix of "@middlebury.edu" to convert a UPN like <code>johndoe@middlebury.edu</code> to and from a WordPress username like <code>johndoe</code></li></ul>',
        'value' => $this->getSetting('dynamic_add_users__secondary_unique_id__suffix'),
        'type' => 'text',
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
    if (empty($this->getSetting('dynamic_add_users__primary_unique_id__property'))) {
      $messages[] = 'The Primary Unique ID property must be specified.';
    }
    if (!empty($this->getSetting('dynamic_add_users__primary_unique_id__transform')) && $this->getSetting('dynamic_add_users__primary_unique_id__transform') != 'none' && empty($this->getSetting('dynamic_add_users__primary_unique_id__suffix'))) {
      $messages[] = 'If you are specifying that a suffix from the Primary Unique ID will be stripped you must be specify it.';
    }
    if (empty($this->getSetting('dynamic_add_users__secondary_unique_id__property'))) {
      $messages[] = 'The secondary Unique ID property must be specified.';
    }
    if (!empty($this->getSetting('dynamic_add_users__secondary_unique_id__transform')) && $this->getSetting('dynamic_add_users__secondary_unique_id__transform') != 'none' && empty($this->getSetting('dynamic_add_users__secondary_unique_id__suffix'))) {
      $messages[] = 'If you are specifying that a suffix from the secondary Unique ID will be stripped you must be specify it.';
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
          $externalId = $this->getPrimaryUniqueIdFromLogin($arguments['query']);
          $messages[] = [
            'success' => TRUE,
            'message' => 'Converted user id from <code>' . esc_html($arguments['query']) . '</code> to <code>' . esc_html($this->getPrimaryUniqueIdFromLogin($arguments['query'])) . '</code> to match against primary property: <code>' . $this->getPrimaryUniqueIdProperty() . '</code>',
          ];
        }
        catch (Exception $e) {
          $messages[] = [
            'success' => FALSE,
            'message' => 'Failed to lookup user info for "' . esc_html($arguments['query']) . '" Code: ' . $e->getCode() . ' Message: ' . esc_html($e->getMessage()),
          ];
        }
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
