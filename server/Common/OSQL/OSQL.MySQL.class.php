<?php
namespace osql\mysql;

class DatabaseConfig{
	public $dbname;
	public $server;
	public $username;
	public $password;
	public $dbprefix;
}

class Database{
	private $dbconfig;
	private $autoSanitize = FALSE;
	
	private $intransaction = FALSE;
	private $insofttransaction = FALSE;
	private $softTransactionLog = NULL;
	
	private $mysqli;
	
	public function __construct(DatabaseConfig $dbconfig){
		$this->dbconfig = $dbconfig;
	}
	
	public function __destruct(){
		$this->Close();
	}
	
	public function Connect($databaseName=NULL){
		//if not already connected to mysql server
		if(!$this->mysqli){
			$this->mysqli = new \mysqli(
				$this->dbconfig->server,
				$this->dbconfig->username,
				$this->dbconfig->password
			);
			
			if($this->mysqli->connect_error){
				throw new \Exception("Connect: server connection failed");
			}
		}
		if($databaseName === NULL){
			//if just connecting to the engine, and not a database
			if(!$this->dbconfig->dbname){
				return;
			}
			$databaseName = $this->dbconfig->dbprefix.$this->dbconfig->dbname;
		}
		$res = $this->mysqli->select_db($databaseName);
		if(!$res){
			throw new \Exception("Connect: select database failed : ".$this->mysqli->error);
		}
	}
	
	public function Close(){
		if(!$this->mysqli){
			return;
		}
		if($this->intransaction || $this->insofttransaction){
			$this->RollbackTransaction();
		}
		if(!$this->mysqli->close()){
			throw new \Exception("Close: failed to close db connection");
		}
		$this->mysqli = NULL;
	}
	
	############################################################
	
	public function BeginTransaction($soft=FALSE){
		if($this->intransaction || $this->insofttransaction){
			return;
		}
		
		if($soft){
			$this->softTransactionLog = array();
			$this->insofttransaction = TRUE;
		}
		else{
			$res = $this->mysqli->query("START TRANSACTION;");
			if(!$res){
				throw new \Exception("Transaction: failed to start");
			}
			$this->intransaction = TRUE;
		}
	}
	
	public function CommitTransaction(){
		if($this->intransaction){
			$res = $this->mysqli->query("COMMIT;");
			if(!$res){
				return FALSE;
			}
			$this->intransaction = FALSE;
		}
		else if($this->insofttransaction){
			$this->softTransactionLog = NULL;
			$this->insofttransaction = FALSE;
		}
		return TRUE;
	}
	
	public function RollbackTransaction(){
		if($this->intransaction){
			$res = $this->mysqli->query("ROLLBACK;");
			if(!$res){
				return FALSE;
			}
			$this->intransaction = FALSE;
		}
		else if($this->insofttransaction){
			//disable soft transaction immediately so that restoration does not cause more logs to be created
			$this->insofttransaction = FALSE;
			foreach($this->softTransactionLog as $arr){
				//if the change was an update
				if(isset($arr['update'])){
					//restore values to those before the update
					$query = new Query($arr['table'],\osql\QueryTypes::$Update);
					$query->addValues($arr['update']);
					$query->addClause("id",$arr['id'],NULL,\osql\OperatorTypes::$Equals);
					$this->OQuery($query);
				}
				//if the change was a delete
				else if(isset($arr['delete'])){
					//restore deleted values
					$query = new Query($arr['table'],\osql\QueryTypes::$Insert);
					$query->addValues($arr['delete']);
					$this->OQuery($query);
				}
				//the change was an insert
				else{
					//delete inserted row
					$query = new Query($arr['table'],\osql\QueryTypes::$Delete);
					$query->addClause("id",$arr['id'],NULL,\osql\OperatorTypes::$Equals);
					$this->OQuery($query);
				}
			}
			$this->softTransactionLog = NULL;
		}
		return TRUE;
	}
	
	public function setAutoSanitize($sanitize){
		$this->autoSanitize = $sanitize;
	}
	
	public function OQuery($obj){
		if(!$this->mysqli){
			throw new \Exception("OQuery: not connected to a database");
		}
		if(!is_object($obj)){
			throw new \Exception("OQuery: passed value is not an object");
		}
		if(!class_exists('\osql\Parser') || !class_exists('\osql\QueryTypes')){
			throw new \Exception("OQuery: missing osql Parser and/or QueryTypes classes");
		}
		
		$parser = new \osql\Parser($this->autoSanitize);
		$sql = $parser->GetSQL($obj,$parameters);
		
		switch($obj->type){
			case \osql\QueryTypes::$RawSQL:
				if($obj->result_limit !== NULL){
					return $this->Query($sql,$parameters,TRUE);
				}
				else{
					$this->Query($sql,$parameters);
				}
				break;
			case \osql\QueryTypes::$Select:
				$limit = $obj->result_limit;
				return $this->Select($sql,$parameters,$limit);
			case \osql\QueryTypes::$Update:
				$this->Update($sql,$obj,$parameters);
				break;
			case \osql\QueryTypes::$Insert:
				return $this->Insert($sql,$obj->table,$parameters);
			case \osql\QueryTypes::$Delete:
				$this->Delete($sql,$obj,$parameters);
				break;
			default:
				throw new \Exception("OQuery: unknown query type: ".$obj->type);
				break;
		}
		
		return NULL;
	}
	
