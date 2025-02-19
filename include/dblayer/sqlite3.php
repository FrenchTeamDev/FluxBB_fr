<?php

/**
 * Copyright (C) 2008-2012 FluxBB
 * based on code by Rickard Andersson copyright (C) 2002-2008 PunBB
 * License: http://www.gnu.org/licenses/gpl.html GPL version 2 or higher
 */

// Make sure we have built in support for SQLite
if (!class_exists('SQLite3'))
	exit('This PHP environment doesn\'t have SQLite3 support built in. SQLite3 support is required if you want to use a SQLite3 database to run this forum. Consult the PHP documentation for further assistance.');


require_once PUN_ROOT.'include/dblayer/interface.php';


class SqliteDBLayer implements DBLayer
{
	var $prefix;
	var $link_id;
	var $query_result;
	var $in_transaction = 0;

	var $last_query;			 
	var $saved_queries = array();
	var $num_queries = 0;

	var $error_no = false;
	var $error_msg = 'Unknown';

	var $datatype_transformations = array(
		'%^SERIAL$%'															=>	'INTEGER',
		'%^(TINY|SMALL|MEDIUM|BIG)?INT( )?(\\([0-9]+\\))?( )?(UNSIGNED)?$%i'	=>	'INTEGER',
		'%^(TINY|MEDIUM|LONG)?TEXT$%i'											=>	'TEXT'
	);


	function __construct($db_host, $db_username, $db_password, $db_name, $db_prefix, $p_connect)
	{
		// Prepend $db_name with the path to the forum root directory
		$db_name = PUN_ROOT.$db_name;

		$this->prefix = $db_prefix;

		if (!file_exists($db_name))
		{
			@touch($db_name);
			@chmod($db_name, 0666);
			if (!file_exists($db_name))
				error('Unable to create new SQLite3 database. Permission denied', __FILE__, __LINE__);
		}

		if (!is_readable($db_name))
			error('Unable to open SQLite3 database for reading. Permission denied', __FILE__, __LINE__);

		if (!forum_is_writable($db_name))
			error('Unable to open SQLite3 database for writing. Permission denied', __FILE__, __LINE__);

				 
		@$this->link_id = new SQLite3($db_name, SQLITE3_OPEN_READWRITE);
	  
																

		if (! $this->link_id instanceof SQLite3)
			error('Unable to open SQLite3 database.', __FILE__, __LINE__);
  

		if (defined('FORUM_SQLITE3_BUSY_TIMEOUT'))
			$this->link_id->busyTimeout(FORUM_SQLITE3_BUSY_TIMEOUT);

		if (defined('FORUM_SQLITE3_WAL_ON'))
			$this->link_id->exec('PRAGMA journal_mode=WAL;');

		$this->link_id->createFunction('CONCAT', function(...$args) {return implode('', $args);});
	}

	function start_transaction()
	{
		++$this->in_transaction;

		return ($this->link_id->exec('BEGIN TRANSACTION')) ? true : false;
	}


	function end_transaction()
	{
		--$this->in_transaction;

		if ($this->link_id->exec('COMMIT'))
			return true;
		else
		{
			$this->link_id->exec('ROLLBACK');
			return false;
		}
	}


	function query($sql, $unbuffered = false)
	{
		$this->last_query = $sql;				   
		if (defined('PUN_SHOW_QUERIES'))
			$q_start = microtime(true);

		$this->query_result = $this->link_id->query($sql);

		if ($this->query_result)
		{
			if (defined('PUN_SHOW_QUERIES'))
				$this->saved_queries[] = array($sql, sprintf('%.5F', microtime(true) - $q_start));

			++$this->num_queries;

			return $this->query_result;
		}
		else
		{
			if (defined('PUN_SHOW_QUERIES'))
				$this->saved_queries[] = array($sql, 0);

			$this->error_no = $this->link_id->lastErrorCode();
			$this->error_msg = $this->link_id->lastErrorMsg();

			if ($this->in_transaction > 0)
			{
				--$this->in_transaction;

				$this->link_id->exec('ROLLBACK');
			}
			return false;
		}
	}


	function result($query_id = 0, $row = 0, $col = 0)
	{
		if ($query_id)
		{
			$result_rows = array();
			while ($cur_result_row = @$query_id->fetchArray(SQLITE3_NUM))
				$result_rows[] = $cur_result_row;

			if (!empty($result_rows) && array_key_exists($row, $result_rows))
				$cur_row = $result_rows[$row];
				 

			if (isset($cur_row))
				return $cur_row[$col];
			else
				return false;
		}
		else
			return false;
	}


