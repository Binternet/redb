<?php

/**
 * reDB
 * Made to convert mysql to any structure that we wish
 *
 * @author Lior Broshi
 * 
 */
class Redb {

	var $db_old = '';
	var $db_new = '';

	var $xml = '';		# Content of XML file
	var $config = '';

	var $logfile = '';

	/**
	 * [__construct description]
	 * @param [type] $config [description]
	 */
	function __construct($config) {

		$this->logfile = fopen('logs/log-' . date('d-m-Y') . '.php','ab');

		$this->config = $config;

		$this->_open_db();
		$this->xml = new SimpleXMLElement( file_get_contents('rules.xml') );

		# Pre-queries
		$this->pre_convertion_queries();

		# And away we go
		$this->convert();	
	}

	/**
	 * [__destruct description]
	 */
	function __destruct() {
		fclose($this->logfile);
	}

	/**
	 * Gets a db instance
	 * @param  string $type new | old
	 * @return object       DB PDO Instance
	 */
	static function db_instance( $type = 'new' ) {
		global $config;

		if ( ! isset( $config["db_{$type}"] ) ) {
			throw new Exception( 'No configuration was found for DB: ' . $type );
		}

		$db_config = $config["db_{$type}"];

		$db = new PDO("mysql:host={$db_config['host']};dbname={$db_config['db_name']}", $db_config['username'],$db_config['password']);
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		return $db;
	}

	/**
	 * Opens db connection
	 */
	private function _open_db() {

		# Open connections
		try {

			$this->db_old = self::db_instance('old');
			$this->db_new = self::db_instance('new');
		#
			#var_dump($conn_old);
		} catch ( PDOException $e ) {
			$this->error($e->getMessage());
		}
	}


	/**
	 * Run 
	 * @return [type] [description]
	 */
	function pre_convertion_queries() {

		try {
			foreach ( $this->xml->xpath('pre_convertion_queries') as $item ) {
				foreach ( $item->children() as $query ) {

					# Default db
					$db = $this->db_new;

					$attributes = $this->get_attributes($query);

					# Perform on old db?
					if ( ! empty($attributes) AND $attributes['on'] == 'old' ) {
						$db = $this->db_old;
					}

					$this->log('Pre-Conversion Query: ' . $query);

					$db->query( $query );
				}
			}
		} catch (Exception $e) {
			$this->error($e->getMessage());
		}

	}

	function convert() {

		# Iterate and convert each table
		foreach ( $this->xml->xpath('table') as $table ) {
			$this->convert_table($table);
		}
	}

	/**
	 * Iterates over table records and converts each one of them to the new structure
	 * @param  object  	$table SimpleXML entry
	 */
	function convert_table($table) {

		try {

			# Get table attributes
			$attributes = $this->get_attributes($table);

			# Skip this table?
			if ( ! empty($attributes['skip']) AND $attributes['skip'] == 'true' ) {
				return;
			}

			# Get table rules
			$rules = $this->get_rules($table);

			# Set name
			if ( empty($attributes['name']) ) {
				throw new Exception( 'No Table name was set' );
			}

			$table_name = $attributes['name'];

			# Set destination name
			if ( empty($attributes['to']) ) {
				throw new Exception( 'No destination table was set for ' . $attributes['name'] );
			}
			$table_name_new = $attributes['to'];

			$this->log('Converting ' . $table_name_new . ' to ' . $table_name_new);

			# Truncate first?
			if ( ! empty($attributes['truncate']) AND $attributes['truncate'] == 'true' ) {
				$this->log('Truncating ' . $table_name_new);
				$this->db_new->query("TRUNCATE {$table_name_new}");
			}

			# onStart function
			if ( ! empty($attributes['onStart']) ) {
				$this->log('Firing onStart function: ' . $attributes['onStart']);
				call_user_func( $attributes['onStart'] );
			}

			# Get the all the old records
			$q = $this->db_old->query("SELECT * FROM {$table_name}");

			$count = 0;
			while ( $row = $q->fetch(PDO::FETCH_ASSOC) ) {

				# Set new record
				$new = $this->convert_record( $table_name, $row, $rules );

				# Create INSERT sql statement
				if ( ! empty($new['new_record']) ) {

					$sql = $this->_insert_string( $table_name_new, $new['new_record'] );

					# Execute
					$this->log('Executing Query: ' . $sql);
					$res = $this->db_new->query($sql);
				}
				
				$count++;

				# Query after insertion
				if ( ! empty($new['after']) ) {
					#$this->log('Executing Post-Query queries:');
					foreach ( $new['after'] as $query ) {
						$this->log('Executing POST-Query: ' . $query);
						$this->db_new->query( $query );
					}
				}

			}

			# onComplete Function
			if ( ! empty($attributes['onComplete']) ) {
				$this->log('Firing onComplete function: ' . $attributes['onComplete'] );
				call_user_func( $attributes['onComplete'] );
			}

			$this->log('Finished converting ' . $count . ' records from table ' . $table_name . ' to table ' . $table_name_new);

			echo 'Finished converting ' . $count . ' records from table ' . $table_name . ' to table ' . $table_name_new . '<br />';

		} catch ( PDOException $e ) {

			$args = $e->getTrace();
			$query = $args[0]['args'];
			echo '<h4>PDO Error Caught</h4>';
			dbug(array(
				'query'	=> $query,
				'info'	=>$e->errorInfo
			));

		}
		catch ( Exception $e ) {
			$this->error( $e->getMessage() );
			echo '<h4>Error Caught</h4>';
			dbug( $e->getMessage() );
			die();
		}
	}

