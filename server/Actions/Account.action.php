<?php
namespace Feed\Action;

class Account{
	public static function ProcessRequest($path){
		//check what type of request this is
		switch(\API::getHTTPMethod()){
			//checking for logged in status
			case "GET":
				$obj = new Account(true);
				$obj->HandleGet($path);
				break;
			//creating or updating an account, or logging in
			case "POST":
				$obj = new Account(false);
				$obj->HandlePost($path);
				break;
			default:
				ErrorResponse(405);
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
	
	//path options: /Account/[New:Details:Devices]
	public function HandleGet($path){
		$known = false;
		//check if user is known
		$user = getAuthenticatedUser($this->db);
		if($user){
			$known = true;
		}
		//if this is just a logged in status check
		if(sizeof($path) == 1){
			//tell the client whether they are logged in
			\API::outputJson($known);
			return;
		}
		//if this is a request for a new device id
		if($path[1] == "New"){
			//if already has a device id
			if($known){
				ErrorResponse(400);
				exit;
			}
			$this->registerDevice();
			return;
		}
		//if the client has requested their user details
		if($path[1] == "Details" && $known){
			//if the user has registered, but is not logged in
			if(!$user['user_id']){
				ErrorResponse(400);
				exit;
			}
			$this->getAccountDetails($user['user_id']);
			return;
		}
		//if the client wants a list of devices linked to their account
		if($path[1] == "Devices"){
			
			ErrorResponse(500, "feature is not yet available");
			
			exit;
		}
		
		//if here, then bad request
		ErrorResponse(400);
		exit;
	}
		
	public function registerDevice(){
		//create a new device record
		$uid = \Utilities::UID(20);
		$query = new \osql\Query("devices");
		$query->addField("id")
			->addClause("uid", $uid)
			->setLimit(1);
		while($this->db->OQuery($query)){
			$uid = \Utilities::UID(20);
		}
		$query = new \osql\Query("devices", \osql\QueryTypes::$Insert);
		$query->addValue("status", \Database\StatusCodes::$Active)
			->addValue("created", time())
			->addValue("details", \API::getHTTPHeader("User-Agent"))
			->addValue("uid", $uid);
		try{
			$deviceid = $this->db->OQuery($query);
		}
		catch(\Exception $e){
			ErrorResponse(500);
			
			//todo - add details to the log
			
			\Logger::Error("DB error saving new device record", $e);
			exit;
		}
		
		\API::outputJson(["device_id"=>$uid]);
	}
	
	public function getAccountDetails($userid){
		$query = new \osql\Query("users");
		$query->addField("created")
			->addField("name")
		//	->addField("screen_name")
			->addField("email")
			->addField("feed_creator")
			->addClause("id", $userid)
			->setLimit(1);
		try{
			$details = $this->db->OQuery($query);
		}
		catch(\Exception $e){
			ErrorResponse(500);
			
			//todo - add details to the log
			
			\Logger::Error("DB error fetching user details", $e);
			exit;
		}
		
		//convert 'feed_creator' to boolean
		if($details['feed_creator']){
			$details['feed_creator'] = true;
		}
		else{
			$details['feed_creator'] = false;
		}
		//now fetch feed user details
		$query = new \osql\Query("feed_users");
		$query->addField("feed_id")
			->addField("created")
			->addField("admin")
			->addClause("user_id", $userid)
			->addClause("status", \Database\StatusCodes::$Active);
		try{
			$feedusers = $this->db->OQuery($query);
		}
		catch(\Exception $e){
			ErrorResponse(500);
			
			//todo - add details to the log
			
			\Logger::Error("DB error fetching user's feed permissions", $e);
			exit;
		}
		
		$details['feeds'] = $feedusers;
		\API::outputJson($details);
	}
	
	##############################################################
	
	//path options: /Account/[Create,Password,Login,Logout,Forgot]
	public function HandlePost($path){
		//check if user is known
		$user = getAuthenticatedUser($this->db);
		if(!$user){
			ErrorResponse(400);
			exit;
		}
		//user has a device id but is not logged in
		if(!$user['user_id'] && $user['device_id']){
			//a new account is needed
			if(sizeof($path) == 1){
				ErrorResponse(400);
				exit;
			}
			if($path[1] == "Create"){
				$this->createAccount($user['device_id']);
				return;
			}
			//user is trying to log in
			if($path[1] == "Login"){
				$this->login($user['device_id']);
				return;
			}
			//if the user is logging out, but they've already logged out and don't know it
			if($path[1] == "Logout"){
				return;
			}
			//client forgot their password
			if($path[1] == "Forgot"){
				
				//todo
				
				return;
			}
			ErrorResponse(400);
			exit;
		}
		//if there is no additional path, must be updating account
		if(sizeof($path) == 1){
			$this->updateAccount($user['user_id']);
			return;
		}
		//user doesn't realize they're already logged in, so just politely say ok
		if($path[1] == "Login"){
			\API::outputJson();	//required to make CORS happy //http_response_code(200);		//not necessary but helps the idea
			return;
		}
		//if the client wants to disconnect the device from their user account
		if($path[1] == "Logout"){
			$this->logout($user['device_id']);
			return;
		}
		//if the client wants to reset their password
		if($path[1] == "Password"){
			$this->changePassword($user['user_id']);
			return;
		}
		
		//no match, so must be a bad request
		ErrorResponse(400);
		exit;
	}
	
	private function login($deviceid){
		$request = \API::getRequestBody();
		$request = \LOEN::decode($request);
		$loginObj = new \Feed\Action\AccountModel($request);
		
		//look for account matching email address
		$query = new \osql\Query("users");
		$query->addField("id", "user_id")
			->addField("password")
			->addClause("status", \Database\StatusCodes::$Active)
			->addClause("email", $loginObj->email)
			->setLimit(1);
		try{
			$account = $this->db->OQuery($query);
		}
		catch(\Exception $e){
			ErrorResponse(500);
			
			//todo - add details to the log
			
			\Logger::Error("DB error fetch login account", $e);
			exit;
		}
		
		//if the account is invalid, or the password does not match
		if(!$account || !\PasswordHash::CheckPassword($loginObj->password, $account['password'])){
			ErrorResponse(401);
			exit;
		}
		
		//all good, so update the device record with the user id
		$query = new \osql\Query("devices", \osql\QueryTypes::$Update);
		$query->addValue("user_id", $account['user_id'])
			->addClause("id", $deviceid);
		try{
			$this->db->OQuery($query);
		}
		catch(\Exception $e){
			ErrorResponse(500);
			
			//todo - add details to the log
			
			\Logger::Error("DB error saving login", $e);
			exit;
		}
		
		$this->getAccountDetails($account['user_id']);
	}
	
	private function createAccount($deviceid){
		//check if new accounts are allowed to be created
		if(!\Feed\Config::$allownewaccount){
			ErrorResponse(403);
			exit;
		}
		
		$request = \API::getRequestBody();
		$request = \LOEN::decode($request);
		$account = new \Feed\Action\AccountModel($request);
		
		//validate account
		//	- todo
		
		//check first if account with that email already exists, and is active
		$query = new \osql\Query("users");
		$query->addField("id")
			->addClause("email", $account->email)
			->addClause("status", \Database\StatusCodes::$Active)
			->setLimit(1);
		try{
			$check = $this->db->OQuery($query);
			if($check){
				ErrorResponse(400,"account already exists");
				//echo "account already exists";
				exit;
			}
		}
		catch(\Exception $e){
			ErrorResponse(500);
			
			//todo - add details to the log
			
			\Logger::Error("DB error checking for existing account", $e);
			exit;
		}
		
		$query = new \osql\Query("users", \osql\QueryTypes::$Insert);
		$query->addValue("status", $account->status)
			->addValue("created", time())
			->addValue("name", $account->name)
			->addValue("email", $account->email)
			->addValue("password", \PasswordHash::HashPassword($account->password));
		
		//check if all new users the 'feed_creator' attribute by default
		if(\Feed\Config::$newaccountfeedcreator){
			$query->addValue("feed_creator", 1);
		}
		
		try{
			$userid = $this->db->OQuery($query);
		}
		catch(\Exception $e){
			ErrorResponse(500);
			
			//todo - add details to the log
			
			\Logger::Error("DB error saving new account", $e);
			exit;
		}
		
		//now log the new user in
		$query = new \osql\Query("devices", \osql\QueryTypes::$Update);
		$query->addValue("user_id", $userid)
			->addClause("id", $deviceid);
		try{
			$this->db->OQuery($query);
		}
		catch(\Exception $e){
			ErrorResponse(500);
			
			//todo - add details to the log
			
			\Logger::Error("DB error logging in new account", $e);
			exit;
		}
		
		$this->getAccountDetails($userid);
	}
	
	private function updateAccount($userid){
		$request = \API::getRequestBody();
		$request = \LOEN::decode($request);
		$account = new \Feed\Action\AccountModel($request);
		
		$query = new \osql\Query("users", \osql\QueryTypes::$Update);
		$query->addValue("status", $account->status)
			->addValue("name", $account->name)
			->addValue("email", $account->email)
			->addClause("id", $userid);
		try{
			$this->db->OQuery($query);
		}
		catch(\Exception $e){
			ErrorResponse(500);
			
			//todo - add details to the log
			
			\Logger::Error("DB error saving account update", $e);
			exit;
		}
	}
	
	private function logout($deviceid){
		$query = new \osql\Query("devices", \osql\QueryTypes::$Update);
		$query->addvalue("user_id", null)
			->addClause("id", $deviceid);
		try{
			$this->db->OQuery($query);
		}
		catch(\Exception $e){
			ErrorResponse(500);
			
			//todo - add details to the log
			
			\Logger::Error("DB error logging out", $e);
			exit;
		}
	}
	
	private function changePassword($userid){
		$request = \API::getRequestBody();
		$request = \LOEN::decode($request);
		$passObj = new \Feed\Action\PasswordModel($request);
		
		//validate old password
		
		
		$newpass = \PasswordHash::HashPassword($passObj->new_password);
		
		$query = new \osql\Query("users", \osql\QueryTypes::$Update);
		$query->addValue("password", $newpass)
			->addClause("id", $userid);
		try{
			$this->db->OQuery($query);
		}
		catch(\Exception $e){
			ErrorResponse(500);
			
			//todo - add details to the log
			
			\Logger::Error("DB error saving password update", $e);
			exit;
		}
	}
}
?>