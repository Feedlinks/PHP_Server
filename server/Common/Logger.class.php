<?php
/*
Logging class which is configurable for database or error_log

This class is designed to be portable, and does not depend on anything specific to this project.
This class does rely upon the OSQL project, for writing to a database
	https://github.com/offthebricks/OSQL
*/

class Logger{
	public static function Info($msg, $details = null){
		self::saveLog("Info", $msg, null, $details);
	}
	
	public static function Warn($msg, $ex = null, $details = null){
		self::saveLog("Warn", $msg, $ex, $details);
	}
	
	public static function Error($msg, $ex = null, $details = null){
		self::saveLog("Error", $msg, $ex, $details);
	}
	
	public static function Fatal($msg, $ex = null, $details = null){
		self::saveLog("Fatal", $msg, $ex, $details);
	}
	
	public static function Debug($msg, $details = null){
		if(self::IsDebugOn()){
			self::saveLog("Debug", $msg, null, $details);
		}
	}
	
	############################################
	
	private static $databaseOn = true;
	
	############################################
	
	private static function saveLog($level, $msg, $ex, $details){
		$db = null;
		try{
			//no database logging, so just log to error_log
			if(!self::$databaseOn){
				if($ex){
					$msg .= "\n\t".$e->getMessage();
				}
				error_log("\n".date("Y-m-d H:i:s")." ".$level." ".$msg."\n");
				return;
			}
			//database logging is enabled
			$db = self::getDatabase();
			//if this is a command line app
			if(php_sapi_name() == "cli" || getenv('CRON_MODE')){
				$clientIP = null;
				$method = null;
				$userAgent = null;
			}
			//web app
			else{
				$clientIP = $_SERVER['REMOTE_ADDR'];
				$method = $_SERVER['REQUEST_METHOD'];
				$userAgent = $_SERVER['HTTP_USER_AGENT']??null;
			}
			//check for an overactive client
			if(self::checkForAbuse($db, $clientIP)){
				return;
			}
			//create a log entry
			$query = new \osql\Query("Logs", \osql\QueryTypes::$Insert);
			$query->addValue("created", time())
				->addValue("ip_address", $clientIP)
				->addValue("method", $method)
				->addValue("appname", self::GetAppName())
				->addValue("level", $level)
				->addValue("message", $msg);
			$logid = $db->OQuery($query);
			//save user agent as a detail as it can be very long
			if($userAgent){
				self::saveDetail($db, $logid, "user_agent", $userAgent);
			}
			//if there is also an exception
			if($ex){
				self::saveDetail($db, $logid, "exception", $ex->getMessage());
				self::saveDetail($db, $logid, "stacktrace", json_encode($ex->getTrace()));
				if(method_exists($ex, "getFile")){
					self::saveDetail($db, $logid, "file", $ex->getFile());
				}
				if(method_exists($ex, "getLine")){
					self::saveDetail($db, $logid, "line", $ex->getLine());
				}
			}
			//if extra details need to be logged
			if($details){
				if(is_object($details)){
					$details = get_object_vars($details);
				}
				if(is_array($details)){
					foreach($details as $key => $value){
						self::saveDetail($db, $logid, $key, $value);
					}
				}
			}
		}
		catch(\Exception $ex){
			//never save logging exception to database
			error_log("error recording log: ".$ex->getMessage());
		}
		finally{
			if($db){
				$db->Close();
			}
		}
	}
	
	private static function checkforAbuse(&$db, $clientIP){
		//look back one minute
		$time = time() - 60;
		//get recent logs from this client
		$osql = new \osql\Query("Logs");
		$osql->addField("id")
			->addClause("ip_address", $clientIP)
			->addClause("created",
						$time,
						\osql\ClauseTypes::$And,
						\osql\OperatorTypes::$GreaterThan);
		$list = $db->OQuery($osql);
		if(sizeof($list) > 10){
			//there is abuse, or just high volume
			return true;
		}
		return false;
	}
	
	private static function getDatabase(){
		$dbConfig = new \osql\mysql\DatabaseConfig();
		//check for service-specific configuration
		if(class_exists("\LoggerConfig")){
			$dbConfig->server = \LoggerConfig::dbServerUrl();
			$dbConfig->username = \LoggerConfig::dbServerUser();
			$dbConfig->password = \LoggerConfig::dbServerPass();
			$dbConfig->dbname = \LoggerConfig::dbName();
		}
		//use default settings
		else{
			$dbConfig->server = "localhost";
			$dbConfig->username = "otb_mysql";
			$dbConfig->password = "otb_dev_pass";
			$dbConfig->dbname = "applogs";
		}
		//connect to db server
		$db = new \osql\mysql\Database($dbConfig);
		$db->Connect();
		return $db;
	}
	
	private static function saveDetail(&$db, $logid, $key, $value){
		$query = new \osql\Query("LogData", \osql\QueryTypes::$Insert);
		$query->addValue("log_id", $logid)
			->addValue("key", $key)
			->addValue("data", $value);
		$db->OQuery($query);
	}
	
	private static function GetAppname(){
		//if the app name exists in a logger config class
		if(class_exists("\LoggerConfig") && method_exists("\LoggerConfig", "AppName")){
			return \LoggerConfig::AppName();
		}
		//if the caller has provided a global function to get the app name
		if(function_exists("GetAppName")){
			return GetAppName();
		}
		//if the appname has been saved to POST or GET
		if(isset($_REQUEST['appname'])){
			return $_REQUEST['appname'];
		}
		return null;
	}
	
	private static function IsDebugOn(){
		if(class_exists("\LoggerConfig") && method_exists("\LoggerConfig", "isDebugOn")){
			return \LoggerConfig::isDebugOn();
		}
		return false;
	}
}
/*
Database Schema - OSQL must already be defined for database logging

//sqlite
create table Logs(
	id INTEGER PRIMARY KEY NOT NULL,
	created int,
	ip_address text,
	method text,
	appname text,
	level text,
	message text
);

create table LogData(
	id INTEGER PRIMARY KEY NOT NULL,
	log_id int,
	key text,
	data text
);

//mysql
create table Logs(
	id INT PRIMARY KEY NOT NULL AUTO_INCREMENT,
	created int,
	ip_address varchar(64),
	method varchar(10),
	appname varchar(20),
	level varchar(5),
	message varchar(256)
);

create table LogData(
	id INT PRIMARY KEY NOT NULL AUTO_INCREMENT,
	log_id int,
	`key` varchar(50),
	data text
);
*/
?>