	/**
	 * Returns a INSERT string
	 * @param  string $table Table name
	 * @param  array $row   Record array
	 * @return string        SQL Statement
	 */
	function _insert_string($table, $row) {
		$sql = 'INSERT INTO %s (%s) VALUES ("%s")';
		$fields = rtrim( implode( ',' , array_keys($row) ), ',' );
		$values = rtrim( implode( '","', array_values($row) ), ',' );
		$sql = sprintf( $sql, $table, $fields, $values );
		$sql = rtrim( $sql, ',' );
		return $sql;
	}


	/**
	 * Takes a `where` / `data` attribute and places the correct data within
	 * the curly brackets
	 * @param  [type] $old_record The old record
	 * @param  [type] $condition  The condition string such as
	 * @return [type]             [description]
	 */
	function apply_data_to_query($old_record, $condition) {
		foreach ( $old_record as $old_field => $old_value ) {
			$look_for = '{' . $old_field . '}';
			if ( strpos( $condition, $look_for ) !== FALSE ) {
				$condition = str_replace( $look_for, $old_value, $condition );
			}
		}

		return $condition;
	}

	/**
	 * Builds an update/insert query for post-query execution
	 * @param  [type] $old_record The old record that we got
	 * @param  [type] $to_field   Destination field
	 * @param  [type] $value      Current value (after function/convertion)
	 * @param  [type] $attributes XML Element attributes
	 * @return [type]             [description]
	 */
	function build_post_query( $old_record, $to_field, $value, $attributes ) {


		switch ( $attributes['type'] ) {

			case 'insert':

				# Set the correct data (insert)
				$values = $this->apply_data_to_query($old_record,$attributes['data']);
				$values = $this->convert_post_query_data_to_array($values);
				
				# Add the value that we need
				$values[ $to_field ] = "'" . $value . "'";

				$insert_keys = implode(',',array_keys($values));
				# Remove last comma
				$insert_keys = rtrim($insert_keys,',');

				$insert_values = implode(',',array_values($values));
				return sprintf("INSERT INTO %s (%s) VALUES (%s)", $attributes['table'], $insert_keys, $insert_values );
				
			break;

			case 'update':

				# Set the condition
				$condition = $this->apply_data_to_query($old_record, $attributes['where']);

				$value = "'" . $value . "'";
				return sprintf( "UPDATE %s SET %s = %s WHERE %s", $attributes['table'], $to_field, $value, $condition );
			
			break;

			default:
				$this->log('Unknown query type in ' . __METHOD__);
			break;
		}
	}

	/**
	 * Converts the result of `apply_data_to_query` to an array
	 * we use it in the insertion process (we need to seperate the keys/values)
	 * @param  [type] $data The output of `apply_data_to_query`
	 */
	function convert_post_query_data_to_array($data) {

		if ( empty($data) ) {
			throw new Exception(__METHOD__ . ' got an empty $data');
		}

		$data = explode(',',$data);
		$result = array();
		foreach ( $data as $entry ) {
			$entry = explode('=',$entry);
			$result[ $entry[0] ] = $entry[1];
		}
		return $result;
	}

	/**
	 * Creates an array of rules
	 * @param  object $table SimpleXML object
	 * @return array         Array of rules for that table
	 */
	function get_rules($table) {

		$array = array();

		foreach ( $table->children() as $entry ) {

			$from = (string)$entry->getName();

			$attributes = $this->get_attributes($entry);

			 $array[ $from ] = array(
			 		'to'			=>	(string)$entry,
			 		'attributes'	=>	$attributes
			 	);
		}
		return $array;
	}

