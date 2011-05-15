<?php
/**********************************************************************************
* QueryString.php                                                                 *
***********************************************************************************
* SMF: Simple Machines Forum                                                      *
* Open-Source Project Inspired by Zef Hemel (zef@zefhemel.com)                    *
* =============================================================================== *
* Software Version:           SMF 2.0 RC5                                         *
* Software by:                Simple Machines (http://www.simplemachines.org)     *
* Copyright 2006-2010 by:     Simple Machines LLC (http://www.simplemachines.org) *
*           2001-2006 by:     Lewis Media (http://www.lewismedia.com)             *
* Support, News, Updates at:  http://www.simplemachines.org                       *
***********************************************************************************
* This program is free software; you may redistribute it and/or modify it under   *
* the terms of the provided license as published by Simple Machines LLC.          *
*                                                                                 *
* This program is distributed in the hope that it is and will be useful, but      *
* WITHOUT ANY WARRANTIES; without even any implied warranty of MERCHANTABILITY    *
* or FITNESS FOR A PARTICULAR PURPOSE.                                            *
*                                                                                 *
* See the "license.txt" file for details of the Simple Machines license.          *
* The latest version can always be found at http://www.simplemachines.org.        *
**********************************************************************************/

/**
 * This file handles functions that manage the output buffer, query string, and incoming sanitation thereof, amongst other things.
 *
 * @package wedge
 */

if (!defined('SMF'))
	die('Hacking attempt...');

/**
 * Cleans all of the environment variables going into this request.
 *
 * By the time we're done, everything should have slashes (regardless of php.ini).
 *
 * - Defines $scripturl (as $boardurl . '/index.php')
 * - Identifies which function to run to handle magic_quotes.
 * - Removes $HTTP_POST_* if set.
 * - Aborts if someone is trying to set $GLOBALS via $_REQUEST or the cookies (in the case of register_globals being on)
 * - Aborts if someone is trying to use numeric keys (e.g. index.php?1=2) in $_POST, $_GET or $_FILES and dumps them if found in $_COOKIE.
 * - Ensure we have the current querystring, and that it's valid.
 * - Check if the server is using ; as the separator, and parse the URL if not.
 * - Cleans input strings dependent on magic_quotes settings.
 * - Process everything in $_GET to ensure it all has entities.
 * - Rebuild $_REQUEST to be $_POST and $_GET only (never $_COOKIE)
 * - Check if $topic and $board are set and push them into the global space.
 * - Check for other stuff in $_REQUEST like a start, or action and deal with the types appropriately.
 * - Try to get the requester's IP address and make sure we have a USER-AGENT and we have the requested URI.
 */
