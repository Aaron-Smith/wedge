<?php
/**********************************************************************************
* Class-DB.php                                                                    *
***********************************************************************************
* SMF: Simple Machines Forum                                                      *
* Open-Source Project Inspired by Zef Hemel (zef@zefhemel.com)                    *
* =============================================================================== *
* Software Version:           SMF 2.0 RC4                                         *
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

if (!defined('SMF'))
	die('Hacking attempt...');

class wedb
{
	protected static $instance; // container for self
	protected static $_db_con; // store the database connection (normally)

	// What kind of class are you, anyway? One of a kind!
	private function __clone()
	{
		return false;
	}

	// Bootstrap's bootstraps
	public static function getInstance()
	{
		// Quero ergo sum
		if (self::$instance == null)
			self::$instance = new self();

		return self::$instance;
	}

	public static function is_connected()
	{
		return (bool) self::$_db_con;
	}

	public static function connect($db_server, $db_name, $db_user, $db_passwd, $db_prefix, $db_options = array())
	{
		global $mysql_set_mode;

		// Attempt to connect.
		if (!empty($db_options['persist']))
			$connection = @mysql_pconnect($db_server, $db_user, $db_passwd);
		else
			$connection = @mysql_connect($db_server, $db_user, $db_passwd);

		// Ooops, couldn't connect. See whether that should be a fatal or silent error.
		if (!$connection)
		{
			if (!empty($db_options['non_fatal']))
				return null;
			else
				show_db_error();
		}

		// Selecting a DB? If so, select it, and die if we couldn't.
		if (empty($db_options['dont_select_db']) && !@mysql_select_db($db_name, $connection) && empty($db_options['non_fatal']))
			show_db_error();

		if (isset($mysql_set_mode) && $mysql_set_mode === true)
			wedb::query('SET sql_mode = \'\', AUTOCOMMIT = 1',
			array(),
			false
		);

		// Otherwise set, and return true so that we can tell we did manage a connection.
		self::$_db_con = $connection;
		return self::$_db_con;
	}

	public static function extend($type = 'extra')
	{
		global $sourcedir;
		$type = ucfirst($type);

		require_once($sourcedir . '/Class-DB' . $type . '.php'); // loadSource is not available for this.
		call_user_func(array('wedb' . $type, 'getInstance')); // can't do this any other way prior to PHP 5.3.0 wherein $type::getInstance() is possible.
	}

	public static function fix_prefix(&$db_prefix, $db_name)
	{
		$db_prefix = is_numeric(substr($db_prefix, 0, 1)) ? $db_name . '.' . $db_prefix : '`' . $db_name . '`.' . $db_prefix;
	}

	public static function quote($db_string, $db_values, $connection = null)
	{
		global $db_callback;

		// Only bother if there's something to replace.
		if (strpos($db_string, '{') !== false)
		{
			// This is needed by the callback function.
			$db_callback = array($db_values, $connection == null ? self::$_db_con : $connection);

			// Do the quoting and escaping
			$db_string = preg_replace_callback('~{([a-z_]+)(?::([a-zA-Z0-9_-]+))?}~', 'wedb::value_replacement__callback', $db_string);

			// Clear this global variable.
			$db_callback = array();
		}

		return $db_string;
	}

	public static function query($db_string, $db_values = array(), $connection = null)
	{
		global $db_cache, $db_count, $db_show_debug, $time_start;
		global $db_unbuffered, $db_callback, $modSettings;

		// Comments that are allowed in a query are preg_removed.
		static $allowed_comments_from = array(
			'~\s+~s',
			'~/\*!40001 SQL_NO_CACHE \*/~',
			'~/\*!40000 USE INDEX \([A-Za-z\_]+?\) \*/~',
			'~/\*!40100 ON DUPLICATE KEY UPDATE id_msg = \d+ \*/~',
		);
		static $allowed_comments_to = array(
			' ',
			'',
			'',
			'',
		);

		// Decide which connection to use.
		$connection = $connection === null ? self::$_db_con : $connection;

		// One more query....
		$db_count = !isset($db_count) ? 1 : $db_count + 1;

		if (empty($modSettings['disableQueryCheck']) && strpos($db_string, '\'') !== false && empty($db_values['security_override']))
			wedb::error_backtrace('Hacking attempt...', 'Illegal character (\') used in query...', true, __FILE__, __LINE__);

		// Use "ORDER BY null" to prevent Mysql doing filesorts for Group By clauses without an Order By
		if (strpos($db_string, 'GROUP BY') !== false && strpos($db_string, 'ORDER BY') === false && strpos($db_string, 'INSERT INTO') === false)
		{
			// Add before LIMIT
			if ($pos = strpos($db_string, 'LIMIT '))
				$db_string = substr($db_string, 0, $pos) . "\t\t\tORDER BY null\n" . substr($db_string, $pos, strlen($db_string));
			else
				// Append it.
				$db_string .= "\n\t\t\tORDER BY null";
		}

		if (empty($db_values['security_override']) && (!empty($db_values) || strpos($db_string, '{db_prefix}') !== false))
		{
			// Pass some values to the global space for use in the callback function.
			$db_callback = array($db_values, $connection);

			// Inject the values passed to this function.
			$db_string = preg_replace_callback('~{([a-z_]+)(?::([a-zA-Z0-9_-]+))?}~', 'wedb::value_replacement__callback', $db_string);

			// This shouldn't be residing in global space any longer.
			$db_callback = array();
		}

		// Debugging.
		if (isset($db_show_debug) && $db_show_debug === true)
		{
			// Get the file and line number this function was called.
			list ($file, $line) = self::error_backtrace('', '', 'return', __FILE__, __LINE__);

			// Initialize $db_cache if not already initialized.
			if (!isset($db_cache))
				$db_cache = array();

			if (!empty($_SESSION['debug_redirect']))
			{
				$db_cache = array_merge($_SESSION['debug_redirect'], $db_cache);
				$db_count = count($db_cache) + 1;
				$_SESSION['debug_redirect'] = array();
			}

			$st = microtime();
			// Don't overload it.
			$db_cache[$db_count]['q'] = $db_count < 50 ? $db_string : '...';
			$db_cache[$db_count]['f'] = $file;
			$db_cache[$db_count]['l'] = $line;
			$db_cache[$db_count]['s'] = array_sum(explode(' ', $st)) - array_sum(explode(' ', $time_start));
		}

		// First, we clean strings out of the query, reduce whitespace, lowercase, and trim - so we can check it over.
		if (empty($modSettings['disableQueryCheck']))
		{
			$clean = '';
			$old_pos = 0;
			$pos = -1;
			while (true)
			{
				$pos = strpos($db_string, '\'', $pos + 1);
				if ($pos === false)
					break;
				$clean .= substr($db_string, $old_pos, $pos - $old_pos);

				while (true)
				{
					$pos1 = strpos($db_string, '\'', $pos + 1);
					$pos2 = strpos($db_string, '\\', $pos + 1);
					if ($pos1 === false)
						break;
					elseif ($pos2 == false || $pos2 > $pos1)
					{
						$pos = $pos1;
						break;
					}

					$pos = $pos2 + 1;
				}
				$clean .= ' %s ';

				$old_pos = $pos + 1;
			}
			$clean .= substr($db_string, $old_pos);
			$clean = trim(strtolower(preg_replace($allowed_comments_from, $allowed_comments_to, $clean)));

			// We don't use UNION in SMF, at least so far.  But it's useful for injections.
			if (strpos($clean, 'union') !== false && preg_match('~(^|[^a-z])union($|[^[a-z])~s', $clean) != 0)
				$fail = true;
			// Comments?  We don't use comments in our queries, we leave 'em outside!
			elseif (strpos($clean, '/*') > 2 || strpos($clean, '--') !== false || strpos($clean, ';') !== false)
				$fail = true;
			// Trying to change passwords, slow us down, or something?
			elseif (strpos($clean, 'sleep') !== false && preg_match('~(^|[^a-z])sleep($|[^[_a-z])~s', $clean) != 0)
				$fail = true;
			elseif (strpos($clean, 'benchmark') !== false && preg_match('~(^|[^a-z])benchmark($|[^[a-z])~s', $clean) != 0)
				$fail = true;
			// Sub selects?  We don't use those either, except in one case.
			elseif (preg_match('~\([^)]*?select(?! f\.friend_id)~s', $clean) != 0)
				$fail = true;

			if (!empty($fail) && function_exists('log_error'))
				self::error_backtrace('Hacking attempt...', 'Hacking attempt...' . "\n" . $db_string, E_USER_ERROR, __FILE__, __LINE__);
		}

		if (empty($db_unbuffered))
			$ret = @mysql_query($db_string, $connection);
		else
			$ret = @mysql_unbuffered_query($db_string, $connection);
		if ($ret === false && empty($db_values['db_error_skip']))
			$ret = self::serious_error($db_string, $connection);

		// Debugging.
		if (isset($db_show_debug) && $db_show_debug === true)
			$db_cache[$db_count]['t'] = array_sum(explode(' ', microtime())) - array_sum(explode(' ', $st));

		return $ret;
	}

	public static function affected_rows($connection = null)
	{
		return mysql_affected_rows($connection === null ? self::$_db_con : $connection);
	}
	
	public static function insert_id($connection = null)
	{
		$connection = $connection === null ? self::$_db_con : $connection;
		return mysql_insert_id($connection);
	}

	public static function transaction($operation = 'commit', $connection = null)
	{
		// Determine whether to use the known connection or not.
		$connection = $connection === null ? self::$_db_con : $connection;

		switch ($operation)
		{
				case 'begin':
				case 'rollback':
				case 'commit':
					return @mysql_query(strtoupper($operation), $connection);
			default:
				return false;
		}
	}

	public static function error($connection = null)
	{
		return mysql_error($connection === null ? self::$_db_con : $connection);
	}

	public static function serious_error($db_string, $connection = null)
	{
		global $txt, $context, $webmaster_email, $modSettings;
		global $forum_version, $db_last_error, $db_persist;
		global $db_server, $db_user, $db_passwd, $db_name, $db_show_debug, $ssi_db_user, $ssi_db_passwd;

		// Get the file and line numbers.
		list ($file, $line) = self::error_backtrace('', '', 'return', __FILE__, __LINE__);

		// Decide which connection to use
		$connection = $connection == null ? self::$_db_con : $connection;

		// This is the error message...
		$query_error = mysql_error($connection);
		$query_errno = mysql_errno($connection);

		// Error numbers:
		//    1016: Can't open file '....MYI'
		//    1030: Got error ??? from table handler.
		//    1034: Incorrect key file for table.
		//    1035: Old key file for table.
		//    1205: Lock wait timeout exceeded.
		//    1213: Deadlock found.
		//    2006: Server has gone away.
		//    2013: Lost connection to server during query.

		// Log the error.
		if ($query_errno != 1213 && $query_errno != 1205 && function_exists('log_error'))
			log_error($txt['database_error'] . ': ' . $query_error . (!empty($modSettings['enableErrorQueryLogging']) ? "\n\n$db_string" : ''), 'database', $file, $line);

		// Database error auto fixing ;).
		if (function_exists('cache_get_data') && (!isset($modSettings['autoFixDatabase']) || $modSettings['autoFixDatabase'] == '1'))
		{
			// Force caching on, just for the error checking.
			$old_cache = @$modSettings['cache_enable'];
			$modSettings['cache_enable'] = '1';

			if (($temp = cache_get_data('db_last_error', 600)) !== null)
				$db_last_error = max(@$db_last_error, $temp);

			if (@$db_last_error < time() - 3600 * 24 * 3)
			{
				// We know there's a problem... but what?  Try to auto detect.
				if ($query_errno == 1030 && strpos($query_error, ' 127 ') !== false)
				{
					preg_match_all('~(?:[\n\r]|^)[^\']+?(?:FROM|JOIN|UPDATE|TABLE) ((?:[^\n\r(]+?(?:, )?)*)~s', $db_string, $matches);

					$fix_tables = array();
					foreach ($matches[1] as $tables)
					{
						$tables = array_unique(explode(',', $tables));
						foreach ($tables as $table)
						{
							// Now, it's still theoretically possible this could be an injection.  So backtick it!
							if (trim($table) != '')
								$fix_tables[] = '`' . strtr(trim($table), array('`' => '')) . '`';
						}
					}

					$fix_tables = array_unique($fix_tables);
				}
				// Table crashed.  Let's try to fix it.
				elseif ($query_errno == 1016)
				{
					if (preg_match('~\'([^\.\']+)~', $query_error, $match) != 0)
						$fix_tables = array('`' . $match[1] . '`');
				}
				// Indexes crashed.  Should be easy to fix!
				elseif ($query_errno == 1034 || $query_errno == 1035)
				{
					preg_match('~\'([^\']+?)\'~', $query_error, $match);
					$fix_tables = array('`' . $match[1] . '`');
				}
			}

			// Check for errors like 145... only fix it once every three days, and send an email. (can't use empty because it might not be set yet...)
			if (!empty($fix_tables))
			{
				// Subs-Admin.php for updateSettingsFile(), Subs-Post.php for sendmail().
				loadSource(array('Subs-Admin', 'Subs-Post'));

				// Make a note of the REPAIR...
				cache_put_data('db_last_error', time(), 600);
				if (($temp = cache_get_data('db_last_error', 600)) === null)
					updateSettingsFile(array('db_last_error' => time()));

				// Attempt to find and repair the broken table.
				foreach ($fix_tables as $table)
					wedb::query("
						REPAIR TABLE $table", false, false);

				// And send off an email!
				sendmail($webmaster_email, $txt['database_error'], $txt['tried_to_repair']);

				$modSettings['cache_enable'] = $old_cache;

				// Try the query again...?
				$ret = self::query($db_string, false, false);
				if ($ret !== false)
					return $ret;
			}
			else
				$modSettings['cache_enable'] = $old_cache;

			// Check for the "lost connection" or "deadlock found" errors - and try it just one more time.
			if (in_array($query_errno, array(1205, 1213, 2006, 2013)))
			{
				if (in_array($query_errno, array(2006, 2013)) && self::$_db_con == $connection)
				{
					// Are we in SSI mode?  If so try that username and password first
					if (SMF == 'SSI' && !empty($ssi_db_user) && !empty($ssi_db_passwd))
					{
						if (empty($db_persist))
							self::$_db_con = @mysql_connect($db_server, $ssi_db_user, $ssi_db_passwd);
						else
							self::$_db_con = @mysql_pconnect($db_server, $ssi_db_user, $ssi_db_passwd);
					}
					// Fall back to the regular username and password if need be
					if (!$db_connection)
					{
						if (empty($db_persist))
							self::$_db_con = @mysql_connect($db_server, $db_user, $db_passwd);
						else
							self::$_db_con = @mysql_pconnect($db_server, $db_user, $db_passwd);
					}

					if (!self::$_db_con || !@mysql_select_db($db_name, self::$_db_con))
						self::$_db_con = false;
				}

				if (self::$_db_con)
				{
					// Try a deadlock more than once more.
					for ($n = 0; $n < 4; $n++)
					{
						$ret = self::query($db_string, false, false);

						$new_errno = mysql_errno($db_connection);
						if ($ret !== false || in_array($new_errno, array(1205, 1213)))
							break;
					}

					// If it failed again, shucks to be you... we're not trying it over and over.
					if ($ret !== false)
						return $ret;
				}
			}
			// Are they out of space, perhaps?
			elseif ($query_errno == 1030 && (strpos($query_error, ' -1 ') !== false || strpos($query_error, ' 28 ') !== false || strpos($query_error, ' 12 ') !== false))
			{
				if (!isset($txt))
					$query_error .= ' - check database storage space.';
				else
				{
					if (!isset($txt['mysql_error_space']))
						loadLanguage('Errors');

					$query_error .= !isset($txt['mysql_error_space']) ? ' - check database storage space.' : $txt['mysql_error_space'];
				}
			}
		}

		// Nothing's defined yet... just die with it.
		if (empty($context) || empty($txt))
			die($db_string . '<br /><br />' . $query_error);

		// Show an error message, if possible.
		$context['error_title'] = $txt['database_error'];
		if (allowedTo('admin_forum'))
			$context['error_message'] = nl2br($query_error) . '<br />' . $txt['file'] . ': ' . $file . '<br />' . $txt['line'] . ': ' . $line;
		else
			$context['error_message'] = $txt['try_again'];

		// A database error is often the sign of a database in need of upgrade. Check forum versions, and if not identical suggest an upgrade... (not for Demo/CVS versions!)
		if (allowedTo('admin_forum') && !empty($forum_version) && $forum_version != 'Wedge ' . @$modSettings['smfVersion'] && strpos($forum_version, 'Demo') === false && strpos($forum_version, 'CVS') === false)
			$context['error_message'] .= '<br /><br />' . sprintf($txt['database_error_versions'], $forum_version, $modSettings['smfVersion']);

		if (allowedTo('admin_forum') && isset($db_show_debug) && $db_show_debug === true)
		{
			$context['error_message'] .= '<br /><br />' . nl2br($db_string);
		}

		// It's already been logged... don't log it again.
		fatal_error($context['error_message'], false);
	}

	public static function insert($method = 'replace', $table, $columns, $data, $keys, $disable_trans = false, $connection = null)
	{
		global $db_prefix;

		$connection = $connection === null ? self::$_db_con : $connection;

		// With nothing to insert, simply return.
		if (empty($data))
			return;

		// Replace the prefix holder with the actual prefix.
		$table = str_replace('{db_prefix}', $db_prefix, $table);

		// Inserting data as a single row can be done as a single array.
		if (!is_array($data[array_rand($data)]))
			$data = array($data);

		// Create the mold for a single row insert.
		$insertData = '(';
		foreach ($columns as $columnName => $type)
		{
			// Are we restricting the length?
			if (strpos($type, 'string-') !== false)
				$insertData .= sprintf('SUBSTRING({string:%1$s}, 1, ' . substr($type, 7) . '), ', $columnName);
			else
				$insertData .= sprintf('{%1$s:%2$s}, ', $type, $columnName);
		}
		$insertData = substr($insertData, 0, -2) . ')';

		// Create an array consisting of only the columns.
		$indexed_columns = array_keys($columns);

		// Here's where the variables are injected to the query.
		$insertRows = array();
		foreach ($data as $dataRow)
			$insertRows[] = self::quote($insertData, array_combine($indexed_columns, $dataRow), $connection);

		// Determine the method of insertion.
		$queryTitle = $method == 'replace' ? 'REPLACE' : ($method == 'ignore' ? 'INSERT IGNORE' : 'INSERT');

		// Do the insert.
		self::query('
			' . $queryTitle . ' INTO ' . $table . '(`' . implode('`, `', $indexed_columns) . '`)
			VALUES
				' . implode(',
				', $insertRows),
			array(
				'security_override' => true,
				'db_error_skip' => $table === $db_prefix . 'log_errors',
			),
			$connection
		);
	}

	public static function value_replacement__callback($matches)
	{
		global $db_callback, $user_info, $db_prefix;

		list ($values, $connection) = $db_callback;
		if ($connection === null)
			$connection = self::$_db_con;

		if ($matches[1] === 'db_prefix')
			return $db_prefix;

		if ($matches[1] === 'query_see_topic')
			return $user_info['query_see_topic'];

		if ($matches[1] === 'query_see_board')
			return $user_info['query_see_board'];

		if ($matches[1] === 'query_wanna_see_board')
			return $user_info['query_wanna_see_board'];

		if (!isset($matches[2]))
			self::error_backtrace('Invalid value inserted or no type specified.', '', E_USER_ERROR, __FILE__, __LINE__);

		if (!isset($values[$matches[2]]))
			self::error_backtrace('The database value you\'re trying to insert does not exist: ' . htmlspecialchars($matches[2]), '', E_USER_ERROR, __FILE__, __LINE__);

		$replacement = $values[$matches[2]];

		switch ($matches[1])
		{
			case 'int':
				if (!is_numeric($replacement) || (string) $replacement !== (string) (int) $replacement)
					self::error_backtrace('Wrong value type sent to the database. Integer expected. (' . $matches[2] . ')', '', E_USER_ERROR, __FILE__, __LINE__);
				return (string) (int) $replacement;
			break;

			case 'string':
			case 'text':
				return sprintf('\'%1$s\'', mysql_real_escape_string($replacement, $connection));
			break;

			case 'array_int':
				if (is_array($replacement))
				{
					if (empty($replacement))
						self::error_backtrace('Database error, given array of integer values is empty. (' . $matches[2] . ')', '', E_USER_ERROR, __FILE__, __LINE__);

					foreach ($replacement as $key => $value)
					{
						if (!is_numeric($value) || (string) $value !== (string) (int) $value)
							self::error_backtrace('Wrong value type sent to the database. Array of integers expected. (' . $matches[2] . ')', '', E_USER_ERROR, __FILE__, __LINE__);

						$replacement[$key] = (string) (int) $value;
					}

					return implode(', ', $replacement);
				}
				else
					self::error_backtrace('Wrong value type sent to the database. Array of integers expected. (' . $matches[2] . ')', '', E_USER_ERROR, __FILE__, __LINE__);

			break;

			case 'array_string':
				if (is_array($replacement))
				{
					if (empty($replacement))
						self::error_backtrace('Database error, given array of string values is empty. (' . $matches[2] . ')', '', E_USER_ERROR, __FILE__, __LINE__);

					foreach ($replacement as $key => $value)
						$replacement[$key] = sprintf('\'%1$s\'', mysql_real_escape_string($value, $connection));

					return implode(', ', $replacement);
				}
				else
					self::error_backtrace('Wrong value type sent to the database. Array of strings expected. (' . $matches[2] . ')', '', E_USER_ERROR, __FILE__, __LINE__);
			break;

			case 'date':
				if (preg_match('~^(\d{4})-([0-1]?\d)-([0-3]?\d)$~', $replacement, $date_matches) === 1)
					return sprintf('\'%04d-%02d-%02d\'', $date_matches[1], $date_matches[2], $date_matches[3]);
				else
					self::error_backtrace('Wrong value type sent to the database. Date expected. (' . $matches[2] . ')', '', E_USER_ERROR, __FILE__, __LINE__);
			break;

			case 'float':
				if (!is_numeric($replacement))
					self::error_backtrace('Wrong value type sent to the database. Floating point number expected. (' . $matches[2] . ')', '', E_USER_ERROR, __FILE__, __LINE__);
				return (string) (float) $replacement;
			break;

			case 'identifier':
				// Backticks inside identifiers are supported as of MySQL 4.1. We don't need them for SMF.
				return '`' . strtr($replacement, array('`' => '', '.' => '')) . '`';
			break;

			case 'raw':
				return $replacement;
			break;

			default:
				self::error_backtrace('Undefined type used in the database query. (' . $matches[1] . ':' . $matches[2] . ')', '', false, __FILE__, __LINE__);
			break;
		}
	}

	public static function error_backtrace($error_message, $log_message = '', $error_type = false, $file = null, $line = null)
	{
		if (empty($log_message))
			$log_message = $error_message;

		foreach (debug_backtrace() as $step)
		{
			// Found it?
			if (strpos($step['function'], 'query') === false && (isset($step['class']) && $step['class'] !== 'wedb') && (!in_array(substr($step['function'], 0, 5), array('preg_re', 'mysql'))))
			{
				$log_message .= '<br />Function: ' . $step['function'];
				break;
			}

			if (isset($step['line']))
			{
				$file = $step['file'];
				$line = $step['line'];
			}
		}

		// A special case - we want the file and line numbers for debugging.
		if ($error_type == 'return')
			return array($file, $line);

		// Is always a critical error.
		if (function_exists('log_error'))
			log_error($log_message, 'critical', $file, $line);

		if (function_exists('fatal_error'))
		{
			fatal_error($error_message, false);

			// Cannot continue...
			exit;
		}
		elseif ($error_type)
			trigger_error($error_message . ($line !== null ? '<em>(' . basename($file) . '-' . $line . ')</em>' : ''), $error_type);
		else
			trigger_error($error_message . ($line !== null ? '<em>(' . basename($file) . '-' . $line . ')</em>' : ''));
	}

	public static function escape_wildcard_string($string, $translate_human_wildcards = false)
	{
		$replacements = array(
			'%' => '\%',
			'_' => '\_',
			'\\' => '\\\\',
		);

		if ($translate_human_wildcards)
			$replacements += array(
				'*' => '%',
			);

		return strtr($string, $replacements);
	}

	public static function fetch_assoc($result)
	{
		return mysql_fetch_assoc($result);
	}

	public static function fetch_row($result)
	{
		return mysql_fetch_row($result);
	}

	public static function free_result($result)
	{
		return mysql_free_result($result);
	}

	public static function data_seek($result, $row_num)
	{
		return mysql_data_seek($result, $row_num);
	}

	public static function num_fields($result)
	{
		return mysql_num_fields($result);
	}

	public static function num_rows($result)
	{
		return mysql_num_rows($result);
	}

	public static function escape_string($string)
	{
		return addslashes($string);
	}

	public static function unescape_string($string)
	{
		return stripslashes($string);
	}

	public static function server_info($connection = null)
	{
		return mysql_get_server_info($connection === null ? self::$_db_con : $connection);
	}

	public static function select_db($db_name, $connection = null)
	{
		$connection = $connection === null ? self::$_db_con : $connection;
		return mysql_select_db($db_name, $connection);
	}
}

?>