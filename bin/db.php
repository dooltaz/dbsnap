<?php

class db
{
	var $_connection;	// current db connection
	var $_qresult;	// current query result identifier
	var $errormsg;	// last error message
	var $errorno;	  // last error number
	var $arraybase=0;	  // return base-0 or base-1 data array (0/1)
	var $fieldcase="L";	// field names: case: "" = as is, "U"=upper, "L"=lower
	var $prevdatabase;	// the previously selected database
		
	// create object & connect to database using globals
	function db($host, $user, $pass, $dbname=null)
	{
		//
		// connect to database
	  $this->_connection = mysql_connect($host , $user , $pass);
	  if ($this->_connection){
		if (!empty($dbname)) {
		  	if (mysql_select_db($dbname, $this->_connection)){
		  		// okay
		  	} else {
			  	// MAJOR ERROR - COULD NOT CONNECT TO DATABASE!  (die for now)
				$this->errormsg = mysql_error();
				die("Could not select the database by name. " . $this->errormsg);
		  	}
		}
	  } else {
	  	// MAJOR ERROR - COULD NOT CONNECT TO DATABASE!  (die for now)
		$this->errormsg = mysql_error();
		die("Could not connect to database. " . $this->errormsg);
	  }
	  $this->prevdatabase = DB_NAME;
	}

	function selectDatabase($db_name) {
	  	if (mysql_select_db($db_name, $this->_connection)){
			$this->prevdatabase = $db_name;

	  	} else {
			mysql_select_db($this->prevdatabase, $this->_connection);
	  	}
	}

	// set the arraybase,fieldcase values
	// stashes the previous values, so you can call resetBaseCase to restore them
	function setBaseCase($base, $case)
	{
		$this->oldbase = $this->arraybase;
		$this->oldcase = $this->fieldcase;
		$this->arraybase = $base;
		$this->fieldcase = $case;
	}
	function resetBaseCase()
	{
		$this->arraybase = $this->oldbase;
		$this->fieldcase = $this->oldcase;
	}