function cleanRequest()
{
	global $board, $topic, $boardurl, $scripturl, $modSettings, $context, $full_request, $full_board, $action_list;

	define('INVALID_IP', '00000000000000000000000000000000');

/*
	// Makes it easier to refer to things this way.
	// !!! Change this once the feature is deemed to be stable.

	if (!empty($modSettings['pretty_enable_filters']))
	{
		$boardurl = 'http://' . $_SERVER['HTTP_HOST'];
		$scripturl = $boardurl . (isset($_COOKIE[session_name()]) ? '/' : '/index.php');
	}
	else */
	$scripturl = $boardurl . '/index.php';

	// What function to use to reverse magic quotes - if sybase is on we assume that the database sensibly has the right unescape function!
	$removeMagicQuoteFunction = @ini_get('magic_quotes_sybase') || strtolower(@ini_get('magic_quotes_sybase')) == 'on' ? 'unescapestring__recursive' : 'stripslashes__recursive';

	// Save some memory.. (since we don't use these anyway.)
	unset($GLOBALS['HTTP_POST_VARS'], $GLOBALS['HTTP_POST_FILES']);

	// These keys shouldn't be set... Ever.
	if (isset($_REQUEST['GLOBALS']) || isset($_COOKIE['GLOBALS']))
		die('Invalid request variable.');

	// Same goes for numeric keys.
	foreach (array_merge(array_keys($_POST), array_keys($_GET), array_keys($_FILES)) as $key)
		if (is_numeric($key))
			die('Numeric request keys are invalid.');

	// Numeric keys in cookies are less of a problem. Just unset those.
	foreach ($_COOKIE as $key => $value)
		if (is_numeric($key))
			unset($_COOKIE[$key]);

	// Get the correct query string. It may be in an environment variable...
	if (!isset($_SERVER['QUERY_STRING']))
		$_SERVER['QUERY_STRING'] = getenv('QUERY_STRING');

	// It seems that sticking a URL after the query string is mighty common, well, it's evil - don't.
	if (strpos($_SERVER['QUERY_STRING'], 'http') === 0)
	{
		header('HTTP/1.1 400 Bad Request');
		die;
	}

	// Are we going to need to parse the ; out?
	if (strpos(@ini_get('arg_separator.input'), ';') === false && !empty($_SERVER['QUERY_STRING']))
	{
		// Get rid of the old one! You don't know where it's been!
		$_GET = array();

		// Was this redirected? If so, get the REDIRECT_QUERY_STRING.
		$_SERVER['QUERY_STRING'] = urldecode(substr($_SERVER['QUERY_STRING'], 0, 5) === 'url=/' ? $_SERVER['REDIRECT_QUERY_STRING'] : $_SERVER['QUERY_STRING']);

		// Replace ';' with '&' and '&something&' with '&something=&'. (This is done for compatibility...)
		// !!! smflib
		parse_str(preg_replace('/&(\w+)(?=&|$)/', '&$1=', strtr($_SERVER['QUERY_STRING'], array(';?' => '&', ';' => '&', '%00' => '', "\0" => ''))), $_GET);

		// Magic quotes still applies with parse_str - so clean it up.
		if (function_exists('get_magic_quotes_gpc') && @get_magic_quotes_gpc() != 0 && empty($modSettings['integrate_magic_quotes']))
			$_GET = $removeMagicQuoteFunction($_GET);
	}
	elseif (strpos(@ini_get('arg_separator.input'), ';') !== false)
	{
		if (function_exists('get_magic_quotes_gpc') && @get_magic_quotes_gpc() != 0 && empty($modSettings['integrate_magic_quotes']))
			$_GET = $removeMagicQuoteFunction($_GET);

		// Search engines will send action=profile%3Bu=1, which confuses PHP.
		foreach ($_GET as $k => $v)
		{
			if (is_string($v) && strpos($k, ';') !== false)
			{
				$temp = explode(';', $v);
				$_GET[$k] = $temp[0];

				for ($i = 1, $n = count($temp); $i < $n; $i++)
				{
					@list ($key, $val) = @explode('=', $temp[$i], 2);
					if (!isset($_GET[$key]))
						$_GET[$key] = $val;
				}
			}

			// This helps a lot with integration!
			if (strpos($k, '?') === 0)
			{
				$_GET[substr($k, 1)] = $v;
				unset($_GET[$k]);
			}
		}
	}

	// There's no query string, but there is a URL... try to get the data from there.
	if (!empty($_SERVER['REQUEST_URI']))
	{
		// Remove the .html, assuming there is one.
		if (substr($_SERVER['REQUEST_URI'], strrpos($_SERVER['REQUEST_URI'], '.'), 4) == '.htm')
			$request = substr($_SERVER['REQUEST_URI'], 0, strrpos($_SERVER['REQUEST_URI'], '.'));
		else
			$request = $_SERVER['REQUEST_URI'];

		// !!! smflib.
		// Replace 'index.php/a,b,c/d/e,f' with 'a=b,c&d=&e=f' and parse it into $_GET.
		if (strpos($request, basename($scripturl) . '/') !== false)
		{
			parse_str(substr(preg_replace('/&(\w+)(?=&|$)/', '&$1=', strtr(preg_replace('~/([^,/]+),~', '/$1=', substr($request, strpos($request, basename($scripturl)) + strlen(basename($scripturl)))), '/', '&')), 1), $temp);
			if (function_exists('get_magic_quotes_gpc') && @get_magic_quotes_gpc() != 0 && empty($modSettings['integrate_magic_quotes']))
				$temp = $removeMagicQuoteFunction($temp);
			$_GET += $temp;
		}
	}

	if (isset($_GET['board']) && is_numeric($_GET['board']))
		$board = (int) $_GET['board'];
	elseif (!empty($modSettings['pretty_enable_filters']))
	{
		// !!! Authorize URLs like noisen.com:80
		//	$_SERVER['HTTP_HOST'] = strpos($_SERVER['HTTP_HOST'], ':') === false ? $_SERVER['HTTP_HOST'] : substr($_SERVER['HTTP_HOST'], 0, strpos($_SERVER['HTTP_HOST'], ':'));
		$full_request = $_SERVER['HTTP_HOST'] . (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/');

		$query = wesql::query('
			SELECT id_board, url
			FROM {db_prefix}boards AS b
			WHERE urllen >= {int:len}
			AND url = SUBSTRING({string:url}, 1, urllen)
			ORDER BY urllen DESC LIMIT 1',
			array(
				'url' => rtrim($full_request, '/'),
				'len' => ($len = strpos($full_request, '/')) !== false ? $len : strlen($full_request),
			)
		);

		if (wesql::num_rows($query) == 0)
		{
			$full_board = array();
			$board = 0;
			unset($_GET['board']);

			// If URL has the form domain.com/~User, it's a profile request.
			if (preg_match('@/~([^/]*)@', $full_request, $m))
			{
				if ($m[1] == '')
					$_REQUEST['u'] = $_GET['u'] = $user_info['id'];
				else
					$_REQUEST['user'] = $_GET['user'] = $m[1];
				$_REQUEST['action'] = $_GET['action'] = 'profile';
			}
			// If URL has the form domain.com/do/action, it's an action. Really.
			elseif (preg_match('@/do/([a-zA-Z0-9]+)@', $full_request, $m) && isset($action_list[$m[1]]))
				$_REQUEST['action'] = $_GET['action'] = $m[1];
		}
		else
		{
			$full_board = wesql::fetch_assoc($query);

			// The happy place where boards are identified.
			$_GET['board'] = $board = $full_board['id_board'];
			$_SERVER['REAL_HTTP_HOST'] = $_SERVER['HTTP_HOST'];
			$_SERVER['HTTP_HOST'] = $full_board['url'];
			$_SERVER['REQUEST_URI'] = $ru = str_replace($full_board['url'], '', $full_request);

			// We will now be analyzing the request URI to find our topic ID and various options...

			if (isset($_GET['topic']))
			{
				// If we've got a topic ID, we can recover the board ID easily!
			}
			// URL: /2010/12/25/?something or /2010/p15/ (get all topics from Christmas 2010, or page 2 of all topics from 2010)
			elseif (preg_match('~^/(2\d{3}(?:/\d{2}(?:/[0-3]\d)?)?)(?:/p(\d+))?~', $ru, $m))
			{
				$_GET['month'] = str_replace('/', '', $m[1]);
				$_GET['start'] = empty($m[2]) ? 0 : $m[2];
				$_GET['pretty'] = 1;
			}
			// URL: /1234/topic/new/?something or /1234/topic/2/?something (get topic ID 1234, named 'topic')
			elseif (preg_match('~^/(\d+)/(?:[^/]+)/(\d+|msg\d+|from\d+|new)?~u', $ru, $m))
			{
				$_GET['topic'] = $m[1];
				$_GET['start'] = empty($m[2]) ? 0 : $m[2];
				$_GET['pretty'] = 1;
			}
			// URL: /cat/hello/?something or /tag/me/p15/ (get all topics from category 'hello', or page 2 of all topics with tag 'me')
			elseif (preg_match('~^/(cat|tag)/([^/]+)(?:/p(\d+))?~u', $ru, $m))
			{
				$_GET[$m[1]] = $m[2];
				$_GET['start'] = empty($m[3]) ? 0 : $m[3];
				$_GET['pretty'] = 1;
			}
			// URL: /p15/ (board index, page 2)
			elseif (preg_match('~^/p(\d+)~', $ru, $m))
			{
				$_GET[$m[1]] = $m[2];
				$_GET['start'] = empty($m[3]) ? 0 : $m[3];
				$_GET['pretty'] = 1;
			}
		}
		wesql::free_result($query);

		// Plug-ins may want to play with their own URL system.
		call_hook('determine_location', array($full_request, $full_board));
	}

	// Don't bother going further if we've come here from a *REAL* 404.
	if (strpos($full_request, '?') === false && in_array(strtolower(substr($full_request, -4)), array('.gif', '.jpg', 'jpeg', '.png')))
	{
		loadLanguage('Errors');

		header('HTTP/1.0 404 Not Found');
		header('Content-Type: text/plain; charset=UTF-8');

		// Webmasters might want to log the error, so they can fix any broken image links.
		updateOnlineWithError('404 Not Found', false);
		if (!empty($modSettings['enableErrorLogging']))
		{
			log_error('File not found');
			loadSource('ManageErrors');
			updateErrorCount();
		}
		die('404 Not Found');
	}

	// If magic quotes are on, we have some work to do...
	if (function_exists('get_magic_quotes_gpc') && @get_magic_quotes_gpc() != 0)
	{
		$_ENV = $removeMagicQuoteFunction($_ENV);
		$_POST = $removeMagicQuoteFunction($_POST);
		$_COOKIE = $removeMagicQuoteFunction($_COOKIE);
		foreach ($_FILES as $k => $dummy)
			if (isset($_FILES[$k]['name']))
				$_FILES[$k]['name'] = $removeMagicQuoteFunction($_FILES[$k]['name']);
	}

	// Add entities to GET. This is kinda like the slashes on everything else.
	$_GET = htmlspecialchars__recursive($_GET);

	// Let's not depend on the ini settings... why even have COOKIE in there, anyway?
	$_REQUEST = $_POST + $_GET;

	// Make sure $board and $topic are numbers.
	if (isset($_REQUEST['board']))
	{
		// Make sure it's a string and not something else like an array
		$_REQUEST['board'] = (string) $_REQUEST['board'];

		// If there's a slash in it, we've got a start value!
		if (strpos($_REQUEST['board'], '/') !== false && strpos($_REQUEST['board'], $_SERVER['HTTP_HOST']) === false)
			list ($_REQUEST['board'], $_REQUEST['start']) = explode('/', $_REQUEST['board']);
		// Same idea, but dots. This is the currently used format - ?board=1.0...
		elseif (strpos($_REQUEST['board'], '.') !== false)
		{
			list ($reqboard, $reqstart) = explode('.', $_REQUEST['board']);
			if (is_numeric($reqboard) && is_numeric($reqstart))
			{
				$_REQUEST['board'] = $reqboard;
				$_REQUEST['start'] = $reqstart;
			}
		}
		// Now make absolutely sure it's a number.
		// Check for pretty board URLs too, and possibly redirect if oldschool queries were used.
		if (is_numeric($_REQUEST['board']))
		{
			$board = (int) $_REQUEST['board'];
			if (!isset($_REQUEST['pretty']))
				$context['pretty']['oldschoolquery'] = true;
		}
		else
			$board = 0;

		$_REQUEST['start'] = isset($_REQUEST['start']) ? (int) $_REQUEST['start'] : 0;

		// This is for "Who's Online" because it might come via POST - and it should be an int here.
		$_GET['board'] = $board;
	}
	// Well, $board is going to be a number no matter what.
	else
		$board = 0;

	// If there's a threadid, it's probably an old YaBB SE link. Flow with it.
	if (isset($_REQUEST['threadid']) && !isset($_REQUEST['topic']))
		$_REQUEST['topic'] = $_REQUEST['threadid'];

	// We've got topic!
	if (isset($_REQUEST['topic']))
	{
		// Make sure it's a string and not something else like an array
		$_REQUEST['topic'] = (string) $_REQUEST['topic'];

		// Slash means old, beta style, formatting. That's okay though, the link should still work.
		if (strpos($_REQUEST['topic'], '/') !== false)
			list ($_REQUEST['topic'], $_REQUEST['start']) = explode('/', $_REQUEST['topic']);
		// Dots are useful and fun ;). This is ?topic=1.15.
		elseif (strpos($_REQUEST['topic'], '.') !== false)
			list ($_REQUEST['topic'], $_REQUEST['start']) = explode('.', $_REQUEST['topic']);

		// Check for pretty topic URLs, and possibly redirect if oldschool queries were used.
		if (is_numeric($_REQUEST['topic']))
		{
			$topic = (int) $_REQUEST['topic'];
			if (!isset($_REQUEST['pretty']))
				$context['pretty']['oldschoolquery'] = true;
		}
		else
		{
			$_REQUEST['topic'] = str_replace(array('&#039;', '&#39;', '\\'), array(chr(18), chr(18), ''), $_REQUEST['topic']);
			$_REQUEST['topic'] = preg_replace('`([\x80-\xff])`e', 'sprintf(\'%%%x\', ord(\'$1\'))', $_REQUEST['topic']);
			// Are we feeling lucky?
			$query = wesql::query('
				SELECT p.id_topic, t.id_board
				FROM {db_prefix}pretty_topic_urls AS p
				INNER JOIN {db_prefix}topics AS t ON p.id_topic = t.id_topic
				INNER JOIN {db_prefix}boards AS b ON b.id_board = t.id_board
				WHERE p.pretty_url = {string:pretty}
				AND b.url = {string:url}
				LIMIT 1', array(
					'pretty' => $_REQUEST['topic'],
					'url' => $_SERVER['HTTP_HOST']
				));
			// No? No topic?!
			if (wesql::num_rows($query) == 0)
				$topic = 0;
			else
				list ($topic, $board) = wesql::fetch_row($query);
			wesql::free_result($query);

			// That query should be counted separately
			$context['pretty']['db_count']++;
		}

		// Now make sure the online log gets the right number.
		$_GET['topic'] = $topic;
	}
	else
		$topic = 0;

	unset($_REQUEST['pretty'], $_GET['pretty']);

	// There should be a $_REQUEST['start'], some at least. If you need to default to other than 0, use $_GET['start'].
	if (empty($_REQUEST['start']) || $_REQUEST['start'] < 0 || (int) $_REQUEST['start'] > 2147473647)
		$_REQUEST['start'] = 0;

	// The action needs to be a string and not an array or anything else
	if (isset($_REQUEST['action']))
		$_REQUEST['action'] = (string) $_REQUEST['action'];
	if (isset($_GET['action']))
		$_GET['action'] = (string) $_GET['action'];

	// Make sure we have a valid REMOTE_ADDR.
	if (!isset($_SERVER['REMOTE_ADDR']))
	{
		$_SERVER['REMOTE_ADDR'] = '';
		// A new magic variable to indicate we think this is command line.
		$_SERVER['is_cli'] = true;
	}

	// Are they using a reverse proxy that's hiding the IP address (e.g. CloudFlare)?
	if (!empty($modSettings['reverse_proxy']))
	{
		// We already check for X-Forwarded-For anyway in Wedge. But if we happen to have something else, let's use that.
		if (!empty($modSettings['reverse_proxy_header']) && $modSettings['reverse_proxy_header'] != 'X-Forwarded-For')
		{
			$header = 'HTTP_' . strtoupper($modSettings['reverse_proxy_header']);
			if (!empty($_SERVER[$header]))
				$_SERVER['HTTP_X_FORWARDED_FOR'] = $_SERVER[$header];
		}
		$context['additional_headers']['X-Detected-Remote-Address'] = $_SERVER['REMOTE_ADDR'];
		if (!empty($modSettings['reverse_proxy_ips']))
			$reverse_proxies = explode(',', $modSettings['reverse_proxy_ips']); // We don't want this set if we're not knowingly using them.
	}

	// OK, whatever we have in our default place, let's turn it into our default format.
	$_SERVER['REMOTE_ADDR'] = expand_ip($_SERVER['REMOTE_ADDR']);

	// Try to calculate their most likely IP for those people behind proxies (and the like).
	$_SERVER['BAN_CHECK_IP'] = $_SERVER['REMOTE_ADDR'];

	// Try and find the user's IP address. First make sure everything's in the right format.
	$internal_subnet = match_internal_subnets($_SERVER['REMOTE_ADDR']);
	if (!empty($_SERVER['HTTP_X_FORWARDED_FOR']))
	{
		$_SERVER['HTTP_X_FORWARDED_FOR_ORIGINAL'] = $_SERVER['HTTP_X_FORWARDED_FOR']; // this might actually be a funky list of IPs.
		$_SERVER['HTTP_X_FORWARDED_FOR'] = expand_ip($_SERVER['HTTP_X_FORWARDED_FOR']);
	}
	if (!empty($_SERVER['HTTP_CLIENT_IP']))
		$_SERVER['HTTP_CLIENT_IP'] = expand_ip($_SERVER['HTTP_CLIENT_IP']);

	if (!empty($_SERVER['HTTP_X_FORWARDED_FOR']) && !empty($_SERVER['HTTP_CLIENT_IP']))
	{
		// We have both forwarded-for and client IP, this could be interesting.
		// Is the supplied CLIENT_IP potentially usable? Or, is the supplied REMOTE_ADDR non-routable?
		if (!match_internal_subnets($_SERVER['HTTP_CLIENT_IP']) || $internal_subnet)
		{
			// OK, now for some cleverness, the CLIENT_IP might be usable, but might be reversed in order. At least for IPv4, no information available for IPv6.
			// So, check for IPv4, then if the first octet of IPv4 matches or not, and if not, see if XFF's first octet matches CLIENT_IP's last - and if so, reverse the octets.
			if (is_ipv4($_SERVER['HTTP_X_FORWARDED_FOR']) && is_ipv4($_SERVER['HTTP_CLIENT_IP']))
			{
				$xff_octet = substr($_SERVER['HTTP_X_FORWARDED_FOR'], 24, 2);
				if ($xff_octet !== substr($_SERVER['HTTP_CLIENT_IP'], 24, 2) && $xff_octet === substr($SERVER['HTTP_CLIENT_IP'], -2))
					$_SERVER['HTTP_CLIENT_IP'] = '00000000000000000000ffff' . implode('', array_reverse(str_split(substr($_SERVER['HTTP_CLIENT_IP'], -8), 2)));
			}
			$_SERVER['BAN_CHECK_IP'] = $_SERVER['HTTP_CLIENT_IP'];
		}
	}
	if (!empty($_SERVER['HTTP_CLIENT_IP']) && (!match_internal_subnets($_SERVER['HTTP_CLIENT_IP']) || $internal_subnet))
	{
		// Since they are in different blocks, it's probably reversed. Again, this is IPv4 specific for the present time.
		if (is_ipv4($_SERVER['HTTP_CLIENT_IP']) && is_ipv4($_SERVER['REMOTE_ADDR']))
		{
			if (substr($_SERVER['REMOTE_ADDR'], 24, 2) !== substr($_SERVER['HTTP_CLIENT_IP'], 24, 2))
				$_SERVER['HTTP_CLIENT_IP'] = '00000000000000000000ffff' . implode('', array_reverse(str_split(substr($_SERVER['HTTP_CLIENT_IP'], -8), 2)));
		}
		$_SERVER['BAN_CHECK_IP'] = $_SERVER['HTTP_CLIENT_IP'];
	}
	elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR']))
	{
		// If there are commas, get the last one.. probably.
		if (strpos($_SERVER['HTTP_X_FORWARDED_FOR_ORIGINAL'], ',') !== false)
		{
			$ips = array_reverse(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR_ORIGINAL']));

			// Go through each IP...
			foreach ($ips as $i => $ip)
			{
				$ip = expand_ip(trim($ip));
				// Make sure it's in a valid range...
				if (match_internal_subnets($ip) && !$internal_subnet)
					continue;

				// Is it on our list of reverse proxies? If so, we don't want it.
				if (isset($reverse_proxies) && match_cidr($ip, $reverse_proxies))
					continue;

				// Otherwise, we've got an IP!
				$_SERVER['BAN_CHECK_IP'] = $ip;
				break;
			}
		}
		// Otherwise just use the only one.
		elseif (!match_internal_subnets($_SERVER['HTTP_X_FORWARDED_FOR']) || $internal_subnet)
			$_SERVER['BAN_CHECK_IP'] = $_SERVER['HTTP_X_FORWARDED_FOR'];
	}

	// Is this a page requested through jQuery? If yes, set hide_chrome to skip all top and sidebar templates
	if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest')
		$context['is_ajax'] = $context['hide_chrome'] = true;

	// Make sure we know the URL of the current request.
	if (empty($_SERVER['REQUEST_URI']))
		$_SERVER['REQUEST_URL'] = $scripturl . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
	else
		$_SERVER['REQUEST_URL'] = 'http' . (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) != 'off' ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

	// And make sure HTTP_USER_AGENT is set.
	$_SERVER['HTTP_USER_AGENT'] = isset($_SERVER['HTTP_USER_AGENT']) ? htmlspecialchars(wesql::unescape_string($_SERVER['HTTP_USER_AGENT']), ENT_QUOTES) : '';

	// Some final checking.
	// !!! This is irrelevant now or at the very least, in need of updating.
	/*
	if (preg_match('~^((([1]?\d)?\d|2[0-4]\d|25[0-5])\.){3}(([1]?\d)?\d|2[0-4]\d|25[0-5])$~', $_SERVER['BAN_CHECK_IP']) === 0)
		$_SERVER['BAN_CHECK_IP'] = '';
	if ($_SERVER['REMOTE_ADDR'] == 'unknown')
		$_SERVER['REMOTE_ADDR'] = '';*/
}

/**
 * Given an array, this function ensures every key and value is escaped for database purposes.
 *
 * This function traverses an array - including nested arrays as appropriate (calling itself recursively as necessary), and runs every array value through the string escaper as used by the database library (typically mysql_real_escape_string()). Uses two underscores to guard against overloading.
 *
 * @param mixed $var Either an array or string; if a string, simply return the string escaped, otherwise traverse the array and calls itself on each element of the array (and calls the DB escape string on the array keys) - typically the contents will be non-array, so will be escaped, and the routine will bubble back up through the recursive layers.
 * @return mixed The effective return is a string or array whose entire key/value pair set has been escaped; as a recursive function some calls will only be returning strings back to itself.
 */
function escapestring__recursive($var)
{
	if (!is_array($var))
		return wesql::escape_string($var);

	// Reindex the array with slashes.
	$new_var = array();

	// Add slashes to every element, even the indexes!
	foreach ($var as $k => $v)
		$new_var[wesql::escape_string($k)] = escapestring__recursive($v);

	return $new_var;
}

/**
 * Given an array, this function ensures that every value has all HTML special characters converted to entities.
 *
 * - Uses two underscores to guard against overloading.
 * - Unlike most of the other __recursive sanitation functions, this function only applies to array values, not to keys.
 * - Attempts to use the UTF-8-ified function if available (for dealing with other special entities)
 *
 * @param mixed $var Either an array or string; if a string, simply return the string having entity-converted, otherwise traverse the array and calls itself on each element of the array - typically the contents will be non-array, so will be converted, and the routine will bubble back up through the recursive layers.
 * @param int $level Maximum recursion level is set to 25. This is a counter for internal use.
 * @return mixed The effective return is a string or array whose value set will have had HTML control characters converted to entities; as a recursive function some calls will only be returning strings back to itself.
 */
function htmlspecialchars__recursive($var, $level = 0)
{
	if (!is_array($var))
		return is_callable('westr::htmlspecialchars') ? westr::htmlspecialchars($var, ENT_QUOTES) : htmlspecialchars($var, ENT_QUOTES);

	// Add the htmlspecialchars to every element.
	foreach ($var as $k => $v)
		$var[$k] = $level > 25 ? null : htmlspecialchars__recursive($v, $level + 1);

	return $var;
}

/**
 * Given an array, this function ensures that every key and value has all %xx URL encoding converted to its regular characters.
 *
 * Uses two underscores to guard against overloading.
 *
 * @param mixed $var Either an array or string; if a string, simply return the string without %xx URL encoding, otherwise traverse the array and calls itself on each element of the array (and decodes any %xx URL encoding in the array keys) - typically the contents will be non-array, so will be slash-stripped, and the routine will bubble back up through the recursive layers.
 * @param int $level Maximum recursion level is set to 25. This is a counter for internal use.
 * @return mixed The effective return is a string or array whose entire key/value pair set have had %xx URL encoding decoded; as a recursive function some calls will only be returning strings back to itself.
 */
function urldecode__recursive($var, $level = 0)
{
	if (!is_array($var))
		return urldecode($var);

	// Reindex the array...
	$new_var = array();

	// Add the htmlspecialchars to every element.
	foreach ($var as $k => $v)
		$new_var[urldecode($k)] = $level > 25 ? null : urldecode__recursive($v, $level + 1);

	return $new_var;
}

/**
 * Given an array, this function ensures every value is unescaped for database purposes - counterpart to {@link escapestring__recursive()}
 *
 * This function traverses an array - including nested arrays as appropriate (calling itself recursively as necessary), and runs every array value through the string un-escaper as used by the database library (custom function to strip the escaping). Uses two underscores to guard against overloading.
 *
 * @param mixed $var Either an array or string; if a string, simply return the string unescaped, otherwise traverse the array and calls itself on each element of the array (and calls the DB unescape string on the array keys) - typically the contents will be non-array, so will be unescaped, and the routine will bubble back up through the recursive layers.
 * @return mixed The effective return is a string or array whose entire key/value pair set has been unescaped; as a recursive function some calls will only be returning strings back to itself.
 */
function unescapestring__recursive($var)
{
	if (!is_array($var))
		return wesql::unescape_string($var);

	// Reindex the array without slashes, this time.
	$new_var = array();

	// Strip the slashes from every element.
	foreach ($var as $k => $v)
		$new_var[wesql::unescape_string($k)] = unescapestring__recursive($v);

	return $new_var;
}

/**
 * Given an array, this function ensures that every key and value has all backslashes removed.
 *
 * Uses two underscores to guard against overloading.
 *
 * @param mixed $var Either an array or string; if a string, simply return the string without slashes, otherwise traverse the array and calls itself on each element of the array (and removes the slashes on the array keys) - typically the contents will be non-array, so will be slash-stripped, and the routine will bubble back up through the recursive layers.
 * @param int $level Maximum recursion level is set to 25. This is a counter for internal use.
 * @return mixed The effective return is a string or array whose entire key/value pair set have had slashes removed; as a recursive function some calls will only be returning strings back to itself.
 */
function stripslashes__recursive($var, $level = 0)
{
	if (!is_array($var))
		return stripslashes($var);

	// Reindex the array without slashes, this time.
	$new_var = array();

	// Strip the slashes from every element.
	foreach ($var as $k => $v)
		$new_var[stripslashes($k)] = $level > 25 ? null : stripslashes__recursive($v, $level + 1);

	return $new_var;
}

/**
 * Given an array, this function ensures that every value will have had whitespace removed from either end.
 *
 * - Uses two underscores to guard against overloading.
 * - Unlike most of the other __recursive sanitation functions, this function only applies to array values, not to keys.
 * - Attempts to use the UTF-8-ified function if available (for dealing with other special entities)
 * - Remove spaces (32), tabs (9), returns (13, 10, and 11), nulls (0), and hard spaces. (160)
 *
 * @param mixed $var Either an array or string; if a string, simply return the string having entity-converted, otherwise traverse the array and calls itself on each element of the array - typically the contents will be non-array, so will be converted, and the routine will bubble back up through the recursive layers.
 * @param int $level Maximum recursion level is set to 25. This is a counter for internal use.
 * @return mixed The effective return is a string or array whose value set will have had HTML control characters converted to entities; as a recursive function some calls will only be returning strings back to itself.
 */
function htmltrim__recursive($var, $level = 0)
{
	if (!is_array($var))
		return is_callable('westr::htmltrim') ? westr::htmltrim($var) : trim($var, ' ' . "\t\n\r\x0B" . '\0' . "\xA0");

	// Go through all the elements and remove the whitespace.
	foreach ($var as $k => $v)
		$var[$k] = $level > 25 ? null : htmltrim__recursive($v, $level + 1);

	return $var;
}

/**
 * Collects the headers for this page request.
 *
 * - Uses apache_request_headers() if running on Apache as a CGI module (recommended).
 * - If this is not available, $_SERVER will be examined for HTTP_ variables which should translate to headers. (This process works on PHP-CLI, IIS and lighttpd at least)
 *
 * @return array A key/value pair of the HTTP headers for this request.
 */
function get_http_headers()
{
	if (is_callable('apache_request_headers'))
		return apache_request_headers();

	$headers = array();
	foreach ($_SERVER as $key => $value)
		if (strpos($key, 'HTTP_') === 0)
			$headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))))] = $value;
	return $headers;
}

