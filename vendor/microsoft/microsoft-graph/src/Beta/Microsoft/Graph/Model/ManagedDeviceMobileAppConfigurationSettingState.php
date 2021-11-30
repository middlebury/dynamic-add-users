<?php
/**
* Copyright (c) Microsoft Corporation.  All Rights Reserved.  Licensed under the MIT License.  See License in the project root for license information.
*
* ManagedDeviceMobileAppConfigurationSettingState File
* PHP version 7
*
* @category  Library
* @package   Microsoft.Graph
* @copyright (c) Microsoft Corporation. All rights reserved.
* @license   https://opensource.org/licenses/MIT MIT License
* @link      https://graph.microsoft.com
*/
namespace Beta\Microsoft\Graph\Model;
/**
* ManagedDeviceMobileAppConfigurationSettingState class
*
* @category  Model
* @package   Microsoft.Graph
* @copyright (c) Microsoft Corporation. All rights reserved.
* @license   https://opensource.org/licenses/MIT MIT License
* @link      https://graph.microsoft.com
*/
class ManagedDeviceMobileAppConfigurationSettingState extends Entity
{
    /**
    * Gets the currentValue
    * Current value of setting on device
    *
    * @return string|null The currentValue
    */
    public function getCurrentValue()
    {
        if (array_key_exists("currentValue", $this->_propDict)) {
            return $this->_propDict["currentValue"];
        } else {
            return null;
        }
    }

    /**
    * Sets the currentValue
    * Current value of setting on device
    *
    * @param string $val The value of the currentValue
    *
    * @return ManagedDeviceMobileAppConfigurationSettingState
    */
    public function setCurrentValue($val)
    {
        $this->_propDict["currentValue"] = $val;
        return $this;
    }
    /**
    * Gets the errorCode
    * Error code for the setting
    *
    * @return int|null The errorCode
    */
    public function getErrorCode()
    {
        if (array_key_exists("errorCode", $this->_propDict)) {
            return $this->_propDict["errorCode"];
        } else {
            return null;
        }
    }

    /**
    * Sets the errorCode
    * Error code for the setting
    *
    * @param int $val The value of the errorCode
    *
    * @return ManagedDeviceMobileAppConfigurationSettingState
    */
    public function setErrorCode($val)
    {
        $this->_propDict["errorCode"] = $val;
        return $this;
    }
    /**
    * Gets the errorDescription
    * Error description
    *
    * @return string|null The errorDescription
    */
    public function getErrorDescription()
    {
        if (array_key_exists("errorDescription", $this->_propDict)) {
            return $this->_propDict["errorDescription"];
        } else {
            return null;
        }
    }

    /**
    * Sets the errorDescription
    * Error description
    *
    * @param string $val The value of the errorDescription
    *
    * @return ManagedDeviceMobileAppConfigurationSettingState
    */
    public function setErrorDescription($val)
    {
        $this->_propDict["errorDescription"] = $val;
        return $this;
    }
    /**
    * Gets the instanceDisplayName
    * Name of setting instance that is being reported.
    *
    * @return string|null The instanceDisplayName
    */
    public function getInstanceDisplayName()
    {
        if (array_key_exists("instanceDisplayName", $this->_propDict)) {
            return $this->_propDict["instanceDisplayName"];
        } else {
            return null;
        }
    }

    /**
    * Sets the instanceDisplayName
    * Name of setting instance that is being reported.
    *
    * @param string $val The value of the instanceDisplayName
    *
    * @return ManagedDeviceMobileAppConfigurationSettingState
    */
    public function setInstanceDisplayName($val)
    {
        $this->_propDict["instanceDisplayName"] = $val;
        return $this;
    }
    /**
    * Gets the setting
    * The setting that is being reported
    *
    * @return string|null The setting
    */
    public function getSetting()
    {
        if (array_key_exists("setting", $this->_propDict)) {
            return $this->_propDict["setting"];
        } else {
            return null;
        }
    }

    /**
    * Sets the setting
    * The setting that is being reported
    *
    * @param string $val The value of the setting
    *
    * @return ManagedDeviceMobileAppConfigurationSettingState
    */
    public function setSetting($val)
    {
        $this->_propDict["setting"] = $val;
        return $this;
    }
    /**
    * Gets the settingInstanceId
    * SettingInstanceId
    *
    * @return string|null The settingInstanceId
    */
    public function getSettingInstanceId()
    {
        if (array_key_exists("settingInstanceId", $this->_propDict)) {
            return $this->_propDict["settingInstanceId"];
        } else {
            return null;
        }
    }

    /**
    * Sets the settingInstanceId
    * SettingInstanceId
    *
    * @param string $val The value of the settingInstanceId
    *
    * @return ManagedDeviceMobileAppConfigurationSettingState
    */
    public function setSettingInstanceId($val)
    {
        $this->_propDict["settingInstanceId"] = $val;
        return $this;
    }
    /**
    * Gets the settingName
    * Localized/user friendly setting name that is being reported
    *
    * @return string|null The settingName
    */
    public function getSettingName()
    {
        if (array_key_exists("settingName", $this->_propDict)) {
            return $this->_propDict["settingName"];
        } else {
            return null;
        }
    }

