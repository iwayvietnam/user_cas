<?php

/**
 * ownCloud - user_cas
 *
 * @author Sixto Martin <sixto.martin.garcia@gmail.com>
 * @copyright Sixto Martin Garcia. 2012
 * @copyright Leonis. 2014 <devteam@leonis.at>
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

require_once(__DIR__ . '/lib/ldap_backend_adapter.php');
use OCA\user_cas\lib\LdapBackendAdapter;

class OC_USER_CAS extends OC_User_Backend {

	// cached settings
	public $autocreate;
	public $updateUserData;
	public $protectedGroups;
	public $defaultGroup;
	public $displayNameMapping;
	public $mailMapping;
	public $groupMapping;
	//public $initialized = false;
	protected static $instance = null;
	protected static $_initialized_php_cas = false;
	private $ldapBackendAdapter=false;
	private $cas_link_to_ldap_backend=false;

	public static function getInstance() {
		if (self::$instance == null) {
			self::$instance = new OC_USER_CAS();
		}
		return self::$instance;
	}

	public function __construct() {
		$this->autocreate = OCP\Config::getAppValue('user_cas', 'cas_autocreate', true);
		$this->cas_link_to_ldap_backend = \OCP\Config::getAppValue('user_cas', 'cas_link_to_ldap_backend', false);
		$this->updateUserData = OCP\Config::getAppValue('user_cas', 'cas_update_user_data', true);
		$this->defaultGroup = OCP\Config::getAppValue('user_cas', 'cas_default_group', '');
		$this->protectedGroups = explode (',', str_replace(' ', '', OCP\Config::getAppValue('user_cas', 'cas_protected_groups', '')));
		$this->mailMapping = OCP\Config::getAppValue('user_cas', 'cas_email_mapping', '');
		$this->displayNameMapping = OCP\Config::getAppValue('user_cas', 'cas_displayName_mapping', '');
		$this->groupMapping = OCP\Config::getAppValue('user_cas', 'cas_group_mapping', '');

		self::initialized_php_cas();
	}

	public static function initialized_php_cas() {
		if(!self::$_initialized_php_cas) {
			$casVersion = OCP\Config::getAppValue('user_cas', 'cas_server_version', '2.0');
			$casHostname = OCP\Config::getAppValue('user_cas', 'cas_server_hostname', $_SERVER['SERVER_NAME']);
			$casPort = OCP\Config::getAppValue('user_cas', 'cas_server_port', 443);
			$casPath = OCP\Config::getAppValue('user_cas', 'cas_server_path', '/cas');
			$casDebugFile = OCP\Config::getAppValue('user_cas', 'cas_debug_file', '');
			$casCertPath = OCP\Config::getAppValue('user_cas', 'cas_cert_path', '');
			$php_cas_path = OCP\Config::getAppValue('user_cas', 'cas_php_cas_path', 'CAS.php');
			$cas_service_url = OCP\Config::getAppValue('user_cas', 'cas_service_url', '');

			if (!class_exists('phpCAS')) {
				if (empty($php_cas_path)) $php_cas_path='CAS.php';
				\OCP\Util::writeLog('cas',"Try to load phpCAS library ($php_cas_path)", \OCP\Util::DEBUG);
				include_once($php_cas_path);
				if (!class_exists('phpCAS')) {
					\OCP\Util::writeLog('cas','Fail to load phpCAS library !', \OCP\Util::ERROR);
					return false;
				}
			}

			if ($casDebugFile !== '') {
				phpCAS::setDebug($casDebugFile);
			}
			
			phpCAS::client($casVersion, $casHostname, (int) $casPort, $casPath, false);
			
			if (!empty($cas_service_url)) {
				phpCAS::setFixedServiceURL($cas_service_url);
			}
						
			if(!empty($casCertPath)) {
				phpCAS::setCasServerCACert($casCertPath);
			}
			else {
				phpCAS::setNoCasServerValidation();
			}
			self::$_initialized_php_cas = true;
		}
		return self::$_initialized_php_cas;
	}

	private function initializeLdapBackendAdapter() {
		if (!$this->cas_link_to_ldap_backend) {
			return false;
		}
		if ($this->ldapBackendAdapter === false) {
			$this->ldapBackendAdapter = new LdapBackendAdapter();
		}
		return true;
	}

	public function checkPassword($uid, $password) {
		if (!self::initialized_php_cas()) {
			return false;
		}

		if(!phpCAS::isAuthenticated()) {
			return false;
		}

		$uid = phpCAS::getUser();
		if ($uid === false) {
			\OCP\Util::writeLog('cas','phpCAS return no user !', \OCP\Util::ERROR);
			return false;
		}

		if ($this->initializeLdapBackendAdapter()) {
			\OCP\Util::writeLog('cas',"Search CAS user '$uid' in LDAP", \OCP\Util::DEBUG);
			//Retrieve user in LDAP directory
			$ocname = $this->ldapBackendAdapter->getUuid($uid);

			if (($uid !== false) && ($ocname !== false)) {
				\OCP\Util::writeLog('cas',"Found CAS user '$uid' in LDAP with name '$ocname'", \OCP\Util::DEBUG);
				return $ocname;
			}
		}

		return $uid;
	}

	
	public function getDisplayName($uid) {
		$udb = new \OC\User\Database;
		return $udb->getDisplayName($uid);
	}

	/**
	* Sets the display name for by using the CAS attribute specified in the mapping
	*
	*/
	public function setDisplayName($uid,$displayName) {
		$udb = new \OC\User\Database;
		$udb->setDisplayName($uid, $displayName);
	}

	public static function generateRandomBytes($length = 30) {
		return \OC::$server->getSecureRandom()->getMediumStrengthGenerator()->generate($length, \OCP\Security\ISecureRandom::CHAR_LOWER.\OCP\Security\ISecureRandom::CHAR_DIGITS);
	}

	public static function generateRandomBytes($length = 30) {
		return \OC::$server->getSecureRandom()->getMediumStrengthGenerator()->generate($length, \OCP\Security\ISecureRandom::CHAR_LOWER.\OCP\Security\ISecureRandom::CHAR_DIGITS);
	}

	public static function isCasFrontChannelLogoutRequest() {
		return !empty($_GET['SAMLRequest']);
	}

	public static function uncompressCasLogoutMessage() {
		$message = OC_Util::sanitizeHTML($_GET['SAMLRequest'], ENT_QUOTES, 'UTF-8');
		return gzinflate(base64_decode($message));
	}
}

