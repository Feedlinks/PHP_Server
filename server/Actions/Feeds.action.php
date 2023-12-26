<?php
namespace Feed\Action;

class Feeds{
	public static function ProcessRequest($path){
		//check what type of request this is
		switch(\API::getHTTPMethod()){
			//checking for / fetching feed updates
			case "GET":
				$obj = new Feeds(true);
				$obj->HandleGet($path);
				break;
			//creating or updating a feed
			case "POST":
				$obj = new Feeds(false);
				$obj->HandlePost($path);
				break;
			default:
				//http_response_code(405);
				ErrorResponse(405);
				\Logger.Warn("405 hit: ".API::getHTTPMethod());
				exit;
		}
		//exit;
	}
	
	private $db;
	
	public function __construct($dbReadOnly){
		$this->db = getFeedDatabase($dbReadOnly);
	}
	
	##############################################################
	
	//path options: /Feeds/<feed id>/[<start_timestamp>,Users,Details]/<end_timestamp>
	public function HandleGet($path){
		$user = getAuthenticatedUser($this->db);
		$timestamp = time() - (DAY * 30);
		$endtimestamp = null;
		switch(sizeof($path)){
			//return a list of feeds on this server
			default:
			case 1:
				$this->GetFeedList();
				break;
			case 4:
				if(is_numeric($path[3])){
					$endtimestamp = $path[3];
				}
			case 3:
				//is this a manage feed users request
				if($path[2] == "Users"){
					$this->ManageFeedAdmins($path, $user, true);
					return;
				}
				//this is a manage feed rss request
				if($path[2] == "Rss"){
					$this->ManageFeedRss($path, $user, true);
					return;
				}
				//if the client just wants to know about a single feed
				if($path[2] == "Details"){
					//need the user in case this feed is private
					$this->GetSingleFeed($path[1], $user);
					return;
				}
				if(strlen(trim($path[2]))){
					//if the path does not contain a timestamp
					if(!is_numeric($path[2])){
						http_response_code(400);
						return;
					}
					//seems like the path contains a proper timestamp
					if($path[2] / 1 > $timestamp){
						$timestamp = $path[2];
					}
				}
			case 2:
				$this->GetFeedPosts($path[1], $user, $timestamp, $endtimestamp);
				break;
		}
	}
	
	private function GetFeedList(){
		$query = new \osql\Query("feeds");
		$query->addField("id", "feed_id")
			->addField("created")
			->addField("name")
			->addField("description")
			->addField("private")
			->addField("unlisted")
			->addField("registered")
			->addField("url")
			->addClause("status", \Database\StatusCodes::$Active)
			->addClause("unlisted", null);
		try{
			$feeds = $this->db->OQuery($query);
		}
		catch(\Exception $e){
			http_response_code(500);
			\Logger::Error("DB error fetching feeds", $e);
			exit;
		}
		
		//adjust 'private' and 'registered' to boolean from bit
		if($feeds){
			foreach($feeds as $i => $feed){
				if($feed['private'] == 1){
					$feeds[$i]['private'] = true;
				}
				else{
					$feeds[$i]['private'] = false;
				}
				if($feed['registered'] == 1){
					$feeds[$i]['registered'] = true;
				}
				else{
					$feeds[$i]['registered'] = false;
				}
			}
		}
		
		\API::outputJson($feeds);
	}
	
	private function GetSingleFeed($feedid, $user){
		$query = new \osql\Query("feeds");
		$query->addField("id", "feed_id")
			->addField("created")
			->addField("name")
			->addField("description")
			->addField("registered")
			->addField("private")
			->addField("unlisted")
			->addField("url")
			->addClause("status", \Database\StatusCodes::$Active)
			->addClause("id", $feedid)
			->setLimit(1);
		try{
			$details = $this->db->OQuery($query);
		}
		catch(\Exception $e){
			http_response_code(500);
			\Logger::Error("DB error fetching single feed", $e);
			exit;
		}
		
		if(!$details){
			http_response_code(404);
			exit;
		}
		
		if($details['registered'] == 1){
			$details['registered'] = true;
		}
		else{
			$details['registered'] = false;
		}
		if($details['private'] == 1){
			$details['private'] = true;
		}
		else{
			$details['private'] = false;
		}
		
		\API::outputJson($details);
	}
	