/**
 * Returns whether the supplied IP address is within an internal subnet, e.g. IPv4's 127.*
 *
 * @param string $ip Expanded form IP address (see {@link expand_ip()} for more)
 * @return bool Whether the IP is within the designated ranges.
 * @todo Update this function if more IPv6 ranges become an issue.
 */
function match_internal_subnets($ip)
{
	// IPv6 loopback, or invalid IP? Treat 'em the same. (It's 31 hex digits, last octet can be any value and still be a loopback.
	if (strpos($ip, '000000000000000000000000000000') === 0)
		return true;

	// OK, IPv4 subnets right now?
	if (is_ipv4($ip))
	{
		$first = substr($ip, 24, 2);
		// Most common IPv4 subnets, 127.*, 255.*, 10.*, 0.*, 192.168.*
		if ($first === '7f' || $first === 'ff' || $first === '0a' || $first === '00' || ($first === 'c0' && substr($ip, 26, 2) === 'a8'))
			return true;
		// Or, 172.16-31
		if ($first === 'ac')
		{
			$second = hexdec(substr($ip, 26, 2));
			if ($second >= 16 && $second <= 31)
				return true;
		}
	}

	return false;
}

/**
 * Converts an IP address from either IPv4, or IPv6 form into the 32 hexdigit string used internally.
 *
 * @param string $ip An IP address in IPv4 (x.y.z.a), IPv4 over IPv6 (::ffff:x.y.z.a) or IPv6 (x:y:z:a::b) type formats
 * @return string A 32 hexcharacter string, all 0 if the incoming address was not valid.
 */
