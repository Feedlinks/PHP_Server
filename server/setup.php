<?php

//Utility for updating or creating Feed server databases
//Delete from production after update and/or installation is complete

//access code; change this if you might not delete this file
//**** THIS DOES NOT MAKE THIS A SECURE UTILITY ****
$accessCode = "feedcode";

session_start();
?><h1>Feed Server Setup Utility</h1><?php
if(!isset($_SESSION['auth'])){
	$isauth = false;
	if(isset($_POST['authenticate'])){
		if($_POST['authcode'] === $accessCode){
			$isauth = true;
		}
		else{
			?><p><i>invalid auth code</i></p><?php
		}
	}
	if(!$isauth){
		authForm();
		exit;
	}
	$_SESSION['auth'] = true;
}

//check if config file has bee created
if(!file_exists("config.inc.php")){
	//do we have config data
	$configDone = false;
	if(array_key_exists("config",$_POST)){
		$configDone = buildConfigFile();
	}
	if(!$configDone){
		configForm();
		exit;
	}
}

//grab server config which we created in the last step, or was already there
include_once("config.inc.php");

//include database files now, so they're ready
include_once(\Feed\Config::$pathtocommon."OSQL/OSQL.Types.class.php");
include_once(\Feed\Config::$pathtocommon."OSQL/OSQL.Query.class.php");
include_once(\Feed\Config::$pathtocommon."OSQL/OSQL.Parser.class.php");
include_once(\Feed\Config::$pathtocommon."OSQL/OSQL.MySQL.class.php");

include_once(\Feed\Config::$pathtocommon."PasswordHash.class.php");

$dbmissing = false;

$dbconfig = new \osql\mysql\DatabaseConfig();
$dbconfig->server = \Feed\Config::$dbserverurl;
$dbconfig->username = \Feed\Config::$dbserveruser;
$dbconfig->password = \Feed\Config::$dbserverpass;
//connect to db server
$db = new \osql\mysql\Database($dbconfig);
$db->Connect();
$query = new \osql\Query("show databases like '".\Feed\Config::$feeddb."'",\osql\QueryTypes::$RawSQL);
$query->setLimit(1);
$res = $db->OQuery($query);
$db->Close();
if(sizeof($res) == 0){
	echo "<p>feed mysql db missing</p>";
	$dbmissing = true;
}
else{
	echo "<p>admin mysql db found</p>";
}

//if new install, ask for basic setup details
if($dbmissing){
	//if details have been passed already
	$saved = false;
	if(isset($_POST['user'])){
		$saved = buildFeedDatabase();
	}
	if(!$saved){
		userDetailsForm();
		exit;
	}
}
//validate that admin database is up to date
else{
	updateFeedDatabase();
}


########################################################################

function authForm(){
	?>
	<p>Authenticate to use this Tool</p>
	<form method="post">
		Auth Code
		<input type="password" name="authcode"/>
		<br/>
		<input type="submit" value="submit" name="authenticate"/>
	</form>
	<?php
}
	
function configForm(){
	?>
	<p>Config file required - enter values</p>
	<form method="post">
		<table>
			<tr>
				<td>Current Path</td>
				<td><?php echo realpath(".");?></td>
			</tr><tr>
				<td>Path to Common</td>
				<td>
					<input type="text" name="pathtocommon"/>
				</td>
			</tr><tr>
				<td>Feed Database Name</td>
				<td>
					<input type="text" name="feeddb" value="localfeed"/>
				</td>
			</tr>
		</table>
		<table>
			<tr><td colspan="2">Feed Path</td></tr>
			<tr>
				<td>Image Path</td>
				<td>
					<input type="text" name="feedimagepath" value="/var/data/localfeed/"/>
				</td>
			</tr>
		</table>
		<table>
			<tr><td colspan="2">MySQL-Specific</td></tr>
			<tr>
				<td>Server URL</td>
				<td>
					<input type="text" name="dbserverurl"/>
				</td>
			</tr><tr>
				<td>Server Username</td>
				<td>
					<input type="text" name="dbserveruser"/>
				</td>
			</tr><tr>
				<td>Server Password</td>
				<td>
					<input type="text" name="dbserverpass"/>
				</td>
			</tr>
		</table>
		<br/>
		<input type="submit" value="submit" name="config"/>
	</form>
	<?php
}

function buildConfigFile(){
	if(!$_POST['feeddb'] || !$_POST['feedimagepath'] || !$_POST['pathtocommon']){
		?><p><i>missing config value(s)</i></p><?php
		return false;
	}
	
	$content = '<?php
namespace Feed;

//Configuration for your Feed server
class Config{
	public static $pathtocommon = "'.$_POST['pathtocommon'].'";
	public static $feeddb = "'.$_POST['feeddb'].'";
	//mysql
	public static $dbserverurl = "'.$_POST['dbserverurl'].'";
	public static $dbserveruser = "'.$_POST['dbserveruser'].'";
	public static $dbserverpass = "'.$_POST['dbserverpass'].'";
	//logger mysql
	public static $appname = "Feedlinks Server";
	public static $logdbserverurl = "localhost";
	public static $logdbserveruser = "logger_mysql";
	public static $logdbserverpass = "logger_dev_pass";
	public static $logdb = "applogs";
	public static $logdebugon = false;
	//feed image path
	public static $feedimagepath = "'.$_POST['feedimagepath'].'";
	
	//account settings
	public static $allownewaccount = true;
	public static $newaccountfeedcreator = true;
	public static $allowfeedlisted = false;		//accounts by default may not create a listed feed
}
?>';
	if(file_put_contents("config.inc.php",$content) !== false){
		return true;
	}
	return false;
}