	// ADDED Administrative Tracking for the system
        function log($query) {
                global $_COOKIE;
                $username = $_COOKIE['username'];
                $group_type = $_COOKIE['group_type'];
                $time = date("Y-m-d H:i:s");
                $query = addslashes($query);
                $sql = "insert into admin_log set `username`='$username', `group`='$group_type', " .
                        "`modify_time` = '$time', `query`='$query'";
                $res = mysql_query($sql, $this->_connection);
                return $res;
        }
	
////
//  this is a master querying routine
//  $query = a SQL SELECT query to run
//  $start = 1st record to return (not implemented yet)
//  $number = number of records to return (not implemented yet)
//
//  it returns an array:
//  array["fields"][1..x] = field names
//  array["error"] = error message
//  array["sql"] = the sql statement that was executed
//  array["rows"] = # of rows
//  array["cols"] = # of columns
//  array["data"][1..x]["field", "field", ...] = all rows
//	  note that if $this->arraybase=0, will return base-0 array
////
// 07/26/05 - Added the ability to cache query results as needed
//
	function querySelect($query, $start=0, $number=0, $cache=0)
	{

		$retval = array();
		$retval["sql"] = $query;
		$base = $this->arraybase;	//base-0 or base-1 arrays (4/1/03)
		
		//
		// Shortcut system to return the results of exact queries
		if ($cache > 0) {
			$md5 = md5($query);
			$filename = ROOT_PATH . "/var/dbcache/" . $md5 . ".dat";
			if (file_exists($filename)) {
				$s_data = file_get_contents($filename);
				$query_data = array();
				$query_data = unserialize($s_data);
				return $query_data;
			}
		}
		
		//
		// check connection first
	  if ($this->_connection){
	  	//
	  	// free the previous result (if any) first
	  	if ($this->_qresult) @mysql_free_result($this->_qresult);
	  	//
	  	// run new query, check for error
			$this->_qresult = mysql_query($query, $this->_connection);
			$this->errorno = mysql_errno();
			if ($this->errorno){
				$this->errormsg = mysql_error();
				$retval["error"] = $this->errormsg;
				return $retval;
			}
			//
			// process the results
			if ($this->_qresult && $this->errorno==0){
				// get row & field counts, store in $retval
		    $retval["rows"] = mysql_num_rows($this->_qresult);
		    $retval["cols"] = mysql_num_fields($this->_qresult);
				//
	      // now copy the field info into fields[]
	      $retval["fields"] = array();
	      for ($i=0; $i<$retval["cols"]; $i++){
	      	$field = mysql_fetch_field($this->_qresult, $i);
	        $retval["fields"][$i+$base] = $field->name;
	      }

		    // now copy the results into rows[]
		    $i = $base;
		    // TO DO: use mysql_data_seek() to move to a start record
		    while($row = mysql_fetch_array($this->_qresult)) {
		    	// for each field, store data
		    	for ($j=$base; $j<$retval["cols"]+$base; $j++){
		    		$fieldname = $retval["fields"][$j];
		    		$field2 = $fieldname;		// apply the new $fieldcase property here:
		    		switch ($this->fieldcase){
		    			case "U": $field2 = strtoupper($field2); break;
		    			case "L": $field2 = strtolower($field2); break;
		    		} // switch
		      	$retval["data"][$i][$field2] = $row[$fieldname];
		      }
		      $i++;
		    }
			} // if _qresult
	  } // if _connection

		if ($cache > 0) {
			$s = serialize($retval);
			$fp = fopen($filename, "w");
			fwrite($fp, $s);
			fclose($fp);
		}		
	  return $retval;
	}

////
//  fire an UPDATE query
//  returns the count of rows affected, -1 if error
////
	function queryUpdate($query)
	{

                global $is_admin;
		// REMOVED ADMIN TRACKING
		if ($is_admin) {
//                        $this->log($query);
                }
		$retval = 0;
		// check connection first
	  if ($this->_connection){
	  	// free the previous result (if any) first
	  	if ($this->_qresult) @mysql_free_result($this->_qresult);
	  	//
	  	// run new query
			$this->_qresult = mysql_query($query, $this->_connection);
			$this->errorno = mysql_errno();
			if ($this->_qresult && $this->errorno==0){
			  $retval = mysql_affected_rows();
			} else {
				$this->errormsg = mysql_error();
				$retval = -1;
			}
	  }
	  return $retval;
	}

////
//  fire an INSERT query
//  returns the id of newly inserted row, -1 if error
////
	function queryInsert($query)
	{
		$retval = $this->queryUpdate($query);
		if ($retval>-1){
			$retval = mysql_insert_id();
		}
		return $retval;
	}

////
//  fire a DELETE query
//  returns the number of rows affected
////
	function queryDelete($query)
	{
		$retval = $this->queryUpdate($query);
		return $retval;
	}

	//
	// put backticks around field names
	// can handle *, 'name' OR 'name' AS 'name', but not function(name)
	// 5/2/03 - if $s contains backticks, do nothing!
	function _backtickIt($s)
	{
		if ( substr_count($s, "`") > 0 ) return $s;

		$s = trim($s);
		if ($s=="*") return $s;
		$parts = explode(" AS ", $s);		// split at the "AS"
		//
		if (strpos("(", $parts[0])>0){	// if there's a (, must be a function call
			$retval = $parts[0];
		} else {
			// watch out for table aliases "table_name alias"
			// and field names like "table.field"
			if (strpos($parts[0],".")>0) $sep="."; else $sep=" ";
			//
			$parts2 = explode($sep, $parts[0]);
			// add backticks
			$retval = "`" . $parts2[0] . "`";
			if (isset($parts2[1])) $retval .= $sep."`" .$parts2[1]. "`";
		}
		if (isset($parts[1])){
			$retval .= " AS `" . $parts[1] . "`";
		}
		return $retval;
	}

	//
	// put single quotes around field values
	// can handle *, 'name' OR 'name' AS 'name', but not function(name)
	// note: mysql_real_escape_string() is PHP4.3+ and uses current charset of db connection
	// note: tried mysql_escape_string(), but it makes ' into \', which saves as \'  (ugh!)
	function _quoteIt($s)
	{
		$retval = "'" . $this->sqlFix($s) . "'";
		return $retval;
	}