function expand_ip($ip)
{
	static $ip_array = array();
	if (isset($ip_array[$ip]))
		return $ip_array[$ip];

	// OK, so what are we dealing with?
	$contains_v4 = strpos($ip, '.') !== false;
	$contains_v6 = strpos($ip, ':') !== false;

	if ($contains_v4)
	{
		// So it's IPv4 in some form. Is it x.y.z.a or ::ffff:x.y.z.a ?
		if ($contains_v6)
		{
			// OK, so it's probably ::ffff:x.y.z.a format, let's do something about that.
			if (strpos($ip, '::ffff:') !== 0)
				return INVALID_IP; // oops, it wasn't valid since this is the only valid prefix for this format.
			$ip = substr($ip, 7);
		}

		if (!preg_match('~^((([1]?\d)?\d|2[0-4]\d|25[0-5])\.){3}(([1]?\d)?\d|2[0-4]\d|25[0-5])$~', $ip))
			return INVALID_IP; // oops, not a valid IPv4 either

		// It's just x.y.z.a
		$ipv6 = '00000000000000000000ffff';
		$ipv4 = explode('.', $ip);
		foreach ($ipv4 as $octet)
			$ipv6 .= str_pad(dechex($octet), 2, '0', STR_PAD_LEFT);
		return $ip_array[$ip] = $ipv6;
	}
	elseif ($contains_v6)
	{
		if (strpos($ip, '::') !== false)
		{
			$pieces = explode('::', $ip);
			if (count($pieces) !== 2)
				return INVALID_IP; // can't be valid!

			// OK, so how many blocks do we have that are actual blocks?
			$before_pieces = explode(':', $pieces[0]);
			$after_pieces = explode(':', $pieces[1]);
			foreach ($before_pieces as $k => $v)
				if ($v == '')
					unset($before_pieces[$k]);
			foreach ($after_pieces as $k => $v)
				if ($v == '')
					unset($after_pieces[$k]);
			// Glue everything back together.
			$ip = preg_replace('~((?<!\:):$)~', '', $pieces[0] . (count($before_pieces) ? ':' : '') . str_repeat('0:', 8 - (count($before_pieces) + count($after_pieces))) . $pieces[1]);
		}

		$ipv6 = explode(':', $ip);
		foreach ($ipv6 as $k => $v)
			$ipv6[$k] = str_pad($v, 4, '0', STR_PAD_LEFT);
		return $ip_array[$ip] = implode('', $ipv6);
	}

	// Just in case we don't know what this is, return *something* (if it contains neither IPv4 nor IPv6, bye)
	return INVALID_IP;
}

