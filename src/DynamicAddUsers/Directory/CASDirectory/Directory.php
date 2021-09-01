<?php

namespace DynamicAddUsers\Directory\CASDirectory;

require_once( dirname(__FILE__) . '/../DirectoryInterface.php' );
require_once( dirname(__FILE__) . '/../DirectoryBase.php' );

use DynamicAddUsers\Directory\DirectoryInterface;
use DynamicAddUsers\Directory\DirectoryBase;
use Exception;

/**
 *
 */
class Directory extends DirectoryBase implements DirectoryInterface
{

  /**
   * @var string $directoryUrl
   *   The directory URL to use for user-information lookup.
   */
  protected $directoryUrl;

  /**
   * @var string $accessToken
   *   The access token to use for user-information lookup.
   */
  protected $accessToken;

  public function __construct($directoryUrl, $accessToken) {
    if (!filter_var($directoryUrl, FILTER_VALIDATE_URL, FILTER_FLAG_PATH_REQUIRED)) {
      throw new \InvalidArgumentException('$directoryUrl must be a valid URL with path. \'' . $directoryUrl . '\' given.');
    }
    if (empty($accessToken)) {
      throw new \InvalidArgumentException('$accessToken must be specified.');
    }

    $this->directoryUrl = $directoryUrl;
    $this->accessToken = $accessToken;
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
  public function getGroupsBySearch ($search) {
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
    $args['headers']["Admin-Access"] = $this->accessToken;
    $response = wp_remote_get($this->directoryUrl . '?' . http_build_query($parameters), $args);
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

}
