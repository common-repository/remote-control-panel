<?php	

class Sqldump {

	/**
	 * File name for sql dump. 
	 * 
	 * @var string
	 */
	public $dump_file = '';
	//private $search         = array( '\x00', '\x0a', '\x0d', '\x1a' );  //\x08\\x09, not required
	//private $replace        = array( '\0', '\n', '\r', '\Z' );

	/**
	 * Our own impelemtation of mysqldump which is the same as 
	 * a full backup. With some additional logic for keeping state.
	 *
	 * Will create the dump into the folder specified by $this->dump_folder.
	 * This function is heavily inspired by other Backup utils, most of the core
	 * concept and code comes from phpMyAdmin.
	 * 
	 * @return void
	 */
	public function mysqldump($table_name = '') 
	{
		global $table_prefix;

		$this->db = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$this->db->set_charset( DB_CHARSET );

		// Begin new backup of MySql
		$tables = $this->db->query( 'SHOW TABLES' );

		$sql  = "# --------------------------------------------------------\n";
		$sql .= "# WordPress :  MySQL full database backup\n";
		$sql .= "#\n";
		$sql .= "# Generated: " . date( 'Y-m-d h:i:s' ) . "\n";
		$sql .= "# Hostname: " . DB_HOST . "\n";
		$sql .= "# Database: " . $this->sql_backquote( DB_NAME ) . "\n";
		$sql .= "# --------------------------------------------------------\n\n\n";
		$this->write_sql($sql);
		
		if($table_name == '') {
			while($table = $tables->fetch_row()) { 
				$curr_table = $table[0];
				// Create the SQL statements
				$this->make_sql($curr_table );
			}
		} else {
			$this->make_sql($table_name);
		}
	}

	/**
	 * Reads the Database table in $table and creates
	 * SQL Statements for recreating structure and data
	 * Taken partially from phpMyAdmin and partially from
	 * Alain Wolf, Zurich - Switzerland
	 * Website: http://restkultur.ch/personal/wolf/scripts/db_backup/
	 *
	 * @param string $table
	 */
	private function make_sql($table ) 
	{
		global $table_prefix;

		$sql  = "# --------------------------------------------------------\n";
		$sql .= "# Table: " . $this->sql_backquote( $table ) . "\n";
		$sql .= "# --------------------------------------------------------\n";
		$this->write_sql($sql);

		// Add SQL statement to drop existing table	
		$sql  = "#\n";
		$sql .= "# Delete any existing table " . $this->sql_backquote( $table ) . "\n";
		$sql .= "#\n";
		$sql .= "\n";
		$sql .= "DROP TABLE IF EXISTS " . $this->sql_backquote( $table ) . ";\n";

		/* Table Structure */

		// Comment in SQL-file
		$sql .= "\n";
		$sql .= "#\n";
		$sql .= "# Table structure of table " . $this->sql_backquote( $table ) . "\n";
		$sql .= "#\n";

		// Get table structure
		$query = 'SHOW CREATE TABLE ' . $this->sql_backquote( $table );
		if($create = $this->db->query( $query )) {
			$row = $create->fetch_row();
			$sql .= $row[1] . ";";
			$create->free();
		}

		/* Table Contents */
		// Get table contents
		$query = '';
		$fields = join(",", $this->get_table_fields( $table, 't' ));
		$md5func = "md5(concat($fields))";
		$additional_where = '';
		if($table == $table_prefix . 'options') {
			$query .= " WHERE t.option_name NOT like '_transient_%'";
		}
		if(FALSE AND $pk) {
			$query = sprintf("SELECT t.* FROM %s t
				INNER JOIN %srcp_state s ON (s.table_name='%s' AND s.table_pk = %s)
				%s ORDER BY %s",
				$this->sql_backquote( $table ), $table_prefix, $table, 
				$this->sql_primary_key_val($pk, 't'), $additional_where, join(', ', $pk)) ;
		} else {
			$query = sprintf("SELECT t.*,%s as rcp_checksum from %s t %s", 
				$md5func, $this->sql_backquote( $table ), $additional_where);
		}
		$query = sprintf("SELECT t.* from %s t %s", 
			$this->sql_backquote( $table ), $additional_where);

		$result = $this->db->query( $query );

		$sql .= "\n";
		$sql .= "#\n";
		$sql .= "# Data contents of table " . $table . " (" . $result->num_rows . " records)\n";
		$sql .= "#\n";

		// Checks whether the field is numeric or not
		$info = $result->fetch_fields();
		foreach($info as $field)
		{
			if($field->orgtable == $table) {
				$field->name_bq =  $this->sql_backquote( $field->name );
				$field->is_numeric =  $this->field_is_numeric($field);
				$field->skip = FALSE;
			} else {
				$field->skip = TRUE;
			}
		}
		$batch_write = 0;
		$checksums = array();
		$running_checksum = '';
		$this->write_sql( $sql );
		while ( $row = $result->fetch_object() )
		{
			$sql .= $this->create_insert_stmt($table, $row, $info);
			//$running_checksum = md5($running_checksum . $row->rcp_checksum);

			// write the rows in batches of 100
			if ( $batch_write === 10 ) 
			{
				$this->write_sql( $sql );
				//$checksums[] = $running_checksum;
				$batch_write = 0;
				$running_checksum = '';
			}

			$batch_write++;
		}
		//$checksums[] = $running_checksum;
		$result->free();

		// Create footer/closing comment in SQL-file
		$sql .= "\n";
		$sql .= "#\n";
		$sql .= "# End of data contents of table " . $table . "\n";
		$sql .= "# --------------------------------------------------------\n";
		$sql .= "\n";
		$this->write_sql( $sql );
		//file_put_contents('checksums_' . $table, json_encode($checksums));
	}

