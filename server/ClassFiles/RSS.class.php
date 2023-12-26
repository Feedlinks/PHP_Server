<?php

class RSS{
	public static function ReadFeed($url){
		$feed = self::curl_get($url);
		$feed = @simplexml_load_string($feed);
		//$feed = @simplexml_load_file($url);
		if($feed === false){
			return null;
		}
		if(property_exists($feed,"channel")){
			return self::ProcessRss2Feed($feed);
		}
		return self::ProcessAtomFeed($feed);
	}
	
	/**
	* Send a GET requst using cURL
	* @param string $url to request
	* @param array $options for cURL
	* @return string
	*/
	private static function curl_get($url, array $options = [])
	{   
		$defaults = array(
			CURLOPT_URL => $url,
			CURLOPT_HEADER => 0,
			CURLOPT_RETURNTRANSFER => TRUE,
			CURLOPT_TIMEOUT => 4
		);

		$ch = curl_init();
		curl_setopt_array($ch, ($options + $defaults));
		if( ! $result = curl_exec($ch)){
			//trigger_error(curl_error($ch));
		}
		curl_close($ch);
		return $result;
	}
	
	private static function ProcessAtomFeed($feed){
		$posts = [];
		foreach($feed->entry as $entry){
			$post = new RSSPost();
			$post->id = $entry->id;
			if(property_exists($entry, "updated") && $entry->updated){
				$post->date = strtotime($entry->updated);
			}
			else{
				$post->date = time();
			}
			$post->title = self::ParseProperties($entry->title);
			$post->description = self::ParseProperties($entry->summary);
			$post->link = get_object_vars($entry->link)["@attributes"]["href"];
			$posts[] = $post;
		}
		return $posts;
	}
	
	//also parses RSS 0.92
	private static function ProcessRss2Feed($feed){
		$posts = [];
		foreach($feed->channel->item as $item){
			$post = new RSSPost();
			if(property_exists($item, "guid")){
				$post->id = self::ParseProperties($item->guid);
			}
			else if(property_exists($item, "post-id")){
				$tmp = "post-id";
				$post->id = (string)$item->$tmp;
			}
			else{
				//no id, so can't work with this
				continue;
			}
			if(property_exists($item, "pubDate") && $item->pubDate){
				$post->date = strtotime($item->pubDate);
			}
			else if(property_exists($feed->channel, "lastBuildDate")){
				$post->date = strtotime($feed->channel->lastBuildDate);
			}
			else{
				$post->date = time();
			}
			$post->title = (string)$item->title;
			$post->description = (string)$item->description;
			$post->link = (string)$item->link;
			$posts[] = $post;
		}
		return $posts;
	}
	
	private static function ParseProperties($obj){
		$str = "";
		foreach(get_object_vars($obj) as $key => $prop){
			if(substr($key, 0, 1) == "@"){
				continue;
			}
			if(strlen($str)){
				$str .= " ";
			}
			$str .= (string)$prop;
		}
		return $str;
	}
}

class RSSPost{
	public $id;
	public $date;
	public $title;
	public $description;
	public $link;
}
?>