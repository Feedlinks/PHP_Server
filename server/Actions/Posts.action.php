<?php
namespace Feed\Action;

class Posts{
	public static function ProcessRequest($path){
		//check what type of request this is
		switch(\API::getHTTPMethod()){
			//checking for / fetching feed updates
			case "GET":
				$obj = new Posts(true);
				$obj->HandleGet($path);
				break;
			//creating or updating a post
			case "POST":
				$obj = new Posts(false);
				$obj->HandlePost($path);
				break;
			default:
				ErrorResponse(405);//http_response_code(405);
				//Logger.Warn("405 hit: ".API::getHTTPMethod());
				exit;
		}
		//exit;
	}
	
	private $db;
	
	public function __construct($dbReadOnly){
		$this->db = getFeedDatabase($dbReadOnly);
	}
	
	##############################################################
	
	//path options: /Posts/<post id>/Image
	public function HandleGet($path){
		$timestamp = time() - (DAY * 30);
		switch(sizeof($path)){
			default:
			case 1:
				ErrorResponse(400);//http_response_code(400);
				break;
			//get the requested post
			case 2:
				$user = getAuthenticatedUser($this->db);
				$this->GetPost($path[1], $user);
				break;
			//get the post image
			case 3:
				$user = getAuthenticatedUser($this->db);
				$this->GetPostImage($path[1], $user);
				break;
		}
	}
	
	private function GetPost($postid, $user){
		$post = $this->FetchPost($postid, $user);
		\API::outputJson($post);
	}
	
	private function GetPostImage($postid, $user){
		$post = $this->FetchPost($postid, $user);
		//if we're here, we're authenticated
		//now check the image exists...
		$imagepath = \Feed\Config::$feedimagepath . "/post" . $postid . ".png";
		if(!file_exists($imagepath)){
			ErrorResponse(404);//http_response_code(404);
			exit;
		}
		header("Content-Type: image/png");
		readfile($imagepath);
	}
		
	private function FetchPost($postid, $user){
		$query = new \osql\Query("posts");
		$query->addField("id", "post_id")
			->addField("feed_id")
			->addField("created")
			->addField("postdata", "text")
			->addField("image")
			->addField("image_url")
			->addClause("status", \Database\StatusCodes::$Active)
			->addClause("id", $postid)
			->addJoin(
				"feeds",
				\osql\JoinTypes::$Inner,
				[
					new \osql\Clause("id", new \osql\Field("feed_id")),
					new \osql\Clause("status", \Database\StatusCodes::$Active),
				],
				[
					new \osql\Field("private")
				]
			)
			->setLimit(1);
		try{
			$post = $this->db->OQuery($query);
		}
		catch(\Exception $e){
			ErrorResponse(500);//http_response_code(500);
			
			//todo - add details to the log
			
			\Logger::Error("DB error fetching feed posts", $e);
			exit;
		}
		
		if(!$post){
			ErrorResponse(404);//http_response_code(404);
			exit;
		}
		
		if($post['private']){
			$post['private'] = true;
			//if the post's feed is private, make sure the user has permission
			$this->CheckHasFeedPermission($feedid, $user['user_id'], FeedAdminLevels::$Browser);
		}
		else{
			$post['private'] = false;
		}
		//convert image  to boolean
		if($post['image']){
			$post['image'] = true;
		}
		else{
			$post['image'] = false;
		}
		
		return $post;
	}
	
	##############################################################
	
