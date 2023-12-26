<?php
namespace osql;

//Type classes

class QueryTypes{
	public static $RawSQL = 1;
	public static $Select = 2;
	public static $Update = 3;
	public static $Insert = 4;
	public static $Delete = 5;
}

class JoinTypes{
	public static $Left = 1;
	public static $Inner = 2;
	public static $Right = 3;
	public static $Outer = 4;
	
	public static function GetString($type){
		switch($type){
			default:break;
			case self::$Left:return " LEFT ";
			case self::$Inner:return " INNER ";
			case self::$Right:return " RIGHT ";
			case self::$Outer:return " OUTER ";
		}
		return "";
	}
}

class ClauseTypes{
	public static $And = 1;
	public static $Or = 2;
	
	public static function GetString($type){
		switch($type){
			default:break;
			case self::$And:return " AND ";
			case self::$Or:return " OR ";
		}
		return "";
	}
}

class OperatorTypes{
	public static $Equals = 1;
	public static $NotEquals = 2;
	public static $GreaterThan = 3;
	public static $GreaterThanEquals = 4;
	public static $LessThan = 5;
	public static $LessThanEquals = 6;
	
	public static function GetString($type){
		switch($type){
			default:break;
			case self::$Equals:return "=";
			case self::$NotEquals:return "!=";
			case self::$GreaterThan:return ">";
			case self::$GreaterThanEquals:return ">=";
			case self::$LessThan:return "<";
			case self::$LessThanEquals:return "<=";
		}
		return "";
	}
}

?>