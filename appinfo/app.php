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


if (OCP\App::isEnabled('user_cas')) {

	require_once 'user_cas/user_cas.php';

	OCP\App::registerAdmin('user_cas', 'settings');

	// register user backend
	OC_User::useBackend('CAS');

	OC::$CLASSPATH['OC_USER_CAS_Hooks'] = 'user_cas/lib/hooks.php';
	OCP\Util::connectHook('OC_User', 'post_createUser', 'OC_USER_CAS_Hooks', 'post_createUser');
	OCP\Util::connectHook('OC_User', 'post_login', 'OC_USER_CAS_Hooks', 'post_login');
	OCP\Util::connectHook('OC_User', 'logout', 'OC_USER_CAS_Hooks', 'logout');

	$force_login = shouldEnforceAuthentication();

	if ((isset($_GET['app']) && $_GET['app'] == 'user_cas') || $force_login) {

		if (OC_USER_CAS::initialized_php_cas()) {

			if ($force_login) {
				$cas_service_url = OCP\Config::getAppValue('user_cas', 'cas_service_url', '');
				if (empty($cas_service_url)) {
					$urlGenerator = \OC::$server->getURLGenerator();
					$cas_service_url = $urlGenerator->getAbsoluteURL('?app=user_cas');
					phpCAS::setFixedServiceURL($cas_service_url);
				}
			}

			phpCAS::forceAuthentication();

			$uid = phpCAS::getUser();
			if (!OC_User::login($uid, '')) {
				$error = true;
				\OCP\Util::writeLog('cas','Error trying to authenticate the user', \OCP\Util::DEBUG);
			}

			if (isset($_SERVER["QUERY_STRING"]) && !empty($_SERVER["QUERY_STRING"]) && $_SERVER["QUERY_STRING"] != 'app=user_cas') {
				header('Location: ' . OC::$WEBROOT . '/?' . $_SERVER["QUERY_STRING"]);
				exit();
			}
		}

		OC::$REQUESTEDAPP = '';
		OC_Util::redirectToDefaultPage();
	}


	if (!phpCAS::isAuthenticated() && !OCP\User::isLoggedIn()) {
		OC_App::registerLogIn(array('href' => '?app=user_cas', 'name' => 'CAS Login'));
	}

	casSingleLogoutRequestHandle();
}

/**
 * Check if login should be enforced using user_cas
 */
function shouldEnforceAuthentication() {
	if (OC::$CLI) {
		return false;
	}

	if (OCP\Config::getAppValue('user_cas', 'cas_force_login', false) !== 'on') {
		return false;
	}

	$isSLO = OC_USER_CAS::isCasFrontChannelLogoutRequest();
	if ($isSLO || phpCAS::isAuthenticated() || OCP\User::isLoggedIn() || isset($_GET['admin_login'])) {
		return false;
	}

	$script = basename($_SERVER['SCRIPT_FILENAME']);
	return !in_array(
		$script,
		array(
			'cron.php',
			'public.php',
			'remote.php',
			'status.php',
		)
	);
}

function casSingleLogoutRequestHandle() {
	if (OC_USER_CAS::isCasFrontChannelLogoutRequest()) {
		OC_User::logout();

		$logoutMessage = OC_USER_CAS::uncompressCasLogoutMessage();
		if (!empty($logoutMessage)) {
			\OCP\Util::writeLog('cas','Logout request:' . $logoutMessage, \OCP\Util::DEBUG);
		}
		$relayStateValue = OC_Util::sanitizeHTML($_GET['RelayState'], ENT_QUOTES, 'UTF-8');
		if (!empty($relayStateValue)) {
			$casHostname = OCP\Config::getAppValue('user_cas', 'cas_server_hostname', $_SERVER['SERVER_NAME']);
			$casPort = (int) OCP\Config::getAppValue('user_cas', 'cas_server_port', 443);
			$casPath = OCP\Config::getAppValue('user_cas', 'cas_server_path', '/cas');

			$redirectUrl = 'https://' . $casHostname;
			if ($casPort != 443) {
				$redirectUrl .= ':' . $casPort;
			}
			$redirectUrl .= '/' . trim($casPath, '/');
			$redirectUrl .= '/logout?_eventId=next';
			$redirectUrl .= '&RelayState=' . $relayStateValue;
			header('Location: ' . $redirectUrl);
			exit();
		}
	}
}
