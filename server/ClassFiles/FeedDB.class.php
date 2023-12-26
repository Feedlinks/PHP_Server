<?php
namespace Feed;

class FeedDB{
	public static $versions = [
		"New",
		"First",
		"RSS"
	];
	
	public static function version_New(){
		//create settings table
		$sql[] = "CREATE TABLE settings(
			id INT PRIMARY KEY NOT NULL AUTO_INCREMENT,
			name VARCHAR(30),
			value VARCHAR(80)
		) ENGINE=innodb;";
		
		return $sql;
	}
	
	public static function version_First(){
		//create feeds table
		$sql[] = "CREATE TABLE feeds (
			id INT PRIMARY KEY NOT NULL AUTO_INCREMENT,
			status INT(1),
			created INT,
			name VARCHAR(80),
			description VARCHAR(500),
			url VARCHAR(2048),
			private INT(1),
			registered INT(1),
			unlisted INT(1)
		) ENGINE=innodb;";
		//set feeds to start at higher number than 1
		$sql[] = "ALTER TABLE feeds AUTO_INCREMENT=10001;";
		
		//create users table - for tracking users who operate while logged in
		$sql[] = "CREATE TABLE users (
			id INT PRIMARY KEY NOT NULL AUTO_INCREMENT,
			status INT(1),
			created INT,
			name VARCHAR(100),
			screen_name VARCHAR(30),
			email VARCHAR(100),
			password VARCHAR(100),
			feed_creator INT(1)
		) ENGINE=innodb;";
		
		//create devices table - for tracking non-logged and logged in users
		$sql[] = "CREATE TABLE devices (
			id INT PRIMARY KEY NOT NULL AUTO_INCREMENT,
			status INT(1),
			created INT,
			uid VARCHAR(200),
			details VARCHAR(500),
			user_id INT,
			signin_token VARCHAR(20)
		) ENGINE=innodb;";
		
		//create feed admin table to track who can post to feeds
		$sql[] = "CREATE TABLE feed_users (
			id INT PRIMARY KEY NOT NULL AUTO_INCREMENT,
			status INT(1),
			created INT,
			feed_id INT,
			user_id INT,
			admin INT (1)
		) ENGINE=innodb;";
		$sql[] = "CREATE INDEX idx_feed_user ON feed_users (feed_id, user_id)";
		
		//create posts table, for tracking posts to a feed
		$sql[] = "CREATE TABLE posts (
			id INT PRIMARY KEY NOT NULL AUTO_INCREMENT,
			status INT(1),
			created INT,
			user_id INT,
			feed_id INT,
			postdata TEXT,
			image INT(1),
			image_url VARCHAR(2048)
		) ENGINE=innodb;";
		$sql[] = "CREATE INDEX idx_feed_post ON posts (feed_id)";
		
		//make sure that posts.postdata can handle all sorts of characters
		$sql[] = "ALTER TABLE posts MODIFY COLUMN postdata TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL;";
		
		return $sql;
	}
	
	//RSS functionality employs discrete tables, so that we can more easily deprecate RSS later (hopefully)
	public static function version_RSS(){
		$sql[] = "CREATE TABLE rss (
			id INT PRIMARY KEY NOT NULL AUTO_INCREMENT,
			status INT(1),
			feed_id INT,
			url VARCHAR(2048),
			label VARCHAR(250)
		) ENGINE=innodb;";
		$sql[] = "CREATE INDEX idx_feed_rss ON rss (feed_id)";
		
		$sql[] = "CREATE TABLE post_rss (
			post_id INT,
			rss_id INT,
			rss_post_id VARCHAR(2048)
		) ENGINE=innodb;";
		$sql[] = "CREATE INDEX idx_rss_post_rss ON post_rss (rss_id)";
		
		return $sql;
	}
	
	public static function version_Calendar(){
		
	}
	
	public static function version_JoinRequests(){
		
	}
	
	public static function version_Likes(){
		
	}
	
	public static function version_Changelog(){
		
	}
	
	public static function version_AdvertisingPreferences(){
		
	}
}

?>