/**
 * Converts a 32 hexdigit string into human readable IP address format.
 *
 * @param string $ip An IP address in 32 hexdigit (IPv6 long without : characters)
 * @return string A human readable IP address, in IPv4 dotted notation or shortened IPv6 as appropriate. If not a suitable format incoming, empty string will be returned.
 */
function format_ip($ip)
{
	static $ip_array = array();

	$ip = strtolower($ip);

	if (strlen($ip) != 32 || !preg_match('~[0-9a-f]{32}~', $ip))
		return '';

	if (isset($ip_array[$ip]))
		return $ip_array[$ip];

	// OK, folks, this is an address we haven't done before this page. Is it IPv4?
	if (is_ipv4($ip))
	{
		// It's IPv4. Grab each octet, convert to decimal, then amalgamate it before storing and returning.
		$ipv4 = array();
		for ($i = 0; $i <= 3; $i++)
			$ipv4[] = hexdec(substr($ip, 24 + $i * 2, 2));
		return $ip_array[$ip] = implode('.', $ipv4);
	}
	else
	{
		// It's IPv6. Part 1: re-separate the string, careful to strip any leading zeroes as we go but leaving a 0 behind if the octet were all zeroes.
		$ipv6 = str_split($ip, 4);
		$last_0 = false;
		foreach ($ipv6 as $k => $v)
			$ipv6[$k] = $v === '0000' ? '0' : ltrim($v);
		$ipv6 = implode(':', $ipv6);
		// Part 2: if possible truncate a single run of 0 double octets to a single ::. To simplify matching :0:, add : to the start and end
		$ipv6 = preg_replace('~(\:0)+\:~', '::', ':' . $ipv6 . ':', 1);
		// Part 3: we may have additional : we're not meant to, leading and trailing, so let's fix that too.
		$ipv6 = preg_replace('~(^\:(?!\:))|((?<!\:):$)~', '', $ipv6);
		return $ip_array[$ip] = $ipv6;
	}
}

