<?php
/**
 * Wedge
 *
 * Contains several package-related database operations, like creating new tables.
 *
 * @package wedge
 * @copyright 2010-2011 Wedgeward, wedge.org
 * @license http://wedge.org/license/
 *
 * @version 0.1
 */

if (!defined('WEDGE'))
	die('Hacking attempt...');

class wedbPackages
{
	protected static $reservedTables, $instance;

	public static function getInstance()
	{
		global $db_package_log, $db_prefix;

		// Quero ergo sum
		if (self::$instance == null)
		{
			// Things we do on creation; it's like a constructor but not quite.
			self::$instance = new self();
			self::$reservedTables = array();

			$reservedTables = array('admin_info_files', 'approval_queue', 'attachments', 'ban_groups', 'ban_items',
			'board_members', 'board_permissions', 'boards', 'calendar', 'calendar_holidays', 'categories', 'collapsed_categories',
			'custom_fields', 'drafts', 'group_moderators', 'log_actions', 'log_activity', 'log_boards', 'log_comments',
			'log_digest', 'log_errors', 'log_floodcontrol', 'log_group_requests', 'log_intrusion', 'log_mark_read', 'log_notify',
			'log_online', 'log_packages', 'log_polls', 'log_reported', 'log_reported_comments', 'log_scheduled_tasks',
			'log_search_messages', 'log_search_results', 'log_search_subjects', 'log_search_topics', 'log_topics', 'mail_queue',
			'membergroups', 'members', 'message_icons', 'messages', 'moderators', 'openid_assoc', 'package_servers',
			'permission_profiles', 'permissions', 'personal_messages', 'pm_recipients', 'pm_rules', 'poll_choices', 'polls',
			'pretty_topic_urls', 'pretty_urls_cache', 'scheduled_tasks', 'sessions', 'settings', 'smileys', 'spiders',
			'subscriptions', 'subscriptions_groups', 'themes', 'topics');

			foreach ($reservedTables as $k => $table_name)
				self::$reservedTables[$k] = strtolower($db_prefix . $table_name);

			// We in turn may need the extra stuff.
			wesql::extend('extra');
			$db_package_log = array();
		}

		return self::$instance;
	}

	// Create a table.
	public static function create_table($table_name, $columns, $indexes = array(), $parameters = array(), $if_exists = 'ignore', $error = 'fatal')
	{
		global $db_package_log, $db_prefix;

		// Strip out the table name, we might not need it in some cases
		$real_prefix = preg_match('~^(`?)(.+?)\\1\\.(.*?)$~', $db_prefix, $match) === 1 ? $match[3] : $db_prefix;

		// With or without the database name, the fullname looks like this.
		$full_table_name = str_replace('{db_prefix}', $real_prefix, $table_name);
		$table_name = str_replace('{db_prefix}', $db_prefix, $table_name);

		// First - no way do we touch SMF tables.
		if (in_array(strtolower($table_name), self::$reservedTables))
			return false;

		// Log that we'll want to remove this on uninstall.
		$db_package_log[] = array('remove_table', $table_name);

		$tables = wedbExtra::list_tables();
		if (in_array($full_table_name, $tables))
		{
			// This is a sad day... drop the table? If not, return false (error) by default.
			if ($if_exists == 'overwrite')
				self::drop_table($table_name);
			else
				return $if_exists == 'ignore';
		}

		// Righty - let's do the damn thing!
		$table_query = 'CREATE TABLE ' . $table_name . "\n" . '(';
		foreach ($columns as $column)
		{
			// Auto increment is easy here!
			if (!empty($column['auto']))
			{
				$default = 'auto_increment';
			}
			elseif (isset($column['default']) && $column['default'] !== null)
				$default = 'default \'' . wesql::escape_string($column['default']) . '\'';
			else
				$default = '';

			// Sort out the size... and stuff...
			$column['size'] = isset($column['size']) && is_numeric($column['size']) ? $column['size'] : null;
			list ($type, $size) = self::calculate_type($column['type'], $column['size']);

			// Allow unsigned integers
			$unsigned = in_array($type, array('int', 'tinyint', 'smallint', 'mediumint', 'bigint')) && !empty($column['unsigned']) ? 'unsigned ' : '';

			if ($size !== null)
				$type = $type . '(' . $size . ')';

			// Now just put it together!
			$table_query .= "\n\t`" .$column['name'] . '` ' . $type . ' ' . (!empty($unsigned) ? $unsigned : '') . (!empty($column['null']) ? '' : 'NOT NULL') . ' ' . $default . ',';
		}

		// Loop through the indexes next...
		foreach ($indexes as $index)
		{
			$columns = implode(',', $index['columns']);

			// Is it the primary?
			if (isset($index['type']) && $index['type'] == 'primary')
				$table_query .= "\n\t" . 'PRIMARY KEY (' . implode(',', $index['columns']) . '),';
			else
			{
				if (empty($index['name']))
					$index['name'] = implode('_', $index['columns']);
				$table_query .= "\n\t" . (isset($index['type']) && $index['type'] == 'unique' ? 'UNIQUE' : 'KEY') . ' ' . $index['name'] . ' (' . $columns . '),';
			}
		}

		// No trailing commas!
		if (substr($table_query, -1) == ',')
			$table_query = substr($table_query, 0, -1);

		$table_query .= ') ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci';

		// Create the table!
		wesql::query($table_query,
			array(
				'security_override' => true,
			)
		);
	}

