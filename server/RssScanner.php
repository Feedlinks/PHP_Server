<?php

//only allowed to run from command line - ie web: cgi-fcgi
//doesn't work on shared hosting
/*
if(php_sapi_name() != "cli"){
	http_response_code(401);
	echo "not permitted\n";
	exit;
}
*/

//https://stackoverflow.com/a/7868700/5937052
//0,30 	* 	* 	* 	* 	CRON_MODE=1 php -q /home/mircerlancerous/public_html/api/RssScanner.php
if(!getenv('CRON_MODE')){
	echo "not permitted\n";
	exit;
}

if(isset($_SERVER['REMOTE_ADDR']) && $_SERVER['REMOTE_ADDR']){
	echo "not permitted 2 - ".$_SERVER['REMOTE_ADDR']."\n";
	exit;
}

if(isset($argv) && isset($argv[1]) && $argv[1] == "test"){
	$testpost = "ABC--<img src='https://i.cbc.ca/1.6997197.1697450881!/fileImage/httpImage/image.jpg_gen/derivatives/16x9_620/india-ioc-meeting.jpg' alt='A man in glasses and a suit speaks at a podium that is adorned with the five Olympic rings.' width='620' height='349' title='International Olympic Committee (IOC) President Thomas Bach, speaks on the first day of the 141st IOC Session in Mumbai, India, Sunday, Oct. 15, 2023.  '/><p>Five sports were added to the <a href='https://www.feedlinks.org'>2028 Los Angeles Games</a> by the International Olympic Committee on Monday, with baseball-softball and squash also confirmed for the program.</p>";
	echo "test post:\n";
		echo "  ".$testpost."\n";
		$testpost = stripImages($testpost, $images);
		$testpost = stripHyperlinks($testpost);
		echo "  ".$testpost."\n";
		var_dump($images);
	echo "test done\n";
	exit;
}

include_once("config.inc.php");
include_once("ClassFiles/StatusCodes.class.php");
include_once("ClassFiles/RSS.class.php");

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
		return \Feed\Config::$appname . " - RssScan";
	}
	public static function isDebugOn(){
		return \Feed\Config::$logdebugon;
	}
}

//now include common resources
include_once(Feed\Config::$pathtocommon."Logger.class.php");

include_once(Feed\Config::$pathtocommon."OSQL/OSQL.Types.class.php");
include_once(Feed\Config::$pathtocommon."OSQL/OSQL.Query.class.php");
include_once(Feed\Config::$pathtocommon."OSQL/OSQL.Parser.class.php");
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

function LogOSQLQuery($query){
	$parser = new \osql\Parser();
	$sql = $parser->GetSQL($query,$parameters);
	ob_start();
		echo $sql."\n";
		var_dump($parameters);
	\Logger::Info(ob_get_clean());
}

##################################################

function stripImages($postbody, &$images){
	$images = [];
	//look for html image in the post body
	$pos = strpos($postbody, "<img ");
	while($pos !== false){
		$pos2 = strpos($postbody, "/>", $pos);
		//if there is no end tag, play it safe
		if($pos2 === false){
			break;
		}
		else{
			$img = substr($postbody, $pos, $pos2 + 2 - $pos);
			//cut the image tag out of the post
			if($pos > 0){
				$postbody = substr($postbody, 0, $pos) . substr($postbody, $pos2 + 2);
			}
			else{
				$postbody = substr($postbody, $pos2 + 2);
			}
			//parse the image tag and get the src
			$images[] = xmlToArray($img)["@attributes"]["src"];
		}
		//check for another image
		$pos = strpos($postbody, "<img ", $pos + 1);
	}
	return $postbody;
}

function stripHyperlinks($postbody){
	//look for html anchor elements in the post body
	$pos = strpos($postbody, "<a ");
	while($pos !== false){
		$pos2 = strpos($postbody, "</a>", $pos);
		//if there is no end tag, play it safe
		if($pos2 === false){
			break;
		}
		else{
			//cut the anchor tag out of the post, leaving just the inner content
			$anchor = substr($postbody, $pos, $pos2 + 4 - $pos);
			$contentpos = strpos($anchor, ">");
			$anchor = substr($anchor, $contentpos + 1);
			$contentpos = strpos($anchor, "<");
			$anchor = substr($anchor, 0, $contentpos);
			if($pos > 0){
				$postbody = substr($postbody, 0, $pos) . $anchor . substr($postbody, $pos2 + 4);
			}
			else{
				$postbody = $anchor . substr($postbody, $pos2 + 4);
			}
		}
		//check for another anchor
		$pos = strpos($postbody, "<a ", $pos + 1);
	}
	return $postbody;
}

