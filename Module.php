<?php

/**
 * This code is licensed under AGPLv3 license or Afterlogic Software License
 * if commercial version of the product was purchased.
 * For full statements of the licenses see LICENSE-AFTERLOGIC and LICENSE-AGPL3 files.
 */

namespace Aurora\Modules\MailChangePasswordFastpanelPlugin;

use Aurora\Modules\Mail\Models\MailAccount;
use Aurora\System\Notifications;

/**
 * Allows users to change passwords on their email accounts in Fastpanel.
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0
 * @license https://afterlogic.com/products/common-licensing Afterlogic Software License
 * @copyright Copyright (c) 2023, Afterlogic Corp.
 *
 * @property Settings $oModuleSettings
 *
 * @package Modules
 */
class Module extends \Aurora\System\Module\AbstractModule
{
    public function init()
    {
        $this->subscribeEvent('Mail::Account::ToResponseArray', array($this, 'onMailAccountToResponseArray'));
        $this->subscribeEvent('ChangeAccountPassword', array($this, 'onChangeAccountPassword'));
    }

    /**
     * @return Module
     */
    public static function getInstance()
    {
        return parent::getInstance();
    }

    /**
     * @return Module
     */
    public static function Decorator()
    {
        return parent::Decorator();
    }

    /**
     * @return Settings
     */
    public function getModuleSettings()
    {
        return $this->oModuleSettings;
    }

    /**
     * Send GET request via cURL
     * @param string $sUrl
     * @param string $sToken
     * @return object|bool
     */
    private function getdata($sUrl, $sToken = "")
    {
        $rCurl = curl_init();
        $acurlOpt = array(
            CURLOPT_URL => $sUrl,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json']
        );
        if ($sToken != "") {
            array_push($acurlOpt[CURLOPT_HTTPHEADER], "Authorization: Bearer " . $sToken);
        }
        curl_setopt_array($rCurl, $acurlOpt);
        $mResult = curl_exec($rCurl);
        curl_close($rCurl);
        $oResult = ($mResult !== false) ? json_decode($mResult) : false;
        return $oResult;
    }

    /**
     * Send POST request via cURL
     * @param string $sUrl
     * @param string $aPost
     * @param string $sToken
     * @return object|bool
     */
    private function postdata($sUrl, $aPost, $sToken = "")
    {
        $rCurl = curl_init();
        $acurlOpt = array(
            CURLOPT_URL => $sUrl,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $aPost,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json']
        );
        if ($sToken != "") {
            array_push($acurlOpt[CURLOPT_HTTPHEADER], "Authorization: Bearer " . $sToken);
        }
        curl_setopt_array($rCurl, $acurlOpt);
        $mResult = curl_exec($rCurl);
        curl_close($rCurl);
        $oResult = ($mResult !== false) ? json_decode($mResult) : false;
        return $oResult;
    }

    /**
     * Send PUT request via cURL
     * @param string $sUrl
     * @param string $aPut
     * @param string $sToken
     * @return object|bool
     */
    private function putdata($sUrl, $aPut, $sToken = "")
    {
        $rCurl = curl_init();
        $acurlOpt = array(
            CURLOPT_URL => $sUrl,
            CURLOPT_CUSTOMREQUEST => "PUT",
            CURLOPT_POSTFIELDS => $aPut,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json']
        );
        if ($sToken != "") {
            array_push($acurlOpt[CURLOPT_HTTPHEADER], "Authorization: Bearer " . $sToken);
        }
        curl_setopt_array($rCurl, $acurlOpt);
        $mResult = curl_exec($rCurl);
        curl_close($rCurl);
        $oResult = ($mResult !== false) ? json_decode($mResult) : false;
        return $oResult;
    }

    /**
     * Adds to account response array information about if allowed to change the password for this account.
     * @param array $aArguments
     * @param mixed $mResult
     */
    public function onMailAccountToResponseArray($aArguments, &$mResult)
    {
        $oAccount = $aArguments['Account'];

        if ($oAccount && $this->checkCanChangePassword($oAccount)) {
            if (!isset($mResult['Extend']) || !is_array($mResult['Extend'])) {
                $mResult['Extend'] = [];
            }
            $mResult['Extend']['AllowChangePasswordOnMailServer'] = true;
        }
    }

    /**
     * Tries to change password for account if allowed.
     * @param array $aArguments
     * @param mixed $mResult
     */
    public function onChangeAccountPassword($aArguments, &$mResult)
    {
        $bPasswordChanged = false;
        $bBreakSubscriptions = false;

        $oAccount = $aArguments['Account'] instanceof MailAccount ? $aArguments['Account'] : false;
        if ($oAccount && $this->checkCanChangePassword($oAccount) && $oAccount->getPassword() === $aArguments['CurrentPassword']) {
            $bPasswordChanged = $this->changePassword($oAccount, $aArguments['NewPassword']);
            $bBreakSubscriptions = true; // break if mail server plugin tries to change password in this account.
        }

        if (is_array($mResult)) {
            $mResult['AccountPasswordChanged'] = $mResult['AccountPasswordChanged'] || $bPasswordChanged;
        }

        return $bBreakSubscriptions;
    }

