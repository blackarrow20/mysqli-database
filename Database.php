<?php
/**
 * A very lightweight class for easy handling of prepared SQL queries for MySQL.
 * Requirements:
 * PHP must support mysqli and use "Client API library" driver called mysqlnd (check your phpinfo() for those)
 *
 * @author    Armin Dajić
 * @copyright (c) 2015, Armin Dajić
 * @license   https://opensource.org/licenses/MIT The MIT License
 */

class Database {
    // Array of results data from database after you run runQuery method
    public $result = array();

    // Error message from last query
    public $error = "";

    // Will be set by function runQuery. 0 or -1 (error) mean that no rows were affected
    public $affectedRows = 0;
    
	// Object which represents the connection to the MySQL Server
    private $link;
    

    /**
     * Constructor for Database class.
     *
     * @param string $host      MySQL hostname
     * @param string $username  MySQL username
     * @param string $password  MySQL password
     * @param string $baseName MySQL name of the database
     * @return void
     * @throws Exception in case of failure to connect, or failure to select the database
     */
    public function __construct($host, $username, $password, $baseName) {
        $this->link = mysqli_connect($host, $username, $password);
        if (!$this->link) {
            throw new Exception(mysqli_connect_errno() . ": " . mysqli_connect_error());
        }
        $this->link->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1);
        $dbSelected = mysqli_select_db($this->link, $baseName);
        if (!$dbSelected) {
            throw new Exception("Error: Could not select the database " . $baseName);
        } 	
    }

    /**
     * Destructor for Database class.
     *
     * @return void
     */
    public function __destruct() {}

    /**
     * Run any SQL query in a safe way with prepared SQL statement.
     *
     * @param string $sqlPrepare   SQL prepare statement template
     * @param array  $variables    Array of variables to bind into the SQL prepare statement
     * @param string $errorMsg     In case of error running a query, $this->error will be set to $errorMsg,
     *                             but maybe also concatenated with SQL error details (if you set $withSqlError to true)
     * @param bool   $withSqlError In case of error running a query, $this->error will be set to $errorMsg,
     *                             but maybe also concatenated with SQL error details (if you set $withSqlError to true)
     * @param bool   $withResult   Do you want to fetch results of the query into $this->result?
     *                             If you expect results from the query set it to true, otherwise set to false.
     * @param bool   $autoBrackets Some SQL queries finish with substring (?,?,?,...,?), where number of '?' marks
     *                             is equal to the number of variables passed in $variables. If this is the case, set
     *                             $autoBrackets to true and (?,?,?,...,?) will be automatically added to $sqlPrepare.
     * @return void
     */
    public function runQuery($sqlPrepare, $variables = array(), $errorMsg = "", $withSqlError = true, $withResult = true, $autoBrackets = false) {
        $this->result = array();
        $this->error = "";
        $this->affectedRows = 0;
        if (strlen($errorMsg)==0 && $withSqlError == true) $errorMsg = "Error running query: ";
        if ($autoBrackets == true) {
            $sqlPrepare .= "(";
            for ($i=0; $i<count($variables); $i++) {
                $sqlPrepare .= ($i!=count($variables)-1)? "?," : "?)";
            }
        }
        $this->sqlSafe($sqlPrepare, $errorMsg, $withSqlError, $withResult, $variables);
    }

    /**
     * Get 1 character which describes type of variable for binding into prepared SQL statement
     *
     * @param mixed $var Variable for which we need to get 1 character which describes type of variable.
     *                   Acceptable types of $var: boolean, integer, double or string.
     * @return string 'i' for boolen, 'i' for integer, 'd' for double, 's' for string
     */
    protected function getTypeOfVariableForSQL($var) {
        $strType = gettype($var);
        switch ($strType) {
          case "boolean": return 'i';
          case "integer": return 'i';
          case "double": return 'd';
          case "string": return 's';
        }
    }

    /**
     * Fetch results (rows with columns) of SQL query
     *
     * @param object $statement Statement object, usually returned from function mysqli_prepare
     * @return array Rows with columns, fetched as results of the query from the database
     */
    protected function fetchResult($statement){
        $result = array();
        $statement->store_result();
        for ($i = 0; $i < $statement->num_rows; $i++) {
            $metadata = $statement->result_metadata();
            $params = array();
            while( $field = $metadata->fetch_field() ){
                $params[] = &$result[ $i ][ $field->name ];
            }
            call_user_func_array( array( $statement, 'bind_result' ), $params );
            $statement->fetch();
        }
        return $result;
    }

    /**
     * Execute SQL safely, fetch results into $this->result (if needed), set $this->error (if any), set $this->affected_rows
     *
     * @param string $sqlPrepare   SQL prepare statement template
     * @param string $errorMsg     In case of error running a query, $this->error will be set to $errorMsg,
     *                             but maybe also concatenated with SQL error details (if you set $withSqlError to true)
     * @param bool   $withSqlError In case of error running a query, $this->error will be set to $errorMsg,
     *                             but maybe also concatenated with SQL error details (if you set $withSqlError to true)
     * @param bool   $withResult   Do you want to fetch results of the query into $this->result?
     *                             If you expect results from the query set it to true, otherwise set to false.
     * @param array  $variables    Array of variables to bind into the SQL prepare statement
     * @return void
     */
    protected function sqlSafe($sqlPrepare, $errorMsg, $withSqlError, $withResult, $variables) {
        $n = count($variables);
        $types = '';
        for ($i=0; $i<$n; $i++)
            $types .= $this->getTypeOfVariableForSQL($variables[$i]);

        if ($n > 0) {
            // PREPARE
            if (!($stmt = mysqli_prepare($this->link, $sqlPrepare))) {
                $this->error = $withSqlError ? $errorMsg . "Invalid SQL syntax: " . mysqli_error($this->link) : $errorMsg;
            }

            // BIND
            if (!$this->ifErrorHappened() && count($variables)>0) {
                $a_params[] = &$types;
                for ($i = 0; $i < $n; $i++) $a_params[] = &$variables[$i];
                call_user_func_array(array($stmt, 'bind_param'), $a_params);
            }

            // EXECUTE
            if (!$this->ifErrorHappened()) {
                if (!$stmt->execute()) {
                    // Set error to concatenated strings $errorMsg and $stmt->error, or just $errorMsg
                    $this->error = $withSqlError ? $errorMsg . $stmt->error : $errorMsg;
                } 
                $this->affectedRows = $stmt->affected_rows;
            }

            // RESULT
            if (!$this->ifErrorHappened() && $withResult) {
                $this->result = $this->fetchResult($stmt);
            }	        
        } else {    	
            if (!($rezultat = $this->link->query($sqlPrepare))) { // Execute query without variables	        	
                $this->error = $withSqlError ? $errorMsg . mysqli_error($this->link) : $errorMsg;
            } else if ($withResult) { // Execution of query succeeded, now fetch result if necessary
                $this->result = array();
                while($row = mysqli_fetch_assoc($rezultat)) {
                    $this->result[] = $row;
                }
            }
            $this->affectedRows = $this->link->affected_rows;
        }       
    }

    /**
     * Checks if any error happened with the last query
     *
     * @return bool If there was any error while running the previous runQuery, returns true, otherwise false.
     */
    public function ifErrorHappened() {
        return strlen($this->error) ? true : false;
    }  
}
