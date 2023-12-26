<?php
namespace osql;

class Query{
	public $type;					//constructor handles default
	public $result_limit;			//default to no limit (NULL)
	public $distinct = FALSE;		//default to non distinct
	
	public $table;
	public $fields = [];			//can be a string or 'field' object; string values are not permitted whitespaces
	public $joins = [];
	public $clauses = [];
	public $values = [];			//for insert and update queries
	public $orderby = [];
	
	#################################
	
	public static function StripDefaults(&$query){
		$reference = new Query(NULL);
		$properties = get_object_vars($reference);
		
		foreach($properties as $key => $value){
			if(property_exists($query,$key) && $query->$key === $reference->$key){
				unset($query->$key);
			}
		}
	}
	
	#################################
	
	public function __construct($table,$type=NULL){
		if(is_string($table)){
			$this->table = $table;
		}
		if(!$type || !is_int($type)){
			$type = QueryTypes::$Select;
		}
		$this->type = $type;
	}
	
	public function setLimit($limit){
		if(is_int($limit)){
			$this->result_limit = $limit;
		}
		return $this;
	}
	
	public function setDistinct($distinct){
		if($distinct){
			$this->distinct = TRUE;
		}
		else{
			$this->distinct = FALSE;
		}
		return $this;
	}
	
	public function addField($column,$as=NULL,$join=NULL){
		$obj = new Field($column,$as,$join);
		$this->fields[] = $obj;
		return $this;
	}
	
	public function addJoin($table,$type=NULL,$clauses=NULL,$fields=NULL,$id=NULL){
		if($clauses){
			foreach($clauses as $i => $clause){
				$clauses[$i] = $clause;
			}
		}
		$obj = new Join($table,$type,$clauses,$fields,$id);
		$this->joins[] = $obj;
		return $this;
	}
	
	public function addClause($column,$compare,$type=NULL,$operator=NULL,$join=NULL){
		$obj = new Clause($column,$compare,$type,$operator,$join);
		$this->clauses[] = $obj;
		return $this;
	}
	
	public function addMultiClause($type,$clauses,$join=NULL){
		$clauseObj = new Clause($clauses,NULL,$type,NULL,$join);
		$clauseObj = $clauseObj;
		if($join){
			foreach($this->joins as $idx => $j){
				if($j->id == $join){
					$j->clauses[] = $clauseObj;
					break;
				}
			}
		}
		else{
			$this->clauses[] = $clauseObj;
		}
		return $this;
	}
	
	public function addValues($assocArr){
		if(!is_array($assocArr)){
			return $this;
		}
		foreach($assocArr as $key => $val){
			$this->addValue($key,$val);
		}
		return $this;
	}
	
	public function addValue($column,$value){
		$vobj = new Value();
		$vobj->column = $column;
		$vobj->value = $value;
		$this->values[] = $vobj;
		return $this;
	}
	
	public function addOrderBy($column,$desc=FALSE,$join=NULL){
		$this->orderby[] = new OrderBy($column,$desc,$join);
		return $this;
	}
}

//Content classes

//for selects
class OrderBy{
	public $column;
	public $desc;
	public $join;
	
	public function __construct($column,$desc=FALSE,$join=NULL){
		$this->column = $column;
		$this->desc = $desc;
		if($join){
			$this->join = $join;
		}
	}
}

//for inserts and updates
class Value{
	public $column;
	public $value;
}

//fields to fetch with selects
class Field{
	public $column;
	public $as;			//select 'as' value if desired
	public $join;		//for clause compare values
	
	public function __construct($column,$as=NULL,$join=NULL){
		$this->column = $column;
		if($as){
			$this->as = $as;
		}
		if($join){
			$this->join = $join;
		}
	}
}

class Join{
	public $table;
	public $type = 1;
	public $clauses = [];
	public $fields = [];				//fields to fetch from this join
	public $id;
	
	public function __construct($table,$type=NULL,$clauses=NULL,$fields=NULL,$id=NULL){
		$this->table = $table;
		if($type){
			$this->type = $type;
		}
		if($clauses){
			$this->clauses = $clauses;
		}
		if($fields){
			$this->fields = $fields;
		}
		if($id){
			$this->id = $id;
		}
	}
}

class Clause{
	public $column;
	public $compare;		//can a 'Field' object
	public $type = 1;			//defaults to 'And'
	public $operator = 1;		//defaults to 'Equals'
	public $join;
	
	public function __construct($column,$compare,$type=NULL,$operator=NULL,$join=NULL){
		$this->column = $column;
		$this->compare = $compare;
		if($type){
			$this->type = $type;
		}
		if($operator){
			$this->operator = $operator;
		}
		if($join){
			$this->join = $join;
		}
	}
}
?>