    /**
    * Sets the settingName
    * Localized/user friendly setting name that is being reported
    *
    * @param string $val The value of the settingName
    *
    * @return ManagedDeviceMobileAppConfigurationSettingState
    */
    public function setSettingName($val)
    {
        $this->_propDict["settingName"] = $val;
        return $this;
    }

    /**
    * Gets the sources
    * Contributing policies
    *
    * @return SettingSource|null The sources
    */
    public function getSources()
    {
        if (array_key_exists("sources", $this->_propDict)) {
            if (is_a($this->_propDict["sources"], "\Beta\Microsoft\Graph\Model\SettingSource") || is_null($this->_propDict["sources"])) {
                return $this->_propDict["sources"];
            } else {
                $this->_propDict["sources"] = new SettingSource($this->_propDict["sources"]);
                return $this->_propDict["sources"];
            }
        }
        return null;
    }

    /**
    * Sets the sources
    * Contributing policies
    *
    * @param SettingSource $val The value to assign to the sources
    *
    * @return ManagedDeviceMobileAppConfigurationSettingState The ManagedDeviceMobileAppConfigurationSettingState
    */
    public function setSources($val)
    {
        $this->_propDict["sources"] = $val;
         return $this;
    }

    /**
    * Gets the state
    * The compliance state of the setting. Possible values are: unknown, notApplicable, compliant, remediated, nonCompliant, error, conflict, notAssigned.
    *
    * @return ComplianceStatus|null The state
    */
    public function getState()
    {
        if (array_key_exists("state", $this->_propDict)) {
            if (is_a($this->_propDict["state"], "\Beta\Microsoft\Graph\Model\ComplianceStatus") || is_null($this->_propDict["state"])) {
                return $this->_propDict["state"];
            } else {
                $this->_propDict["state"] = new ComplianceStatus($this->_propDict["state"]);
                return $this->_propDict["state"];
            }
        }
        return null;
    }

    /**
    * Sets the state
    * The compliance state of the setting. Possible values are: unknown, notApplicable, compliant, remediated, nonCompliant, error, conflict, notAssigned.
    *
    * @param ComplianceStatus $val The value to assign to the state
    *
    * @return ManagedDeviceMobileAppConfigurationSettingState The ManagedDeviceMobileAppConfigurationSettingState
    */
    public function setState($val)
    {
        $this->_propDict["state"] = $val;
         return $this;
    }
    /**
    * Gets the userEmail
    * UserEmail
    *
    * @return string|null The userEmail
    */
    public function getUserEmail()
    {
        if (array_key_exists("userEmail", $this->_propDict)) {
            return $this->_propDict["userEmail"];
        } else {
            return null;
        }
    }

    /**
    * Sets the userEmail
    * UserEmail
    *
    * @param string $val The value of the userEmail
    *
    * @return ManagedDeviceMobileAppConfigurationSettingState
    */
    public function setUserEmail($val)
    {
        $this->_propDict["userEmail"] = $val;
        return $this;
    }
    /**
    * Gets the userId
    * UserId
    *
    * @return string|null The userId
    */
    public function getUserId()
    {
        if (array_key_exists("userId", $this->_propDict)) {
            return $this->_propDict["userId"];
        } else {
            return null;
        }
    }

    /**
    * Sets the userId
    * UserId
    *
    * @param string $val The value of the userId
    *
    * @return ManagedDeviceMobileAppConfigurationSettingState
    */
    public function setUserId($val)
    {
        $this->_propDict["userId"] = $val;
        return $this;
    }
    /**
    * Gets the userName
    * UserName
    *
    * @return string|null The userName
    */
    public function getUserName()
    {
        if (array_key_exists("userName", $this->_propDict)) {
            return $this->_propDict["userName"];
        } else {
            return null;
        }
    }

    /**
    * Sets the userName
    * UserName
    *
    * @param string $val The value of the userName
    *
    * @return ManagedDeviceMobileAppConfigurationSettingState
    */
    public function setUserName($val)
    {
        $this->_propDict["userName"] = $val;
        return $this;
    }
    /**
    * Gets the userPrincipalName
    * UserPrincipalName.
    *
    * @return string|null The userPrincipalName
    */
    public function getUserPrincipalName()
    {
        if (array_key_exists("userPrincipalName", $this->_propDict)) {
            return $this->_propDict["userPrincipalName"];
        } else {
            return null;
        }
    }

    /**
    * Sets the userPrincipalName
    * UserPrincipalName.
    *
    * @param string $val The value of the userPrincipalName
    *
    * @return ManagedDeviceMobileAppConfigurationSettingState
    */
    public function setUserPrincipalName($val)
    {
        $this->_propDict["userPrincipalName"] = $val;
        return $this;
    }
}