	function fetch_assoc($query_id = 0)
	{
		if ($query_id)
		{
			$cur_row = @$query_id->fetchArray(SQLITE3_ASSOC);
			if ($cur_row)
			{
				// Horrible hack to get rid of table names and table aliases from the array keys
				foreach ($cur_row as $key => $value)
				{
					$dot_spot = strpos($key, '.');
					if ($dot_spot !== false)
					{
						unset($cur_row[$key]);
						$key = substr($key, $dot_spot+1);
						$cur_row[$key] = $value;
					}
				}
			}

			return $cur_row;
		}
		else
			return false;
	}


	function fetch_row($query_id = 0)
	{
		return ($query_id) ? @$query_id->fetchArray(SQLITE3_NUM) : false;
	}


	function num_rows($query_id = 0)
	{
		if ($query_id && preg_match ('/\bSELECT\b/i', $this->last_query))
		{
			$num_rows_query = preg_replace ('/\bSELECT\b(.*)\bFROM\b/imsU', 'SELECT COUNT(*) FROM', $this->last_query);
			$result = $this->query($num_rows_query);

			return intval($this->result($result));
		}
		else
			return false;
	}


	function affected_rows()
	{
		return ($this->query_result) ? $this->link_id->changes() : false;
	}


	function insert_id()
	{
		return ($this->link_id) ? $this->link_id->lastInsertRowID() : false;
	}


	function get_num_queries()
	{
		return $this->num_queries;
	}


	function get_saved_queries()
	{
		return $this->saved_queries;
	}