	// Drop a table.
	public static function drop_table($table_name, $parameters = array(), $error = 'fatal')
	{
		global $db_prefix;

		// After stripping away the database name, this is what's left.
		$real_prefix = preg_match('~^(`?)(.+?)\\1\\.(.*?)$~', $db_prefix, $match) === 1 ? $match[3] : $db_prefix;

		// Get some aliases.
		$full_table_name = str_replace('{db_prefix}', $real_prefix, $table_name);
		$table_name = str_replace('{db_prefix}', $db_prefix, $table_name);

		// God no - dropping one of these = bad.
		if (in_array(strtolower($table_name), self::$reservedTables))
			return false;

		// Does it exist?
		if (in_array($full_table_name, wedbExtra::list_tables()))
		{
			$query = 'DROP TABLE ' . $table_name;
			wesql::query(
				$query,
				array(
					'security_override' => true,
				)
			);

			return true;
		}

		// Otherwise do 'nout.
		return false;
	}

	// Add a column.
	public static function add_column($table_name, $column_info, $parameters = array(), $if_exists = 'update', $error = 'fatal')
	{
		global $db_package_log, $txt, $db_prefix;

		$table_name = str_replace('{db_prefix}', $db_prefix, $table_name);

		// Log that we will want to uninstall this!
		$db_package_log[] = array('remove_column', $table_name, $column_info['name']);

		// Does it exist - if so don't add it again!
		$columns = self::list_columns($table_name, false);
		foreach ($columns as $column)
			if ($column == $column_info['name'])
			{
				// If we're going to overwrite then use change column.
				if ($if_exists == 'update')
					return self::change_column($table_name, $column_info['name'], $column_info);
				else
					return false;
			}

		// Get the specifics...
		$column_info['size'] = isset($column_info['size']) && is_numeric($column_info['size']) ? $column_info['size'] : null;
		list ($type, $size) = self::calculate_type($column_info['type'], $column_info['size']);

		// Allow unsigned integers
		$unsigned = in_array($type, array('int', 'tinyint', 'smallint', 'mediumint', 'bigint')) && !empty($column_info['unsigned']) ? 'unsigned ' : '';

		if ($size !== null)
			$type = $type . '(' . $size . ')';

		// Now add the thing!
		$query = '
			ALTER TABLE ' . $table_name . '
			ADD `' . $column_info['name'] . '` ' . $type . ' ' . (!empty($unsigned) ? $unsigned : '') . (empty($column_info['null']) ? 'NOT NULL' : '') . ' ' .
				(!isset($column_info['default']) ? '' : 'default \'' . wesql::escape_string($column_info['default']) . '\'') . ' ' .
				(empty($column_info['auto']) ? '' : 'auto_increment primary key') . ' ';
		wesql::query($query,
			array(
				'security_override' => true,
			)
		);

		return true;
	}

	// Remove a column.
	public static function remove_column($table_name, $column_name, $parameters = array(), $error = 'fatal')
	{
		global $db_prefix;

		$table_name = str_replace('{db_prefix}', $db_prefix, $table_name);

		// Does it exist?
		$columns = self::list_columns($table_name, true);
		foreach ($columns as $column)
			if ($column['name'] == $column_name)
			{
				wesql::query('
					ALTER TABLE ' . $table_name . '
					DROP COLUMN ' . $column_name,
					array(
						'security_override' => true,
					)
				);

				return true;
			}

		// If here we didn't have to work - joy!
		return false;
	}