function xmlToArray($xmlstring){
	$xml = simplexml_load_string($xmlstring);
	$json = json_encode($xml);
	$array = json_decode($json,TRUE);
	return $array;
}

##################################################

\Logger::Debug("RssScanner starting scan");

//fetch feeds with RSS Urls
$db = getFeedDatabase(true);

$query = new \osql\Query("rss");
$query->addField("id", "rss_id")
	->addField("feed_id")
	->addField("url")
	->addField("label")
	->addClause("status", \Database\StatusCodes::$Active)
	->addJoin(
		"feeds",
		\osql\JoinTypes::$Inner,
		[
			new \osql\Clause("id", new \osql\Field("feed_id")),
			new \osql\Clause("status", \Database\StatusCodes::$Active),
		]
	)
	->addOrderBy("feed_id");
try{
	$list = $db->OQuery($query);
}
catch(\Exception $e){
	
	//todo - add details to the log
	
	\Logger::Error("DB error fetching list of RSS feeds", $e);
	exit;
}

if(!$list){
	echo "no rss feeds";
	exit;
}

//fetch posts from the RSS feeds
foreach($list as $feed){
	$posts = \RSS::ReadFeed($feed['url']);
	if(!$posts){
		echo "no posts for feed: ".$feed['feed_id']."\n";
		continue;
	}
	echo "processing ".sizeof($posts)." posts for feed: ".$feed['feed_id']." - ".$feed['rss_id']."\n";
	//save new posts to local feed
	foreach($posts as $post){
		//check for a post in the feed with the same 'post_id'
		$query = new \osql\Query("post_rss");
		$query->addField("post_id")
			->addClause("rss_id", $feed['rss_id'])
			->addClause("rss_post_id", $post->id)
			->setLimit(1);
		try{
			$existing_post = $db->OQuery($query);
			if($existing_post){
		//		stripImages($post->description,$images);
		//		echo "debug: ";var_dump($images);
				continue;
			}
		}
		catch(\Exception $e){
			
			//todo - add details to the log
			
			\Logger::Error("DB error checking feed for post", $e, $post);
			continue;
		}
		
		//post is new, so add it
		
		//remove all hyperlinks, and separate images - the article will have them, so we only need the link to that
		$postdata = stripHyperlinks( stripImages($post->description, $images) );
		$postdata = "[".$post->title."](".$post->link.")" ."\n\n" . $postdata;
		if($feed['label']){
			$postdata = "_".$feed['label']."_" ."\n\n" . $postdata;
		}
		
		$query = new \osql\Query("posts", \osql\QueryTypes::$Insert);
		if(sizeof($images) > 0){
			$query->addValue("image", 1)
				->addValue("image_url", $images[0]);
		}
		
		if(strlen($postdata) > 500){
			$postdata = substr($postdata, 0, 497)."...";
		}
		$query->addValue("status", \Database\StatusCodes::$Active)
			->addValue("created", $post->date)
			->addValue("feed_id", $feed['feed_id'])
			->addValue("postdata", $postdata);
		try{
			$post_id = $db->OQuery($query);
		}
		catch(\Exception $e){
			http_response_code(500);
			
			//todo - add details to the log
			
			\Logger::Warn("DB error saving rss post for rss: ".$feed['rss_id']." - ".$feed['feed_id'], $e, $post);
			
			//just skip this post
			continue;
		}
		//also need a post_rss entry
		$query = new \osql\Query("post_rss", \osql\QueryTypes::$Insert);
		$query->addValue("post_id", $post_id)
			->addValue("rss_id", $feed['rss_id'])
			->addValue("rss_post_id", $post->id);
		try{
			$db->OQuery($query);
		}
		catch(\Exception $e){
			http_response_code(500);
			
			//todo - add details to the log
			
			\Logger::Error("DB error saving post rss record for rss: ".$feed['rss_id'], $e);
			exit;
		}
	}
}