	//path options: /Posts/<post id>/Delete
	public function HandlePost($path){
		//check if user is known
		$user = getAuthenticatedUser($this->db);
		if(!$user){
			ErrorResponse(401);//http_response_code(401);
			exit;
		}
		
		if(sizeof($path) == 3){
			if($path[2] == "Delete"){
				$this->DeletePost($path[1], $user['user_id']);
			}
			else{
				ErrorResponse(400);//http_response_code(400);
			}
			return;
		}
		
		$request = \API::getRequestBody();
		$request = \LOEN::decode($request);
		$post = new PostModel($request);
		
		//validate values
		$this->ValidatePostModelValues($post);
		
		//check that user is allowed to post to this feed
		if(!$this->CheckHasFeedPostPermission($post->feed_id, $user['user_id'])){
			ErrorResponse(403);//http_response_code(403);
			exit;
		}
		
		//prepare query
		$query = new \osql\Query("posts");
		$query->addValue("status", $post->status)
			->addValue("postdata", $post->text);
		
		try{
			//if creating new
			if(sizeof($path) == 1){
				$query->type = \osql\QueryTypes::$Insert;
				$query->addValue("created", time())
					->addValue("user_id", $user['user_id'])
					->addValue("feed_id", $post->feed_id);
				$postid = $this->db->OQuery($query);
			}
			//must be editing
			else{
				$postid = $path[1];
				//validate that the post exists, and is allowed to be edited
				$this->CheckPostStatus($postid);
				//proceed with update
				$query->type = \osql\QueryTypes::$Update;
				$query->addClause("id", $postid);
				$this->db->OQuery($query);
			}
		}
		catch(\Exception $e){
			ErrorResponse(500);//http_response_code(500);
			
			//todo - add details to the log
			
			\Logger::Error("DB error saving post", $e);
			exit;
		}
		
		//image update must be done after saving post, so that we always have the post id
		//if there is an image, or we're removing one
		if($post->image_url !== null){
			$savepath = \Feed\Config::$feedimagepath . "/post" . $postid . ".png";
			$query = new \osql\Query("posts", \osql\QueryTypes::$Update);
			$query->addClause("id", $postid);
			//if we're removing an image
			if(!$post->image_url){
				$query->addValue("image", null)
					->addValue("image_url", null);
				//delete the image - if app messed up, may have already been deleted
				if(file_exists($savepath)){
					unlink($savepath);
				}
			}
			else{
				$query->addValue("image", 1);
				//if this is an external url
				if(strpos($post->image_url, "http") === 0){
					$query->addValue("image_url", $post->image_url);
				}
				//must be data url
				else{
					$query->addValue("image_url", null);
					$binImage = file_get_contents($post->image_url);
					$resImage = imagecreatefromstring($binImage);
					if($resImage === false){
						ErrorResponse(400);//http_response_code(400);
						exit;
					}
					$success = imagepng($resImage, $savepath);
					if(!$success){
						ErrorResponse(500);//http_response_code(500);
						\Logger::Error("error saving new post image");
						exit;
					}
				}
			}
			try{
				$this->db->OQuery($query);
			}
			catch(\Exception $e){
				ErrorResponse(500);//http_response_code(500);
				
				//todo - add details to the log
				
				\Logger::Error("DB error saving post image", $e);
				exit;
			}
		}
		
		//if creating new
		if(sizeof($path) == 1){
			\API::outputJson(["post_id" => $postid]);
		}
	}
	
	private function ValidatePostModelValues($post){
		if(!$post->feed_id){
			ErrorResponse(400);//http_response_code(400);
			exit;
		}
		
		
	}
	
	private function CheckHasFeedPostPermission($feedid, $userid){
		$this->CheckHasFeedPermission($feedid, $userid, FeedAdminLevels::$Poster);
		//if we got here, we're good to go
		return true;
	}
	
	private function CheckPostStatus($postid){
		$query = new \osql\Query("posts");
		$query->addField("id")
			->addField("status")
			->addField("feed_id")
			->addClause("status", \Database\StatusCodes::$Deleted, null, \osql\OperatorTypes::$NotEquals)
			->addClause("id", $postid)
			->setLimit(1);
		try{
			$post = $this->db->OQuery($query);
		}
		catch(\Exception $e){
			ErrorResponse(500);//http_response_code(500);
			
			//todo - add details to the log
			
			\Logger::Error("DB error validating feed-post relation", $e);
			exit;
		}
		
		if($post){
			//all good
			return $post;
		}
		
		ErrorResponse(400);//http_response_code(400);
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
			ErrorResponse(500);//http_response_code(500);
			
			//todo - add details to the log
			
			\Logger::Error("DB error checking feed permission", $e);
			exit;
		}
		if($feedadminid){
			//all good
			return $feedadminid;
		}
		
		ErrorResponse(403);//http_response_code(403);
		exit;
	}
	
	private function DeletePost($postid, $userid){
		//fetch the post so we can verify permission to delete the post
		$post = $this->CheckPostStatus($postid);
		
		//check that user is allowed to post to this feed
		if(!$this->CheckHasFeedPostPermission($post["feed_id"], $userid)){
			ErrorResponse(403);//http_response_code(403);
			exit;
		}
		
		$query = new \osql\Query("posts", \osql\QueryTypes::$Update);
		$query->addValue("status", \Database\StatusCodes::$Deleted)
			->addClause("id", $postid);
		try{
			$this->db->OQuery($query);
		}
		catch(\Exception $e){
			ErrorResponse(500);//http_response_code(500);
			
			//todo - add details to the log
			
			\Logger::Error("DB error deleting post", $e);
			exit;
		}
	}
}
?>