	//
	// main interface for building SELECT queries
	// $tables = "table,table,..."
	// $fields = "field,field,..."
	// $where = "criteria,criteria,..."
	// $orderby = string
	function doSelect($tables, $fields, $where, $orderby="")
	{

		// expand the tables list, and backtick them all
		$parts = explode(",", $tables);
		$tables = "";
		for ($i=0; $i<count($parts); $i++){
			$tables .= $this->_backtickIt($parts[$i]);
			if ($i<count($parts)-1) $tables .= ",";
		}
		// expand the fields list, and backtick them all
		$parts = explode(",", $fields);
		$fields = "";
		for ($i=0; $i<count($parts); $i++){
			$fields .= $this->_backtickIt($parts[$i]);
			if ($i<count($parts)-1) $fields .= ",";
		}
		$query = "SELECT $fields FROM $tables ";
		if ($where>"") $query .= " WHERE $where";
		if ($orderby>"") $query .= " ORDER BY $orderby";
		return $this->querySelect($query);
	}

  // an alias for doSelect
	function select($tables, $fields, $where, $orderby="")
	{
		return $this->doSelect($tables, $fields, $where, $orderby);
	}

	//
	// main interface for building INSERT queries
	// $table = table name
	// $data = array["field"]="value", ["field"]="value"
  //  returns the id of newly inserted row, -1 if error
	function doInsert($table, $data)
	{

		$field_list = array();
		$value_list = array();
		$table = $this->_backtickIt($table);
		reset($data);
		$counter = 0;
		while (list($key,$value)=each($data)){
			$field_list[$counter] = $this->_backtickIt($key);
			$value_list[$counter++] = $this->_quoteIt($value);
		}
		$fields = implode(",", $field_list);
		$values = implode(",", $value_list);

		$query = "INSERT INTO $table($fields) VALUES($values)";        
		return $this->queryInsert($query);
	}
	// an alias for doInsert
	function insert($table, $data)
	{
		return $this->doInsert($table, $data);
	}

	//
	// main interface for building UPDATE queries
	// $table = table name
	// $data = array["field"]="value", ["field"]="value"
	// $where = "criteria", ex. "user_id=9"
  //  returns the count of rows affected, -1 if error
	function doUpdate($table, $data, $where)
	{

		$field_list = array();
		$value_list = array();
		$table = $this->_backtickIt($table);
		// insert the date_modified field here
		reset($data);
		$counter = 0;
		while (list($key,$value)=each($data)){
			$field_list[$counter] = $this->_backtickIt($key);
			$value_list[$counter++] = $this->_quoteIt($value);
		}
		$values = "";
		for ($i=0; $i<$counter; $i++){
			$values .= $field_list[$i] . "=" . $value_list[$i];
			if ($i<$counter-1) $values .= ",";
		}

		$query = "UPDATE $table SET $values ";
		if ($where>"") $query .= " WHERE $where";
		return $this->queryUpdate($query);
	}
	// an alias for doUpdate
	function update($table, $data, $where)
	{
		return $this->doUpdate($table, $data, $where);
	}

	function sqlFix($sql)
	{
	   $sql = stripslashes($sql);
	   $sql = str_replace("'", "''", $sql);
	   return $sql;
	}

////// Additional Functionality //////
	function chooseFields($data, $fields)
	{

		$retval=array();
		$fields = explode(",",$fields);
		for ($f=0;$f<count($fields);$f++){
			// these are either "FIELD" or "FIELD=VALUE"
			$parts = explode("=", $fields[$f]);
			$field = trim(strtolower($parts[0]));
			$value = isset($parts[1]) ? trim($parts[1]) : "";
			$retval[$field] = isset($data[$field]) ? $data[$field] : "$value";
		} // next f
		return $retval;
	}



//----------------- destroy:
	function destroy()
	{
	  if ($this->_qresult) @mysql_free_result($this->_qresult);
	  if ($this->_connection) @mysql_close($this->_connection);
	}

}

?>