	function free_result($query_id = false)
	{
		if ($query_id)
		{
			@$query_id->finalize();
		return true;
	}


	function escape($str)
	{
		return is_array($str) ? '' : $this->link_id->escapeString($str);
	}


	function error()
	{
		$result['error_sql'] = empty($this->saved_queries) ? '' : end($this->saved_queries);
		$result['error_no'] = $this->error_no;
		$result['error_msg'] = $this->error_msg;

		return $result;
	}


	function close()
	{
		if ($this->link_id)
		{
			if ($this->in_transaction > 0)
			{
				if (defined('PUN_SHOW_QUERIES'))
					$this->saved_queries[] = array('COMMIT', 0);
				
				--$this->in_transaction;

				$this->link_id->exec('COMMIT');
			}

			return @$this->link_id->close();
		}
		else
			return false;
	}


	function get_names()
	{
		return '';
	}


	function set_names($names)
	{
		return true;
	}


	function get_version()
	{
		$info = SQLite3::version();

		return array(
			'name'		=> 'SQLite3',
			'version'	=> $info['versionString']
		);
	}


	function table_exists($table_name, $no_prefix = false)
	{
		$result = $this->query('SELECT COUNT(type) FROM sqlite_master WHERE name = \''.($no_prefix ? '' : $this->prefix).$this->escape($table_name).'\' AND type=\'table\'');
		$table_exists = intval($this->result($result)) > 0;

		// Free results for DROP
		if ($result instanceof Sqlite3Result)
		{
			$this->free_result($result);
		}

		return $table_exists; 
	}


	function field_exists($table_name, $field_name, $no_prefix = false)
	{
		$result = $this->query('PRAGMA table_info(\'' . ($no_prefix ? '' : $this->prefix) . $this->escape($table_name) . '\');');
								
				

		if ($result instanceof Sqlite3Result)
		{
			while ($row = $this->fetch_assoc($result))
			{
				if ($row['name'] == $field_name)
				{
					$this->free_result($result);
					return true;
				}
			}
		}
		return false;  
	}


	function index_exists($table_name, $index_name, $no_prefix = false)
	{
		$result = $this->query('SELECT COUNT(type) FROM sqlite_master WHERE tbl_name = \''.($no_prefix ? '' : $this->prefix).$this->escape($table_name).'\' AND name = \''.($no_prefix ? '' : $this->prefix).$this->escape($table_name).'_'.$this->escape($index_name).'\' AND type=\'index\'');
		$index_exists = intval($this->result($result)) > 0;

		// Free results for DROP
		if ($result instanceof Sqlite3Result)
		{
			$this->free_result($result);
		}

		return $index_exists;	   
	}


	function create_table($table_name, $schema, $no_prefix = false)
	{
		if ($this->table_exists($table_name, $no_prefix))
			return true;

		$query = 'CREATE TABLE '.($no_prefix ? '' : $this->prefix).$table_name." (\n";

		// Go through every schema element and add it to the query
		foreach ($schema['FIELDS'] as $field_name => $field_data)
		{
			$field_data['datatype'] = preg_replace(array_keys($this->datatype_transformations), array_values($this->datatype_transformations), $field_data['datatype']);

			$query .= $field_name.' '.$field_data['datatype'];

			if (!$field_data['allow_null'])
				$query .= ' NOT NULL';

			if (isset($field_data['default']))
				$query .= ' DEFAULT '.$field_data['default'];

			$query .= ",\n";
		}

		// If we have a primary key, add it
		if (isset($schema['PRIMARY KEY']))
			$query .= 'PRIMARY KEY ('.implode(',', $schema['PRIMARY KEY']).'),'."\n";

		// Add unique keys
		if (isset($schema['UNIQUE KEYS']))
		{
			foreach ($schema['UNIQUE KEYS'] as $key_name => $key_fields)
				$query .= 'UNIQUE ('.implode(',', $key_fields).'),'."\n";
		}

		// We remove the last two characters (a newline and a comma) and add on the ending
		$query = substr($query, 0, strlen($query) - 2)."\n".')';

		$result = $this->query($query) ? true : false;

		// Add indexes
		if (isset($schema['INDEXES']))
		{
			foreach ($schema['INDEXES'] as $index_name => $index_fields)
				$result &= $this->add_index($table_name, $index_name, $index_fields, false, $no_prefix);
		}

		return $result;
	}


	function drop_table($table_name, $no_prefix = false)
	{
		if (!$this->table_exists($table_name, $no_prefix))
			return true;

		return $this->query('DROP TABLE '.($no_prefix ? '' : $this->prefix).$this->escape($table_name)) ? true : false;
	}


	function rename_table($old_table, $new_table, $no_prefix = false)
	{
		// If the old table does not exist
		if (!$this->table_exists($old_table, $no_prefix))
			return false;
		// If the table names are the same
		else if ($old_table == $new_table)
			return true;
		// If the new table already exists
		else if ($this->table_exists($new_table, $no_prefix))
			return false;

		$table = $this->get_table_info($old_table, $no_prefix);

		// Create new table
		$query = str_replace('CREATE TABLE '.($no_prefix ? '' : $this->prefix).$this->escape($old_table).' (', 'CREATE TABLE '.($no_prefix ? '' : $this->prefix).$this->escape($new_table).' (', $table['sql']);
		$result = $this->query($query) ? true : false;

		// Recreate indexes
		if (!empty($table['indices']))
		{
			foreach ($table['indices'] as $cur_index)
			{
				$query = str_replace('CREATE INDEX '.($no_prefix ? '' : $this->prefix).$this->escape($old_table), 'CREATE INDEX '.($no_prefix ? '' : $this->prefix).$this->escape($new_table), $cur_index);
				$query = str_replace('ON '.($no_prefix ? '' : $this->prefix).$this->escape($old_table), 'ON '.($no_prefix ? '' : $this->prefix).$this->escape($new_table), $query);
				$result &= $this->query($query) ? true : false;
			}
		}

		// Copy content across
		$result &= $this->query('INSERT INTO '.($no_prefix ? '' : $this->prefix).$this->escape($new_table).' SELECT * FROM '.($no_prefix ? '' : $this->prefix).$this->escape($old_table)) ? true : false;

		// Drop the old table if the new one exists
		if ($this->table_exists($new_table, $no_prefix))
			$result &= $this->drop_table($old_table, $no_prefix);

		return $result;
	}


	function get_table_info($table_name, $no_prefix = false)
	{
		// Grab table info
		$result = $this->query('SELECT sql FROM sqlite_master WHERE tbl_name = \''.($no_prefix ? '' : $this->prefix).$this->escape($table_name).'\' ORDER BY type DESC') or error('Unable to fetch table information', __FILE__, __LINE__, $this->error());
		

		$table = array();
		$table['indices'] = array();
		$num_rows = 0;		
		while ($cur_index = $this->fetch_assoc($result))
		{
			if (empty($cur_index['sql']))
				continue;

			if (!isset($table['sql']))
				$table['sql'] = $cur_index['sql'];
			else
				$table['indices'][] = $cur_index['sql'];
			 ++$num_rows;  
		}

		// Check for empty
		if ($num_rows < 1)
			return;


		// fix multiple fields in one line
		$table['sql'] = str_replace(', ', ",\n", $table['sql']);
		// Work out the columns in the table currently
		$table_lines = explode("\n", $table['sql']);
		$table['columns'] = array();
		foreach ($table_lines as $table_line)
		{
			$table_line = trim($table_line, " \t\n\r,"); // trim spaces, tabs, newlines, and commas
			if (substr($table_line, 0, 12) == 'CREATE TABLE')
				continue;
			else if (substr($table_line, 0, 11) == 'PRIMARY KEY')
				$table['primary_key'] = $table_line;
			else if (substr($table_line, 0, 6) == 'UNIQUE')
				$table['unique'] = $table_line;
			else if (substr($table_line, 0, strpos($table_line, ' ')) != '')
				$table['columns'][substr($table_line, 0, strpos($table_line, ' '))] = trim(substr($table_line, strpos($table_line, ' ')));
		}

		return $table;
	}


	function add_field($table_name, $field_name, $field_type, $allow_null, $default_value = null, $after_field = null, $no_prefix = false)
	{
		if ($this->field_exists($table_name, $field_name, $no_prefix))
			return true;
				 
		$field_type = preg_replace(array_keys($this->datatype_transformations), array_values($this->datatype_transformations), $field_type);

		$query = 'ALTER TABLE '.($no_prefix ? '' : $this->prefix).$this->escape($table_name).' ADD '.$field_name.' '.$field_type;

		if (!$allow_null)
			$query .= ' NOT NULL';

		if (is_string($default_value))
			$default_value = '\''.$this->escape($default_value).'\'';

		if (!is_null($default_value))
			$query .= ' DEFAULT '.$default_value;
		else if (!$allow_null)
			$query .= ' DEFAULT \'\'';
													  

		$this->query($query) or error(__FILE__, __LINE__);
		return true;
	}


	function alter_field($table_name, $field_name, $field_type, $allow_null, $default_value = null, $after_field = null, $no_prefix = false)
	{
		// Unneeded for SQLite
		return true;
	}


	function drop_field($table_name, $field_name, $no_prefix = false)
	{
		if (!$this->field_exists($table_name, $field_name, $no_prefix))
			return true;

		$table = $this->get_table_info($table_name, $no_prefix);

		// Create temp table
		$now = time();
		$tmptable = str_replace('CREATE TABLE '.($no_prefix ? '' : $this->prefix).$this->escape($table_name).' (', 'CREATE TABLE '.($no_prefix ? '' : $this->prefix).$this->escape($table_name).'_t'.$now.' (', $table['sql']);
		$result = $this->query($tmptable) ? true : false;
		$result &= $this->query('INSERT INTO '.($no_prefix ? '' : $this->prefix).$this->escape($table_name).'_t'.$now.' SELECT * FROM '.($no_prefix ? '' : $this->prefix).$this->escape($table_name)) ? true : false;

		// Work out the columns we need to keep and the sql for the new table
		unset($table['columns'][$field_name]);
		$new_columns = array_keys($table['columns']);

		$new_table = 'CREATE TABLE '.($no_prefix ? '' : $this->prefix).$this->escape($table_name).' (';

		foreach ($table['columns'] as $cur_column => $column_details)
			$new_table .= "\n".$cur_column.' '.$column_details.',';

		if (isset($table['unique']))
			$new_table .= "\n".$table['unique'].',';

		if (isset($table['primary_key']))
			$new_table .= "\n".$table['primary_key'].',';

		$new_table = trim($new_table, ',')."\n".');';

		// Drop old table
		$result &= $this->drop_table($table_name, $no_prefix);

		// Create new table
		$result &= $this->query($new_table) ? true : false;

		// Recreate indexes
		if (!empty($table['indices']))
		{
			foreach ($table['indices'] as $cur_index)
				if (!preg_match('%\('.preg_quote($field_name, '%').'\)%', $cur_index))
					$result &= $this->query($cur_index) ? true : false;
		}

		// Copy content back
		$result &= $this->query('INSERT INTO '.($no_prefix ? '' : $this->prefix).$this->escape($table_name).' SELECT '.implode(', ', $new_columns).' FROM '.($no_prefix ? '' : $this->prefix).$this->escape($table_name).'_t'.$now) ? true : false;

		// Drop temp table
		$result &= $this->drop_table($table_name.'_t'.$now, $no_prefix);

		return $result;
	}


	function add_index($table_name, $index_name, $index_fields, $unique = false, $no_prefix = false)
	{
		if ($this->index_exists($table_name, $index_name, $no_prefix))
			return true;

		return $this->query('CREATE '.($unique ? 'UNIQUE ' : '').'INDEX '.($no_prefix ? '' : $this->prefix).$table_name.'_'.$index_name.' ON '.($no_prefix ? '' : $this->prefix).$table_name.'('.implode(',', $index_fields).')') ? true : false;
	}


	function drop_index($table_name, $index_name, $no_prefix = false)
	{
		if (!$this->index_exists($table_name, $index_name, $no_prefix))
			return true;

		return $this->query('DROP INDEX '.($no_prefix ? '' : $this->prefix).$table_name.'_'.$index_name) ? true : false;
	}

	function truncate_table($table_name, $no_prefix = false)
	{
		return $this->query('DELETE FROM '.($no_prefix ? '' : $this->prefix).$table_name) ? true : false;
	}
}
