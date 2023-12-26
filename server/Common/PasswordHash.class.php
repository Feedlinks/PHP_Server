<?php
#
# Uses PHP Bcrypt - requires php 5.3 or higher
# 
class PasswordHash {
	//https://www.php.net/manual/en/function.password-hash.php#124138
	////change this on each server deployed to - cannot change or hashed values cannot be verified
	private static $pepper = "gfds8943hrhKJHY782gpo9";
	
	public static function HashPassword($password,$shortoutput=false){
		//if we want to add extra security to our hashes
		if(self::$pepper){
			$password = hash_hmac("sha256", $password, self::$pepper);
		}
		//if we need to ensure the output is only 60 characters
		if($shortoutput){
			return password_hash($password, PASSWORD_BCRYPT);
		}
		//if we can handle up to 255 characters
		else{
			return password_hash($password, PASSWORD_DEFAULT);
		}
	}

	public static function CheckPassword($password, $hash){
		if(self::$pepper){
			$password = hash_hmac("sha256", $password, self::$pepper);
		}
		return password_verify($password, $hash);
	}
}
?>