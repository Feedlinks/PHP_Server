<?php
namespace osql;

class Parser{
	private $autoSanitize;
	public $parameters;
	
	public function __construct($autoSanitize=FALSE){
		$this->autoSanitize = $autoSanitize;
	}
	
	public function GetSQL($obj,&$parameters){
		if(!class_exists('\osql\QueryTypes')){
			throw new \Exception("GetSQL: missing QueryTypes class");
		}
		
		$this->parameters = &$parameters;
		$this->parameters = [];
		
		$str = "";
		switch($obj->type){
			case QueryTypes::$RawSQL:$str = $this->buildRawSQL($obj);break;
			case QueryTypes::$Select:$str = $this->buildSelect($obj);break;
			case QueryTypes::$Update:$str = $this->buildUpdate($obj);break;
			case QueryTypes::$Insert:$str = $this->buildInsert($obj);break;
			case QueryTypes::$Delete:$str = $this->buildDelete($obj);break;
			default:throw new \Exception("GetSQL: unknown query type");
		}
		
		return $str;
	}
	
	private function buildRawSQL(&$obj){
		//in a raw sql query, the query table value contains the full sql
		return $obj->table;
	}
	
	private function buildDelete(&$obj){
		$str = "";
		//build where clause
		if($obj->clauses){
			$str .= " WHERE ".$this->getSelectClauses($obj->clauses,NULL,[]);
		}
		//finish
		return "DELETE FROM ".$obj->table.$str;
	}
	
	private function buildInsert(&$obj){
		$part1 = $part2 = "";
		if($this->autoSanitize){
			$obj->values = $this->SanitizeInput($obj->values);
		}
		foreach($obj->values as $vobj){
			$this->checkColumnIsSafe($vobj->column);
			if(strlen($part1) > 0){
				$part1 .= ",";
				$part2 .= ",";
			}
			$part1 .= "`".$vobj->column."`";
			if(!$vobj->value && ($vobj->value === NULL || strlen($vobj->value) == 0)){
				$part2 .= "NULL";
			}
			else if($this->isNumeric($vobj->value)){
				$part2 .= $vobj->value;
			}
			else{
				$part2 .= ":p".sizeof($this->parameters);
				$this->parameters[] = $vobj->value;
			}
		}
		return "INSERT INTO ".$obj->table." ($part1) VALUES ($part2)";
	}
	
	private function buildUpdate(&$obj){
		$str = "";
		//construct values
		if($this->autoSanitize){
			$obj->values = $this->SanitizeInput($obj->values);
		}
		foreach($obj->values as $vobj){
			$this->checkColumnIsSafe($vobj->column);
			if(strlen($str) > 0){
				$str .= ", ";
			}
			if($vobj->value === NULL){
				$str .= "`".$vobj->column."`=NULL";
			}
			else if($this->isNumeric($vobj->value)){
				$str .= "`".$vobj->column."`=".$vobj->value;
			}
			else{
				$str .= "`".$vobj->column."`=:p".sizeof($this->parameters);
				$this->parameters[] = $vobj->value;
			}
		}
		//build where clause
		if($obj->clauses){
			$str .= " WHERE ".$this->getSelectClauses($obj->clauses,NULL,[]);
		}
		//finish
		return "UPDATE ".$obj->table." SET ".$str;
	}
	
	private function buildSelect(&$obj){
		$str = "SELECT";
		if($obj->distinct){
			$str .= " DISTINCT";
		}
		$fieldsql = $this->getSelectFields($obj->fields,$obj->joins);
		foreach($obj->joins as $idx => $join){
			if(!sizeof($join->fields)){
				continue;
			}
			if($fieldsql){
				$fieldsql .= ",";
			}
			$fieldsql .= $this->getSelectFields($join->fields,1 + $idx);
		}
		if(!$fieldsql){
			throw new \Exception("no select fields provided");
		}
		$str .= " $fieldsql FROM ".$obj->table." A";
		$str .= $this->getSelectJoins($obj->joins);
		if($obj->clauses){
			$str .= " WHERE ".$this->getSelectClauses($obj->clauses,"A",$obj->joins);
		}
		
		if($obj->orderby){
			$str .= $this->getOrderByStatement($obj);
		}
		
		//add limit
		if($obj->result_limit){
			$str .= " LIMIT ".$obj->result_limit;
		}
		
		return $str;
	}
	