	private function GetFeedPosts($feedid, $user, $starttimestamp, $endtimestamp=null){
		//check that feed exists, is active, and is hosted on this server
		$query = new \osql\Query("feeds");
		$query->addField("id")
			->addField("registered")
			->addField("private")
			->addClause("status", \Database\StatusCodes::$Active)
			->addClause("url", null)
			->setLimit(1);
		try{
			$feed = $this->db->OQuery($query);
		}
		catch(\Exception $e){
			http_response_code(500);
			
			//todo - add details to the log
			
			\Logger::Error("DB error fetching feed", $e);
			exit;
		}
		
		if(!$feed){
			http_response_code(404);
			exit;
		}
		
		//verify the user has permission to view the feed's posts
		if($feed['private'] || $feed['registered']){
			//if not registered, then proceed no further!
			if(!$user){
				http_response_code(401);
				exit;
			}
			if($feed['private']){
				$this->CheckHasFeedPermission($feedid, $user['user_id'], FeedAdminLevels::$Browser);
			}
		}
		
		//fetch posts for the feed that are newer than the timestamp
		$query = new \osql\Query("posts");
		$query->addField("id", "post_id")
			->addField("created")
			->addField("feed_id")
			->addField("postdata", "text")
			->addField("image")
			->addField("image_url")
			->addOrderBy("created", true)
			->addClause("status", \Database\StatusCodes::$Active)
			->addClause("feed_id", $feedid)
			->addClause("created", $starttimestamp, null, \osql\OperatorTypes::$GreaterThan);
		if($endtimestamp){
			$query->addClause("created", $endtimestamp, null, \osql\OperatorTypes::$LessThan);
		}
		try{
			$posts = $this->db->OQuery($query);
		}
		catch(\Exception $e){
			http_response_code(500);
			
			//todo - add details to the log
			
			\Logger::Error("DB error fetching feed posts", $e);
			exit;
		}
		
		//convert image bits to boolean
		if($posts){
			foreach($posts as $i => $post){
				if($post['image']){
					$post['image'] = true;
				}
				else{
					$post['image'] = false;
				}
				$posts[$i] = $post;
			}
		}
		
		\API::outputJson($posts);
	}
	
	##############################################################
	
	//path options: /Feeds/<feed id>/[Users,Rss,Delete]
	public function HandlePost($path){
		\Logger::Debug("Processing Feeds post");
		
		//check if user is known
		$user = getAuthenticatedUser($this->db);
		if(!$user){
			//http_response_code(401);
			ErrorResponse(401);
			exit;
		}
		
		if(sizeof($path) == 3){
			//if this is a request to adjust feed users/admins
			if($path[2] == "Users"){
				$this->ManageFeedAdmins($path, $user, false);
				exit;
			}
			//this is a manage feed rss request
			if($path[2] == "Rss"){
				$this->ManageFeedRss($path, $user, false);
				return;
			}
			//if the user wants the feed deleted
			if($path[2] == "Delete"){
				\Logger::Debug("Delete Feed Request: ".$path[1]);
				$this->DeleteFeed($path[1], $user);
				return;
			}
			//http_response_code(400);
			ErrorResponse(400);
			return;
		}
		
		$request = \API::getRequestBody();
		$request = \LOEN::decode($request);
		$feed = new FeedModel($request);
		
		//validate values
		$this->ValidateFeedModelValues($feed);
		
		//prepare query
		$query = new \osql\Query("feeds");
		$query->addValue("status", $feed->status)
			->addValue("name", $feed->name)
			->addValue("description", $feed->description)
			->addValue("unlisted", $feed->unlisted)
			->addValue("private", $feed->private)
			->addValue("url", $feed->url);
		try{
			//if creating new
			if(sizeof($path) == 1){
				//check if the user has create feed permissions: feed_creator
				$this->CheckIsAuthorizedFeedCreator($user['user_id']);
				//proceed with insert
				$query->type = \osql\QueryTypes::$Insert;
				$query->addValue("created", time());
				$feedid = $this->db->OQuery($query);
				//add user as a feed editor
				$query = new \osql\Query("feed_users", \osql\QueryTypes::$Insert);
				$query->addValue("feed_id", $feedid)
					->addValue("user_id", $user['user_id'])
					->addValue("status", \Database\StatusCodes::$Active)
					->addValue("created", time())
					->addValue("admin", FeedAdminLevels::$Editor);
				$this->db->OQuery($query);
			}
			//must be editing
			else{
				$feedid = $path[1];
				//validate that the user has permission to edit the feed (as opposed to simply posting)
				$this->CheckHasFeedPermission($feedid, $user['user_id'], FeedAdminLevels::$Editor);
				//proceed with update
				$query->type = \osql\QueryTypes::$Update;
				$query->addClause("id", $feedid);
				$this->db->OQuery($query);
				/*
				$parser = new \osql\Parser();
				$sql = $parser->GetSQL($query,$parameters);
				error_log("dbg: ".$sql. " : ".json_encode($parameters));
				*/
			}
		}
		catch(\Exception $e){
			//http_response_code(500);
			ErrorResponse(500);
			
			//todo - add details to the log
			
			\Logger::Error("DB error saving feed", $e);
			exit;
		}
		
		//if creating new
		if(sizeof($path) == 1){
			\API::outputJson(["feed_id" => $feedid]);
		}
		else{
			\API::outputJson(["success"=>true]);
		}
	}
	