	/**
	 * Gets the attributes for a SimpleXML elemenet
	 * @param  object $elem SimpleXML element
	 * @return array        Array of attributes
	 */
	function get_attributes($elem) {
		$array = array();
		foreach ( $elem->attributes() as $key => $val ) {
			$array[(string)$key] = (string)$val;
		}
		return $array;
	}

	/**
	 * Convert a specific record to the new structure
	 * @param  string $table  Destination table
	 * @param  array $record Desired record
	 * @param  array $rules  Rules to apply
	 * @return array         Array with the record and additional information
	 */
	function convert_record( $table, $record, $rules ) {

		# New Record structure
		$new_record = array();

		# Stuff to execute after new structure query is executed
		$after = array();

		# Iterate over each field in that record
		foreach ( $record as $field => $value ) {

			# Skip a field with no rule or destination
			if ( ! array_key_exists( $field, $rules ) OR empty($value) ) {
			//	$this->log('Skipping field `' . $field . '` (table ' . $table . ') since it has no rules for it.');
				continue;
			}

			# Set field rules and attributes (easier usage)
			$field_rules = $rules[$field];

			$attributes = ( isset( $field_rules['attributes'] ) ) ? $field_rules['attributes'] : NULL;

			/*
			|--------------------------------------------------------------------------
			| Apply Function on our value
			|--------------------------------------------------------------------------
			*/

			if ( isset( $attributes['function'] ) ) {

				# Get function
				$function = $this->_get_function( $attributes['function'] );

				# Execute
				if ( function_exists( $function['name'] ) ) {

					# Got arguments?
					if ( ! empty( $function['arguments'] ) ) {

						# Yep
						$args = explode( ',', $function['arguments'] );
						$args[] = $value;
						$fn = new ReflectionFunction( $function['name'] );
						$value = $fn->invokeArgs( $args );

					} else {
						# Nope
						$value = $function['name']( $value );
					}

				}
				else {
					// No such function
					$this->log('Unknown function: ' . $function['name']);
				}

			}

			/*
			|--------------------------------------------------------------------------
			| Different Destination Table (Update/Insert to a different table)
			|--------------------------------------------------------------------------
			*/

			# Try to see if we have a specific table for that field or we fetch it from the record
			if ( isset($attributes['table']) ) {

				if ( ! isset($attributes['type']) ) {
					throw new Exception( 'You must provide a query type (insert or update) when specifying a different destination table' );
				}

				# Set an `after` query for that field
				$after[] = $this->build_post_query( $record, $field_rules['to'], $value, $attributes );
			}

			/*
			|--------------------------------------------------------------------------
			| Skip this field?
			|--------------------------------------------------------------------------
			*/
		
			if ( isset($attributes['skip']) AND $attributes['skip'] == 'true' ) {
				continue;
			}

			/*
			|--------------------------------------------------------------------------
			| Clean the value and set in the new record array
			|--------------------------------------------------------------------------
			*/
		
			$value = $this->quotes_to_entities( $value );

			# Set in the new record
			$new_record[ $field_rules['to'] ] = $value;

		}

		# Return the record for execution
		return array(
			'new_record' =>	$new_record,
			'after'		 =>	$after
		);
	}

	/**
	 * Extracts the function name and attributes from `function` entry of an elemenet
	 * @param  string $function Function value from XML entry
	 * @return [type]           [description]
	 */
	function _get_function($function) {

		$parts = explode( '(', $function );

		// I should use regex here but I'm lazy and it works...
		return ( count($parts) == 2 )
			? array( 'name' => $parts[0], 'arguments' => rtrim( $parts[1], ')' ) )
			: array( 'name' => $function, 'arguments' => NULL );
	}

	/**
	 * Replaces quotes to HTML entities
	 * @param  string $str String
	 * @return string      Query-safe string
	 */
	function quotes_to_entities($str) {
		return str_replace(array("\'","\"","'",'"'), array("&#39;","&quot;","&#39;","&quot;"), $str);
	}

	/**
	 * Write to log file
	 * @param  [type] $str [description]
	 * @return [type]      [description]
	 */
	function log($str) {
		$prefix = '[' . date('d-m-Y H:i:s') . '] - ';
		fwrite( $this->logfile, $prefix . $str . "\n" );
	}

	/**
	 * Log an error
	 * @param  [type] $str [description]
	 * @return [type]      [description]
	 */
	function error($str) {
		return $this->log('ERROR: ' . $str);
	}
}