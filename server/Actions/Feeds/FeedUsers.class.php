<?php
namespace Feed\Action;

class FeedUsers{
	public static function GetList(&$db, $feedid, $userid){
		self::CheckHasEditPermission($db, $feedid, $userid);
		//fetch a list of users, identifying them by name and email only
		$query = new \osql\Query("feed_users");
		$query->addField("admin")
			->addClause("feed_id", $feedid)
			->addClause("user_id", $userid, null, \osql\OperatorTypes::$NotEquals)
			->addClause("status", \Database\StatusCodes::$Active)
			->addJoin(
				"users",
				\osql\JoinTypes::$Inner,
				[
					new \osql\Clause("id", new \osql\Field("user_id")),
					new \osql\Clause("status", \Database\StatusCodes::$Active),
				],
				[
					new \osql\Field("name"),
					new \osql\Field("email")
				]
			);
		
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
	
	public static function Adjust(&$db, $feedid, $userid, $feeduser){
		self::CheckHasEditPermission($db, $feedid, $userid);
		//can't do anything without an email
		if(!$feeduser->email){
			ErrorResponse(400);//http_response_code(400);
			exit;
		}
		//check if the supplied user exists, and whether they're already a part of the feed_users
		$query = new \osql\Query("users");
		$query->addField("id")
			->addField("name")
			->addClause("status", \Database\StatusCodes::$Active)
			->addClause("email", $feeduser->email)
			->addJoin(
				"feed_users",
				\osql\JoinTypes::$Left,
				[
					new \osql\Clause("user_id", new \osql\Field("id")),
					new \osql\Clause("feed_id", $feedid)
				],
				[
					new \osql\Field("id", "feeduserid")
				]
			)
			->setLimit(1);
		try{
			$user = $db->OQuery($query);
		}
		catch(\Exception $e){
			ErrorResponse(500);//http_response_code(500);
			
			//todo - add details to the log
			
			\Logger::Error("DB error searching for feed user by email", $e);
			exit;
		}
		//if there's no matching user, then proceed no further
		if(!$user){
			ErrorResponse(400);//http_response_code(400);
			exit;
		}
		
		//if the user is not yet a feed user
		if(!$user['feeduserid']){
			$query = new \osql\Query("feed_users", \osql\QueryTypes::$Insert);
			$query->addValue("feed_id", $feedid)
				->addValue("user_id", $user['id'])
				->addValue("created", time());
		}
		//must be updating the feed user
		else{
			$query = new \osql\Query("feed_users", \osql\QueryTypes::$Update);
			$query->addClause("id", $user['feeduserid']);
		}
		
		$query->addValue("status", $feeduser->status)
			->addValue("admin", $feeduser->admin);
		try{
			$db->OQuery($query);
		}
		catch(\Exception $e){
			ErrorResponse(500);//http_response_code(500);
			
			//todo - add details to the log
			
			\Logger::Error("DB error updating feed users", $e);
			exit;
		}
		
		//if the feed user status is not active, just return null
		if($feeduser->status != \Database\StatusCodes::$Active){
			return null;
		}
		
		return [
			"name" => $user['name'],
			"email" => $feeduser->email,
			"admin" => $feeduser->admin
		];
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