	// Change a column.
	public static function change_column($table_name, $old_column, $column_info, $parameters = array(), $error = 'fatal')
	{
		global $db_prefix;

		$table_name = str_replace('{db_prefix}', $db_prefix, $table_name);

		// Check it does exist!
		$columns = self::list_columns($table_name, true);
		$old_info = null;
		foreach ($columns as $column)
			if ($column['name'] == $old_column)
				$old_info = $column;

		// Nothing?
		if ($old_info == null)
			return false;

		// Get the right bits.
		if (!isset($column_info['name']))
			$column_info['name'] = $old_column;
		if (!isset($column_info['default']))
			$column_info['default'] = $old_info['default'];
		if (!isset($column_info['null']))
			$column_info['null'] = $old_info['null'];
		if (!isset($column_info['auto']))
			$column_info['auto'] = $old_info['auto'];
		if (!isset($column_info['type']))
			$column_info['type'] = $old_info['type'];
		if (!isset($column_info['size']) || !is_numeric($column_info['size']))
			$column_info['size'] = $old_info['size'];
		if (!isset($column_info['unsigned']) || !in_array($column_info['type'], array('int', 'tinyint', 'smallint', 'mediumint', 'bigint')))
			$column_info['unsigned'] = '';

		list ($type, $size) = self::calculate_type($column_info['type'], $column_info['size']);

		// Allow for unsigned integers
		$unsigned = in_array($type, array('int', 'tinyint', 'smallint', 'mediumint', 'bigint')) && !empty($column_info['unsigned']) ? 'unsigned ' : '';

		if ($size !== null)
			$type = $type . '(' . $size . ')';

		wesql::query('
			ALTER TABLE ' . $table_name . '
			CHANGE COLUMN `' . $old_column . '` `' . $column_info['name'] . '` ' . $type . ' ' . (!empty($unsigned) ? $unsigned : '') . (empty($column_info['null']) ? 'NOT NULL' : '') . ' ' .
				(!isset($column_info['default']) ? '' : 'default \'' . wesql::escape_string($column_info['default']) . '\'') . ' ' .
				(empty($column_info['auto']) ? '' : 'auto_increment') . ' ',
			array(
				'security_override' => true,
			)
		);
	}

	// Add an index.
	public static function add_index($table_name, $index_info, $parameters = array(), $if_exists = 'update', $error = 'fatal')
	{
		global $db_package_log, $db_prefix;

		$table_name = str_replace('{db_prefix}', $db_prefix, $table_name);

		// No columns = no index.
		if (empty($index_info['columns']))
			return false;
		$columns = implode(',', $index_info['columns']);

		// No name - make it up!
		if (empty($index_info['name']))
		{
			// No need for primary.
			if (isset($index_info['type']) && $index_info['type'] == 'primary')
				$index_info['name'] = '';
			else
				$index_info['name'] = implode('_', $index_info['columns']);
		}
		else
			$index_info['name'] = $index_info['name'];

		// Log that we are going to want to remove this!
		$db_package_log[] = array('remove_index', $table_name, $index_info['name']);

		// Let's get all our indexes.
		$indexes = self::list_indexes($table_name, true);
		// Do we already have it?
		foreach ($indexes as $index)
		{
			if ($index['name'] == $index_info['name'] || ($index['type'] == 'primary' && isset($index_info['type']) && $index_info['type'] == 'primary'))
			{
				// If we want to overwrite simply remove the current one then continue.
				if ($if_exists != 'update' || $index['type'] == 'primary')
					return false;
				else
					self::remove_index($table_name, $index_info['name']);
			}
		}

		// If we're here we know we don't have the index - so just add it.
		if (!empty($index_info['type']) && $index_info['type'] == 'primary')
		{
			wesql::query('
				ALTER TABLE ' . $table_name . '
				ADD PRIMARY KEY (' . $columns . ')',
				array(
					'security_override' => true,
				)
			);
		}
		else
		{
			wesql::query('
				ALTER TABLE ' . $table_name . '
				ADD ' . (isset($index_info['type']) && $index_info['type'] == 'unique' ? 'UNIQUE' : 'INDEX') . ' ' . $index_info['name'] . ' (' . $columns . ')',
				array(
					'security_override' => true,
				)
			);
		}
	}