/**
 * Shortcut test (for readability) to confirm whether a given IP address is IPv4 or IPv6
 *
 * The first 5 double-octets will be 0, followed by 2 octets of ff, equal to ::ffff:
 *
 * @param string $ip A 32 hex-character string indicating IPv6 style address in longform, without the separator colons.
 * @return bool Whether the address falls into the ::ffff: subnet or not (and is therefore IPv4 or not)
 */
function is_ipv4($ip)
{
	return strpos($ip, '00000000000000000000ffff') === 0;
}

/**
 * Obtains an IP address from the central IP address log, or alternatively, adds it to the log and returns the identifier for it.
 *
 * @param string $ip A 32 hex-character string indicating IPv6 style address in longform, without the separator colons.
 * @return int The id used in the log for this IP address. Will return 0 if the IP address was invalid, or could not be added to the log.
 */
function get_ip_identifier($ip)
{
	static $ip_array = array();

	$ip = strtolower($ip);

	if (strlen($ip) != 32 || !preg_match('~[0-9a-f]{32}~', $ip) || $ip == INVALID_IP)
		return 0;

	if (isset($ip_array[$ip]))
		return $ip_array[$ip];

	$query = wesql::query('
		SELECT id_ip
		FROM {db_prefix}log_ips
		WHERE member_ip = {string:ip}',
		array(
			'ip' => $ip,
		)
	);
	if ($row = wesql::fetch_row($query))
	{
		wesql::free_result($query);
		return $ip_array[$ip] = $row[0];
	}

	// Oops, not in the log, so cleanup then add to log.
	wesql::free_result($query);
	wesql::insert('insert',
		'{db_prefix}log_ips',
		array(
			'member_ip' => 'string',
		),
		array(
			$ip,
		),
		array(
			'id_ip',
		)
	);
	return wesql::insert_id();
}

?>