    /**
     * Checks if allowed to change password for account.
     * @param \Aurora\Modules\Mail\Models\MailAccount $oAccount
     * @return bool
     */
    protected function checkCanChangePassword($oAccount)
    {
        $bFound = in_array('*', $this->oModuleSettings->SupportedServers);

        if (!$bFound) {
            $oServer = $oAccount->getServer();

            if ($oServer && in_array($oServer->IncomingServer, $this->oModuleSettings->SupportedServers)) {
                $bFound = true;
            }
        }

        return $bFound;
    }

    /**
     * Tries to change password for account.
     * @param \Aurora\Modules\Mail\Models\MailAccount $oAccount
     * @param string $sPassword
     * @return boolean
     * @throws \Aurora\System\Exceptions\ApiException
     */
    protected function changePassword($oAccount, $sPassword)
    {
        $bResult = false;
        $sEmail = $oAccount->Email;
        $sPassCurr = $oAccount->getPassword();
        [$sUsername, $sDomain] = explode("@", $sEmail);

        $sFastpanelURL = rtrim($this->oModuleSettings->FastpanelURL, "/");
        $sFastpanelAdminUser = $this->oModuleSettings->FastpanelAdminUser;
        $sFastpanelAdminPass = $this->oModuleSettings->FastpanelAdminPass;

        if ($sFastpanelAdminPass && !\Aurora\System\Utils::IsEncryptedValue($sFastpanelAdminPass)) {
            $this->setConfig('FastpanelAdminPass', \Aurora\System\Utils::EncryptValue($sFastpanelAdminPass));
            $this->saveModuleConfig();
        } else {
            $sFastpanelAdminPass = \Aurora\System\Utils::DecryptValue($sFastpanelAdminPass);
        }

        if (0 < strlen($sPassCurr) && $sPassCurr !== $sPassword) {
            $aPost = array("password" => $sFastpanelAdminPass, "username" => $sFastpanelAdminUser);
            $oRes1 = $this->postdata($sFastpanelURL . "/login", json_encode($aPost));

            if ($oRes1 === false) {
                throw new \Aurora\System\Exceptions\ApiException(0, null, "Fastpanel admin auth general error");
            }

            if (isset($oRes1->code) && isset($oRes1->message)) {
                throw new \Aurora\System\Exceptions\ApiException(0, null, "Fastpanel admin auth error " . $oRes1->code . ": " . $oRes1->message);
            }

            if (!isset($oRes1->data->token)) {
                throw new \Aurora\System\Exceptions\ApiException(0, null, "Fastpanel admin auth failed");
            }

            $sToken = $oRes1->data->token;
            $oRes2 = $this->getdata($sFastpanelURL . "/api/email/domains", $sToken);
            if (($oRes2 === false) || (!isset($oRes2->data))) {
                throw new \Aurora\System\Exceptions\ApiException(0, null, "Fastpanel API error: could not get list of domains");
            }

            $aDomainList = $oRes2->data;
            $iDomainId = null;
            foreach ($aDomainList as $oDomainListItem) {
                if ($oDomainListItem->name == $sDomain) {
                    $iDomainId = $oDomainListItem->id;
                }
            }

            if ($iDomainId == null) {
                throw new \Aurora\System\Exceptions\ApiException(0, null, "Fastpanel API error: could not locate email domain " . $sDomain);
            }

            $oRes3 = $this->getdata($sFastpanelURL . "/api/email/domains/" . $iDomainId . "/boxs", $sToken);
            if (!is_object($oRes3)) {
                throw new \Aurora\System\Exceptions\ApiException(0, null, "Fastpanel API error: could not retrieve list of email users in domain " . $sDomain);
            }
            $aUserList = $oRes3->data;
            $iUserId = null;
            $oUser = null;
            foreach ($aUserList as $oUserListItem) {
                if ($oUserListItem->login == $sUsername) {
                    $oUser = $oUserListItem;
                    $iUserId = $oUser->id;
                }
            }

            if ($iUserId == null) {
                throw new \Aurora\System\Exceptions\ApiException(0, null, "Fastpanel API error: could not locate email user " . $sUsername . " in domain");
            }

            $aPut = array("password" => $sPassword, "quota" => $oUser->quota, "redirects" => $oUser->redirects, "aliases" => $oUser->aliases, "spam_to_junk" => $oUser->spam_to_junk);
            $oRes4 = $this->putdata($sFastpanelURL . "/api/mail/box/" . $iUserId, json_encode($aPut), $sToken);

            if (isset($oRes4->errors->password)) {
                throw new \Aurora\System\Exceptions\ApiException(0, null, "Fastpanel API error: " . $oRes4->errors->password);
            }

            if (!isset($oRes4->data->id)) {
                throw new \Aurora\System\Exceptions\ApiException(Notifications::CanNotChangePassword);
            }
        }
        return true;
    }
}
