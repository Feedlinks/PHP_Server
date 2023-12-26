<?php
namespace Feed\Action;

class FeedRss{
	public static function GetList(&$db, $feedid, $userid){
		self::CheckHasEditPermission($db, $feedid, $userid);
		
		$query = new \osql\Query("rss");
		$query->addField("id")
			->addField("label")
			->addField("url")
			->addClause("status", \Database\StatusCodes::$Active)
			->addClause("feed_id", $feedid);
		try{
			$list = $db->OQuery($query);
		}
		catch(\Exception $e){
			ErrorResponse(500);//http_response_code(500);
			
			//todo - add details to the log
			
			\Logger::Error("DB error fetching list of feed users", $e);
			exit;
		}
		
		\API::outputJson($list);
	}
	
	public static function Adjust(&$db, $feedid, $userid, $obj){
		self::CheckHasEditPermission($db, $feedid, $userid);
		
		$query = new \osql\Query("rss");
		
		//if creating a new rss link
		if(!$obj->id){
			$query->type = \osql\QueryTypes::$Insert;
			$query->addValue("feed_id", $feedid);
		}
		//must be editing an existing rss link
		else{
			$query->type = \osql\QueryTypes::$Update;
			$query->addClause("id", $obj->id);
		}
		
		$query->addValue("status", $obj->status)
			->addValue("label", $obj->label)
			->addValue("url", $obj->url);
		
		try{
			$rss_id = $db->OQuery($query);
		}
		catch(\Exception $e){
			ErrorResponse(500);//http_response_code(500);
			
			//todo - add details to the log
			
			\Logger::Error("DB error creating feed rss link", $e);
			exit;
		}
		
		\API::outputJson(["rss_id" => $rss_id]);
	}
	
	private static function CheckHasEditPermission(&$db, $feedid, $userid){
		//check that the user has editor permissions on this feed
		$query = new \osql\Query("feed_users");
		$query->addField("id")
			->addClause("status", \Database\StatusCodes::$Active)
			->addClause("feed_id", $feedid)
			->addClause("user_id", $userid)
			->addClause("admin", FeedAdminLevels::$Editor, null, \osql\OperatorTypes::$GreaterThanEquals)
			->setLimit(1);
		try{
			$feedadminid = $db->OQuery($query);
		}
		catch(\Exception $e){
			ErrorResponse(500);//http_response_code(500);
			
			//todo - add details to the log
			
			\Logger::Error("DB error checking feed user list permission", $e);
			exit;
		}
		if(!$feedadminid){
			ErrorResponse(403);//http_response_code(403);
			exit;
		}
	}
}
?>