	############################################################
	
	private function Query($sql,$prepared=NULL,$getResults=FALSE){
		$prepValues = NULL;
		if($prepared){
			$prepValues = $this->bindStatementValues($sql,$prepared);
		}
		
		$stmt = $this->mysqli->prepare($sql);
		if(!$stmt){
			//extract query type
			throw new \Exception("OQuery: prepare query fail: ".$sql." : ".$this->mysqli->error);
		}
		
		if($prepValues){
			$ref = new \ReflectionClass('mysqli_stmt');
			$method = $ref->getMethod("bind_param");
			$method->invokeArgs($stmt,$prepValues);
		}
		
		$res = $stmt->execute();
		if(!$res){
			throw new \Exception("OQuery: failed to execute statement: $sql : ".$this->mysqli->error);
		}
		
		if(!$getResults){
			$stmt->close();
			return;
		}
		
		$resObj = $stmt->get_result();
		$stmt->close();
		if(!$resObj){
			throw new \Exception("Failed to fetch results: $sql : ".$this->mysqli->error);
		}
		$resVal = [];
		while($row = $resObj->fetch_array(MYSQLI_ASSOC)){
			$resVal[] = $row;
		}
		$resObj->free();
		
		return $resVal;
	}
	
	private function Select($sql,$prepared,$limit){
		$resVal = $this->Query($sql,$prepared,TRUE);
		
		//if only one result was requested
		if($resVal && $limit == 1){
			$resVal = $resVal[0];
			//if only one column was requested
			if(sizeof($resVal) == 1){
				foreach($resVal as $val){
					$resVal = $val;
					break;
				}
			}
		}
		
		return $resVal;
	}
	
	private function Insert($sql,$table,$prepared){
		$this->Query($sql,$prepared);
		
		$id = $this->mysqli->insert_id;
		if($this->insofttransaction){
			$this->softTransactionLog[] = ["table" => $table, "id" => $id];
		}
		return $id;
	}
	
	private function Update($sql,&$obj,$prepared){
		$updateRecord = [];
		if($this->insofttransaction){
			$updateRecord = $this->fetchUpdateRecords($obj);
		}
		
		$this->Query($sql,$prepared);
		
		if($this->insofttransaction){
			foreach($updateRecord as $record){
				$this->softTransactionLog[] = $record;
			}
		}
	}
	
	private function Delete($sql,&$obj,$prepared){
		$deleteRecord = [];
		if($this->insofttransaction){
			$deleteRecord = $this->fetchDeleteRecords($obj);
		}
		
		$this->Query($sql,$prepared);
		
		if($this->insofttransaction){
			foreach($deleteRecord as $record){
				$this->softTransactionLog[] = $record;
			}
		}
	}
	
	############################################################

	# desc: 
	private function fetchDeleteRecords(&$obj){
		if(!class_exists('\osql\Query')){
			throw new \Exception("Update: osql query class required for soft transaction update queries");
		}
		$query = new Query($obj->table,\osql\QueryTypes::$Select);
		$query->addField("*");
		foreach($obj->clauses as $cObj){
			$query->clauses[] = $cObj;
		}
		
		$results = $this->OQuery($query);
		$records = [];
		if($results){
			foreach($results as $data){
				$records[] = ["table"=>$query->table, "delete"=>$data];
			}
		}
		return $records;
	}#-#fetchDeleteRecords()
	
	# desc: 
	private function fetchUpdateRecords(&$obj){
		if(!class_exists('\osql\Query')){
			throw new \Exception("Update: osql query class required for soft transaction update queries");
		}
		//build a select query from the update object
		$query = new Query($obj->table,\osql\QueryTypes::$Select);
		$query->addField("id");
		foreach($obj->values as $vObj){
			if($vObj->column == "id"){
				continue;
			}
			$query->addField($vObj->column);
		}
		foreach($obj->clauses as $cObj){
			$query->clauses[] = $cObj;
		}
		
		$results = $this->OQuery($query);
		$records = [];
		if($results){
			foreach($results as $data){
				$id = $data['id'];
				unset($data['id']);
				$records[] = ["table"=>$query->table, "id"=>$id, "update"=>$data];
			}
		}
		return $records;
	}#-#fetchUpdateRecords()
	
	# desc: 
	private function bindStatementValues(&$sql,$prepValues){
		$refArr = [""];
		foreach($prepValues as $key => $value){
			$sql = str_replace(":p".$key,"?",$sql);
			
			//setup type
			if(is_double($value)){
				$refArr[0] .= "d";
			}
			else{
				$refArr[0] .= "s";		//default to string - must do this to support big int, and unsigned int, etc
			}
			//add to list
			$val = $value;//$this->mysqli->real_escape_string($value);
			$refArr[] = &$val;
			unset($val);
		}
		return $refArr; 
	}#-#bindStatementValues()
}
?>
