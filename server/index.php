<?php
if(!file_exists("config.inc.php")){
	http_response_code(500);
	echo "api server not configured";
	exit;
}
include_once("config.inc.php");
include_once("ClassFiles/StatusCodes.class.php");
include_once("ClassFiles/Models.class.php");

//config class to provide logger with database config
class LoggerConfig{
	public static function dbServerUrl(){
		return \Feed\Config::$logdbserverurl;
	}
	public static function dbServerUser(){
		return \Feed\Config::$logdbserveruser;
	}
	public static function dbServerPass(){
		return \Feed\Config::$logdbserverpass;
	}
	public static function dbName(){
		return \Feed\Config::$logdb;
	}
	public static function AppName(){
		return \Feed\Config::$appname;
	}
	public static function isDebugOn(){
		return \Feed\Config::$logdebugon;
	}
}

//now include common resources
include_once(Feed\Config::$pathtocommon."API.class.php");
include_once(Feed\Config::$pathtocommon."PasswordHash.class.php");
include_once(Feed\Config::$pathtocommon."Logger.class.php");
include_once(Feed\Config::$pathtocommon."Utilities.class.php");
include_once(Feed\Config::$pathtocommon."LOEN/LOEN.class.php");

include_once(Feed\Config::$pathtocommon."OSQL/OSQL.Types.class.php");
include_once(Feed\Config::$pathtocommon."OSQL/OSQL.Query.class.php");
include_once(Feed\Config::$pathtocommon."OSQL/OSQL.Parser.class.php");
//include_once(Feed\Config::$pathtocommon."OSQL/OSQL.SQLite.class.php");
include_once(Feed\Config::$pathtocommon."OSQL/OSQL.MySQL.class.php");

function getFeedDatabase($readonly = false){
	$dbConfig = new \osql\mysql\DatabaseConfig();
	$dbConfig->server = \Feed\Config::$dbserverurl;
	$dbConfig->username = \Feed\Config::$dbserveruser;
	$dbConfig->password = \Feed\Config::$dbserverpass;
	//connect to db server
	$db = new \osql\mysql\Database($dbConfig);
	
	$dbConfig->dbname = \Feed\Config::$feeddb;
	$db->Connect();
	return $db;
}

function getAuthenticatedUser(&$db){
	$uid = API::getHTTPHeader("Feedlinks");
	if(!$uid){
		return null;
	}
	$query = new \osql\Query("devices");
	$query->addField("user_id")
		->addField("id", "device_id")
		->addClause("uid", $uid)
		->addClause("status", \Database\StatusCodes::$Active)
		->setLimit(1);
	try{
		$user = $db->OQuery($query);
	}
	catch(\Exception $e){
		http_response_code(500);
		\Logger::Error("DB error fetching authorized user", $e);
		return null;
	}
	
	return $user;		//is either null or the user id of the authorized user
}

function LogOSQLQuery($query){
	$parser = new \osql\Parser();
	$sql = $parser->GetSQL($query,$parameters);
	ob_start();
		echo $sql."\n";
		var_dump($parameters);
	\Logger::Info(ob_get_clean());
}

function IsProduction(){
	if(strpos($_SERVER["SERVER_NAME"],"dev.") !== 0){
		return true;
	}
	return false;
}

function ErrorResponse($code, $msg = ""){
	Logger::Debug("response: $code - $msg");
	\API::outputJson($msg, $code);
	exit;
}

define("DAY", 86400);

########################################################################

//CORS is probably handled by the web server, but just in case
if(\API::getHTTPMethod() == "OPTIONS"){
	\API::sendCorsHeaders();
	exit;
}

$path = API::getPathSetGet();

Logger::Debug("(".API::getHTTPMethod().") path: ".$path);//." dbg: ".LOEN::encode($_SERVER));

//if there is no path or the path is this file, show details about this service
if(!$path || $path == "/" || $path == "index.php"){
	$details = [
	//	"timestamp" => time(),
	//	"client_ip" => $_SERVER['REMOTE_ADDR'],
	//	"client_accept" => $_SERVER['HTTP_ACCEPT'],
		"accept" => "application/json,application/loen",
		"name" => "Feedlinks.org Feed Collection",
		"description" => "Feedlinks.org is the perfect place to get started with Feedlinks! Find feeds with news, events, posts from businesses, cities, and your friends. Create your own feed, or your own feed source like this one!",
		"image" => false,
		"sse" => false,
		"account_url" => null		//url for managing account, otherwise default account handling
	];
	if(!IsProduction()){
		$details["name"] = "** dev ** ".$details["name"]." - ".$_SERVER["SERVER_NAME"];
	}
	API::outputJson($details);
	exit;
}

$path = explode("/",$path);
//purge any empty path indexes
for($i=sizeof($path)-1; $i>-1; $i--){
	if(!strlen($path[$i])){
		unset($path[$i]);
	}
}
$path = array_values($path);

Logger::Debug("path ready: ".LOEN::encode($path));

//determine the action to take
if(!file_exists("Actions/".$path[0].".action.php")){
	//http_response_code(404);
	//echo "unknown action: '".$path[0]."'";
	ErrorResponse(404, "unknown action: '".$path[0]."'");
	exit;
}

Logger::Debug("processing action: ".$path[0]);

include_once("Actions/".$path[0].".action.php");
$action = "Feed\Action\\".$path[0];
$action::ProcessRequest($path);

//API::outputJson(["success" => true]);
Logger::Debug("response: 200");

/*
Description of the 'Local' service

The company maintains a list of feeds that are available by default in the app
	- other people may make apps that have their own defaults
	- other apps may use the company's list of defaults
	- only specific logged in users may add/edit feeds
The app tracks a feed's server and id on that server
	- it's up to the feed how long to keep history for
	- it's up to the server to determine how far back to give to new followers
The app polls the server on a schedule to check for new feeds
	- probably no more often than every 5 minutes (like an email client)
	- for more real-time updates, the feed may web hook to an SSE or push notification server
Users do not need to be logged in to view the feeds
	- their app will be assigned a unique id so that abuse can be monitored
	- the unique id will also help with comment replys
	- each feed server will have unique accounts, should a user be logged in
Users must be logged in and have appropriate permissions, in order to post to a feed
	- any user without permissions may request a post be added to a feed, but they must be logged in
Users may reply to a feed, but all replys and comments are hidden
	- hidden comments and replys are sent only to the poster
	- only replys from the original poster are visible in the feed
Later expansion to include private feeds
	- feeds will be by invitation only
	- users will need to be logged in to view the feed
*/
?>