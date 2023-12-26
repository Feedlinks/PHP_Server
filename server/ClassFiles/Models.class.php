<?php
namespace Feed\Action;

class FeedAdminLevels{
	public static $Browser = 0;
	public static $Poster = 1;
	public static $Editor = 2;
}

class FeedModel{
	public $status;
	public $name;
	public $description = null;
	public $url = null;
	public $private = null;
	public $unlisted = null;
	
	public function __construct($stdObj = null){
		$this->status = \Database\StatusCodes::$Active;
		if(!$stdObj){
			return;
		}
		\Utilities::InitModel($this, $stdObj, false);
	}
}

class FeedUsersModel{
	public $status;
	public $email;
	public $admin;
	
	public function __construct($stdObj = null){
		$this->status = \Database\StatusCodes::$Active;
		
		if(!$stdObj){
			return;
		}
		\Utilities::InitModel($this, $stdObj, false);
		
		if($this->email){
			$this->email = strtolower($this->email);
		}
	}
}

class FeedRssModel{
	public $id;
	public $status;
	public $feed_id;
	public $label;
	public $url;
	
	public function __construct($stdObj = null){
		$this->status = \Database\StatusCodes::$Active;
		if(!$stdObj){
			return;
		}
		\Utilities::InitModel($this, $stdObj, false);
	}
}

class PostModel{
	public $status;
	public $feed_id;
	public $text = "";
	public $image_url = null;
	public $event_date = null;
	
	public function __construct($stdObj = null){
		$this->status = \Database\StatusCodes::$Active;
		if(!$stdObj){
			return;
		}
		\Utilities::InitModel($this, $stdObj, false);
	}
}

class AccountModel{
	public $status;
	public $name;
	public $email;
	public $password;
	
	public function __construct($stdObj = null){
		$this->status = \Database\StatusCodes::$Active;
		
		if(!$stdObj){
			return;
		}
		\Utilities::InitModel($this, $stdObj, false);
		
		if($this->email){
			$this->email = strtolower($this->email);
		}
	}
}

class PasswordModel{
	public $password;
	public $new_password;
	
	public function __construct($stdObj = null){
		if(!$stdObj){
			return;
		}
		\Utilities::InitModel($this, $stdObj, false);
	}
}
?>