	/**
	 * Add backquotes to tables and db-names in SQL queries. Taken from phpMyAdmin.
	 *
	 * @access private
	 * @param mixed $a_name
	 */
	private function sql_backquote( $a_name ) 
	{
		if ( ! empty( $a_name ) && $a_name !== '*' ) 
		{
			if ( is_array( $a_name ) ) 
			{
				$result = array();
				reset( $a_name );
				while ( list( $key, $val ) = each( $a_name ) )
				{
					$result[$key] = '`' . $val . '`';
				}
				return $result;
			} 
			else 
			{
				return '`' . $a_name . '`';
			}
		} 
		else 
		{
			return $a_name;
		}
	}

	/**
	 * Create INSERT statement based for a row
	 *
	 *
	*/
	private function create_insert_stmt($table, $row, $info) {
		$values = array();
		foreach($info as $field) {
			if(isset($field->skip) && $field->skip == TRUE) continue;
			$fld_name = $field->name;
			if ( ! isset($row->$fld_name) ) {
				$values[]     = 'NULL';
			} elseif ( $row->$fld_name === '0' || $row->$fld_name !== '' ) {
				if($field->is_numeric) {
					$values[] = $row->$fld_name;
				}
				else {
					$values[] = "'" . @$this->db->real_escape_string($row->$fld_name) . "'";
				}			
			} 
			else 
			{
				$values[] = "''";
			}
		}
		return "INSERT INTO " . $this->sql_backquote($table) ." VALUES (" . implode(', ', $values ) . ") ;\n";
	}

	/**
	 * Better addslashes for SQL queries.
	 * Taken from phpMyAdmin.
	 *
	 * @access private
	 * @param string $a_string. (default: '')
	 * @param bool $is_like. (default: false)
	 */
	private function sql_addslashes( $a_string = '', $is_like = false ) 
	{
		if ( $is_like )
		{
			$a_string = str_replace( '\\', '\\\\\\\\', $a_string );
		}
		else
		{
			$a_string = str_replace( '\\', '\\\\', $a_string );
		}

		$a_string = str_replace( '\'', '\\\'', $a_string );
		return $a_string;
	}	

	/**
	 * Write the SQL file
	 *
	 * @access private
	 * @param string $sql
	 */
	private function write_sql(&$sql ) 
	{
		$sqlname = $this->dump_file;
		if ( is_writable( $sqlname ) || ! file_exists( $sqlname ) ) 
		{
			if ( ! $handle = @fopen( $sqlname, 'a' ) )
			{
				$sql = '';
				return;
			}
			if ( ! fwrite( $handle, $sql ) ) 
			{
				$sql = '';
				return;
			}

			fclose( $handle );
			$sql = '';
			return true;
	    }
	}

	/**
	 * Use Mysqli constants to check if a field is numeric
	 *
	 * @access private
	 * @param Field $field
	 */
	private function field_is_numeric($field) {
		switch($field->type) {
			case MYSQLI_TYPE_DECIMAL:
			case MYSQLI_TYPE_NEWDECIMAL:
			case MYSQLI_TYPE_BIT:
			case MYSQLI_TYPE_TINY:
			case MYSQLI_TYPE_SHORT:
			case MYSQLI_TYPE_LONG:
			case MYSQLI_TYPE_FLOAT:
			case MYSQLI_TYPE_DOUBLE:
			case MYSQLI_TYPE_LONGLONG:
			case MYSQLI_TYPE_INT24:
				return TRUE;
		}
		return FALSE;
	}

	/**
	 * Return array of all fields in table
	 *
	 */
	private function get_table_fields($table, $prefix = '') {
		$query = sprintf("select * from %s WHERE 1=0;",	$this->sql_backquote( $table ));
		$result = $this->db->query( $query );	
		if($prefix != '') $prefix = $prefix . '.';
		if ( $result ) 
		{
			$info = $result->fetch_fields();
			foreach($info as $field) {
				$fields[] = $prefix . $this->sql_backquote( $field->name );
			}
			return $fields;
		} else {
			return array();
		}
	}

}	