	private function getOrderByStatement(&$obj){
		$str = "";
		foreach($obj->orderby as $obObj){
			$this->checkColumnIsSafe($obObj->column);
			if($str){
				$str .= ",";
			}
			$str .= $this->getJoinPrefix($obj->joins,$obObj->join).".".$obObj->column;
			if($obObj->desc){
				$str .= " DESC";
			}
		}
		
		return " ORDER BY ".$str;
	}
	
	private function getJoinPrefix(&$joins,$id){
		if($id){
			foreach($joins as $i => $j){
				if($j->id == $id){
					return chr($i + 1 + 65);
				}
			}
		}
		return "A";
	}
	
	private function getSelectFields($list,$joins){
		$str = $prefix = "";
		foreach($list as $field){
			$this->checkColumnIsSafe($field->column);
			if(isset($field->join) && $field->join && is_array($joins)){
				$prefix = $this->getJoinPrefix($joins,$field->join);
			}
			else if(is_int($joins)){
				$prefix = chr($joins + 65);
			}
			else{
				$prefix = "A";
			}
			if($str){
				$str .= ",";
			}
			$str .= "$prefix.".$field->column;
			if($field->as){
				$str .= " AS ".$field->as;
			}
		}
		return $str;
	}
	
	private function getSelectClauses($list,$prefix,$joins){
		$str = "";
		foreach($list as $clause){
			if($str){
				$str .= ClauseTypes::GetString($clause->type);
			}
			if($clause->join){
				//$prefix = chr($clause->join + 65);
				$prefix = $this->getJoinPrefix($joins,$clause->join);
			}
			//check if this is a multi clause in brackets
			if(is_array($clause->column)){
				$str .= "(".$this->getSelectClauses($clause->column,$prefix,$joins).")";
			}
			else{
				$this->checkColumnIsSafe($clause->column);
				if($prefix){
					$str .= "$prefix.";
				}
				//need to handle nulls differently
				if($clause->compare === NULL){
					//check for an invalid compare case
					if($clause->operator != OperatorTypes::$Equals){
						throw new \Exception("null clause compare with non-equals operator");
					}
					$str .= $clause->column." IS NULL";
					continue;
				}
				else{
					$str .= $clause->column.OperatorTypes::GetString($clause->operator);
				}
				if($this->isNumeric($clause->compare)){
					$str .= $clause->compare;
				}
				else if(is_string($clause->compare)){
					//parameterize the value for safety
					$str .= ":p".sizeof($this->parameters);
					$this->parameters[] = $clause->compare;
				}
				else{
					//must be field class
					$field = $clause->compare;
					$this->checkColumnIsSafe($field->column);
					if(isset($field->join)){
						$str .= $this->getJoinPrefix($joins,$field->join).".".$field->column;
					}
					else{
						$str .= "A.".$field->column;
					}
				}
			}
		}
		return $str;
	}
	
	private function getSelectJoins($list){
		$str = "";
		foreach($list as $idx => $join){
			$str .= JoinTypes::GetString($join->type)."JOIN ".$join->table." ".chr($idx + 1 + 65)." ON ";
			$str .= $this->getSelectClauses($join->clauses,chr($idx + 1 + 65),$list);
		}
		return $str;
	}
	
	############################################################
	
	private function isNumeric($value){
		//zero length strings need to return as false
		if(is_object($value) || is_array($value) || !strlen("".$value)){
			return FALSE;
		}
		$len = strlen($value);
		for($i=0; $i<$len; $i++){
			$code = ord(substr($value, $i, 1));
			if (!($code > 47 && $code < 58)){ // numeric (0-9)
				return FALSE;
			}
		}
		return TRUE;
	}
	
	private function checkColumnIsSafe($columnName){
		//check for space in column name
		if(strlen($columnName) == 0 || strpos($columnName," ") !== FALSE){
			throw new \Exception("invalid field column name detected: '$columnName'");
		}
	}
	
	# Desc: strips all HTML from all input string parameters
	private function SanitizeInput($arr){
		if(is_array($arr)){
			foreach($arr as $key => $val){
				$arr[$key] = $this->SanitizeInput($val);
			}
		}
		else if(is_object($arr)){
			$list = get_object_vars($arr);
			foreach($list as $key => $val){
				$arr->$key = $this->SanitizeInput($val);
			}
		}
		else if(is_string($arr)){
			$arr = htmlentities($arr);
		}
		return $arr;
	}#-#SanitizeInput()
}
?>