	// Remove an index.
	public static function remove_index($table_name, $index_name, $parameters = array(), $error = 'fatal')
	{
		global $db_prefix;

		$table_name = str_replace('{db_prefix}', $db_prefix, $table_name);

		// Better exist!
		$indexes = self::list_indexes($table_name, true);

		foreach ($indexes as $index)
		{
			// If the name is primary we want the primary key!
			if ($index['type'] == 'primary' && $index_name == 'primary')
			{
				// Dropping primary key?
				wesql::query('
					ALTER TABLE ' . $table_name . '
					DROP PRIMARY KEY',
					array(
						'security_override' => true,
					)
				);

				return true;
			}
			if ($index['name'] == $index_name)
			{
				// Drop the bugger...
				wesql::query('
					ALTER TABLE ' . $table_name . '
					DROP INDEX ' . $index_name,
					array(
						'security_override' => true,
					)
				);

				return true;
			}
		}

		// Not to be found ;(
		return false;
	}

	// Get the schema formatted name for a type.
	public static function calculate_type($type_name, $type_size = null, $reverse = false)
	{
		// MySQL is actually the generic baseline.
		return array($type_name, $type_size);
	}

	// Get table structure.
	public static function table_structure($table_name, $parameters = array())
	{
		global $db_prefix;

		$table_name = str_replace('{db_prefix}', $db_prefix, $table_name);

		return array(
			'name' => $table_name,
			'columns' => self::list_columns($table_name, true),
			'indexes' => self::list_indexes($table_name, true),
		);
	}

	// Return column information for a table.
	public static function list_columns($table_name, $detail = false, $parameters = array())
	{
		global $db_prefix;

		$table_name = str_replace('{db_prefix}', $db_prefix, $table_name);

		$result = wesql::query('
			SHOW FIELDS
			FROM {raw:table_name}',
			array(
				'table_name' => substr($table_name, 0, 1) == '`' ? $table_name : '`' . $table_name . '`',
			)
		);
		$columns = array();
		while ($row = wesql::fetch_assoc($result))
		{
			if (!$detail)
			{
				$columns[] = $row['Field'];
			}
			else
			{
				// Is there an auto_increment?
				$auto = strpos($row['Extra'], 'auto_increment') !== false ? true : false;

				// Can we split out the size?
				if (preg_match('~(.+?)\s*\((\d+)\)(?:(?:\s*)?(unsigned))?~i', $row['Type'], $matches) === 1)
				{
					$type = $matches[1];
					$size = $matches[2];
					if (!empty($matches[3]) && $matches[3] == 'unsigned')
						$unsigned = true;
				}
				else
				{
					$type = $row['Type'];
					$size = null;
				}

				$columns[$row['Field']] = array(
					'name' => $row['Field'],
					'null' => $row['Null'] != 'YES' ? false : true,
					'default' => isset($row['Default']) ? $row['Default'] : null,
					'type' => $type,
					'size' => $size,
					'auto' => $auto,
				);

				if (isset($unsigned))
				{
					$columns[$row['Field']]['unsigned'] = $unsigned;
					unset($unsigned);
				}
			}
		}
		wesql::free_result($result);

		return $columns;
	}

	// What about some index information?
	public static function list_indexes($table_name, $detail = false, $parameters = array())
	{
		global $db_prefix;

		$table_name = str_replace('{db_prefix}', $db_prefix, $table_name);

		$result = wesql::query('
			SHOW KEYS
			FROM {raw:table_name}',
			array(
				'table_name' => substr($table_name, 0, 1) == '`' ? $table_name : '`' . $table_name . '`',
			)
		);
		$indexes = array();
		while ($row = wesql::fetch_assoc($result))
		{
			if (!$detail)
				$indexes[] = $row['Key_name'];
			else
			{
				// What is the type?
				if ($row['Key_name'] == 'PRIMARY')
					$type = 'primary';
				elseif (empty($row['Non_unique']))
					$type = 'unique';
				elseif (isset($row['Index_type']) && $row['Index_type'] == 'FULLTEXT')
					$type = 'fulltext';
				else
					$type = 'index';

				// This is the first column we've seen?
				if (empty($indexes[$row['Key_name']]))
				{
					$indexes[$row['Key_name']] = array(
						'name' => $row['Key_name'],
						'type' => $type,
						'columns' => array(),
					);
				}

				// Is it a partial index?
				if (!empty($row['Sub_part']))
					$indexes[$row['Key_name']]['columns'][] = $row['Column_name'] . '(' . $row['Sub_part'] . ')';
				else
					$indexes[$row['Key_name']]['columns'][] = $row['Column_name'];
			}
		}
		wesql::free_result($result);

		return $indexes;
	}
}

?>