function userDetailsForm(){
	?>
	<p>First User Details</p>
	<form method="post">
		<table>
			<tr>
				<td>Name</td>
				<td>
					<input type="text" name="name"/>
				</td>
			</tr><tr>
				<td>Email</td>
				<td>
					<input type="text" name="email"/>
				</td>
			</tr><tr>
				<td>Password</td>
				<td>
					<input type="text" name="password"/>
				</td>
			</tr>
		</table>
		<br/>
		<input type="submit" value="submit" name="user"/>
	</form>
	<?php
}

function buildFeedDatabase(){
	$dbConfig = new \osql\mysql\DatabaseConfig();
	$dbConfig->server = \Feed\Config::$dbserverurl;
	$dbConfig->username = \Feed\Config::$dbserveruser;
	$dbConfig->password = \Feed\Config::$dbserverpass;
	$db = new \osql\mysql\Database($dbConfig);
	$db->Connect();
	
	$query = new \osql\Query("CREATE DATABASE ".\Feed\Config::$feeddb,\osql\QueryTypes::$RawSQL);
	$db->OQuery($query);
	
	$dbConfig->dbname = \Feed\Config::$feeddb;
	$db->Connect();
	$db->BeginTransaction(false);
	//get admin database build sql
	include_once("ClassFiles/FeedDB.class.php");
	$sqlarr = [];
	foreach(\Feed\FeedDB::$versions as $method){
		$method = "\Feed\FeedDB::version_".$method;
		$sqlarr = array_merge($sqlarr, $method());
	}
	try{
		foreach($sqlarr as $sql){
			$query = new \osql\Query($sql,\osql\QueryTypes::$RawSQL);
			$db->OQuery($query);
		}
	}
	catch(\Exception $e){
		//todo - do something
		echo "<p>sql error: ".$e->getMessage()."</p>";
		return false;
	}
	//update settings table with versions
	$query = new \osql\Query("settings",\osql\QueryTypes::$Insert);
	$query->addValue("id",1)
		->addValue("name","dbversion")
		->addValue("value",json_encode(\Feed\FeedDB::$versions));
	$db->OQuery($query);
	
	//build query to create First User
	$query = new \osql\Query("users",\osql\QueryTypes::$Insert);
	$query->addValue("status",1)
		->addValue("created",time())
		->addValue("name",$_POST['name'])
		->addValue("email",$_POST['email'])
		->addValue("password",\PasswordHash::HashPassword($_POST['password']))
		->addValue("feed_creator",1);
	$db->OQuery($query);
	
	$db->CommitTransaction();
	$db->Close();
	return true;
}

function updateFeedDatabase(){
	$dbConfig = new \osql\mysql\DatabaseConfig();
	$dbConfig->server = \Feed\Config::$dbserverurl;
	$dbConfig->username = \Feed\Config::$dbserveruser;
	$dbConfig->password = \Feed\Config::$dbserverpass;
	$db = new \osql\mysql\Database($dbConfig);
	
	$dbConfig->dbname = \Feed\Config::$feeddb;
	$db->Connect();
	$db->BeginTransaction(false);
	include_once("ClassFiles/FeedDB.class.php");
	//fetch settings
	$query = new \osql\Query("settings",\osql\QueryTypes::$Select);
	$query->addField("value")
		->addClause("name","dbversion")
		->setLimit(1);
	try{
		$settings = $db->OQuery($query);
		echo "<p>settings: ".$settings."</p>";
	}
	catch(\Exception $e){
		$settings = null;
	}
	if($settings){
		$settings = json_decode($settings);
	}
	else{
		$settings = [];
	}
	$updateList = [];
	foreach(\Feed\FeedDB::$versions as $method){
		if(!in_array($method,$settings)){
			$updateList[] = $method;
		}
	}
	//if no update is necessary
	if(sizeof($updateList) == 0){
		echo "<p>feed db is already current</p>";
		return true;
	}
	//update the feed db
	$sqlarr = [];
	foreach($updateList as $update){
		$settings[] = $update;
		$update = "\Feed\FeedDB::version_".$update;
		$sqlarr = array_merge($sqlarr, $update());
	}
	try{
		foreach($sqlarr as $sql){
			$query = new \osql\Query($sql,\osql\QueryTypes::$RawSQL);
			$db->OQuery($query);
		}
	}
	catch(\Exception $e){
		//todo - do something
		echo "<p>sql error: ".$e->getMessage()."</p>";
		return false;
	}
	//update db version in settings
	try{
		$query = new \osql\Query("settings", \osql\QueryTypes::$Update);
		$query->addValue("value", json_encode($settings))
			->addClause("id", 1);
		$db->OQuery($query);
	}
	catch(\Exception $e){
		echo "<p>sql error: ".$e->getMessage()."</p>";
		return false;
	}
	echo "<p>feed db updates applied</p>";
	
	$db->CommitTransaction();
	$db->Close();
	return true;
}
?>