	private function ValidateFeedModelValues($feed){
		
	}
	
	private function CheckIsAuthorizedFeedCreator($userid){
		$query = new \osql\Query("users");
		$query->addField("id")
			->addClause("status", \Database\StatusCodes::$Active)
			->addClause("id", $userid)
			->addClause("feed_creator", 1)
			->setLimit(1);
		try{
			$checkid = $this->db->OQuery($query);
		}
		catch(\Exception $e){
			http_response_code(500);
			
			//todo - add details to the log
			
			\Logger::Error("DB error checking feed creation permission", $e);
			exit;
		}
		if($checkid){
			//all good
			return;
		}
		
		http_response_code(403);
		exit;
	}
	
	private function CheckHasFeedPermission($feedid, $userid, $permission){
		$query = new \osql\Query("feed_users");
		$query->addField("id")
			->addClause("status", \Database\StatusCodes::$Active)
			->addClause("feed_id", $feedid)
			->addClause("user_id", $userid)
			->addClause("admin", $permission, null, \osql\OperatorTypes::$GreaterThanEquals)
			->setLimit(1);
		try{
			$feedadminid = $this->db->OQuery($query);
		}
		catch(\Exception $e){
			//http_response_code(500);
			ErrorResponse(500);
			
			//todo - add details to the log
			
			\Logger::Error("DB error checking feed permission", $e);
			exit;
		}
		if($feedadminid){
			//all good
			return;
		}
		
		//http_response_code(403);
		ErrorResponse(403);
		exit;
	}
	
	//path options: /Feeds/<feed id>/Users
	private function ManageFeedAdmins($path, $authArr, $isGet){
		//if the user doesn't have a registered device and/or isn't logged in
		if(!$authArr || !$authArr['user_id']){
			http_response_code(403);
			exit;
		}
		include_once("Actions/Feeds/FeedUsers.class.php");
		if($isGet){
			FeedUsers::GetList($this->db, $path[1], $authArr['user_id']);
			return;
		}
		//must be adding, removing, or adjusting permission of a user from the feed
		$request = \API::getRequestBody();
		$request = \LOEN::decode($request);
		$feeduser = new FeedUsersModel($request);
		
		$obj = FeedUsers::Adjust($this->db, $path[1], $authArr['user_id'], $feeduser);
		if($obj){
			\API::outputJson($obj);
		}
	}
	
	//path options: /Feeds/<feed id>/Rss
	private function ManageFeedRss($path, $authArr, $isGet){
		//if the user doesn't have a registered device and/or isn't logged in
		if(!$authArr || !$authArr['user_id']){
			http_response_code(403);
			exit;
		}
		include_once("Actions/Feeds/FeedRss.class.php");
		if($isGet){
			FeedRss::GetList($this->db, $path[1], $authArr['user_id']);
			return;
		}
		//must be adding, editing, or removing a feed RSS source
		$request = \API::getRequestBody();
		$request = \LOEN::decode($request);
		$feedrss = new FeedRssModel($request);
		
		FeedRss::Adjust($this->db, $path[1], $authArr['user_id'], $feedrss);
	}
	
	private function DeleteFeed($feedid, $authArr){
		//if the user doesn't have a registered device and/or isn't logged in
		if(!$authArr || !$authArr['user_id']){
			//http_response_code(403);
			ErrorResponse(403);
			exit;
		}
		
		$this->CheckHasFeedPermission($feedid, $authArr['user_id'], FeedAdminLevels::$Editor);
		\Logger::Debug("has permission to delete feed: $feedid");
		
		$query = new \osql\Query("feeds", \osql\QueryTypes::$Update);
		$query->addValue("status", \Database\StatusCodes::$Deleted)
			->addClause("id", $feedid);
		try{
			$this->db->OQuery($query);
		}
		catch(\Exception $e){
			//http_response_code(500);
			ErrorResponse(500);
			
			//todo - add details to the log
			
			\Logger::Error("DB error deleting feed", $e);
			exit;
		}
		
		\Logger::Debug("feed $feedid deleted");
		\API::outputJson(["success"=>true]);
	}
}
?>