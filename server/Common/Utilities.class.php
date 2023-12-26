<?php
class Utilities{
	public static function UID($length=8,$numeric=FALSE){
		if($numeric){
			$max = 9;
			if($max % 2 == 0){
				$max = 99;
			}
			//make sure to never start with a zero
			$uid = random_int(1, $max);
			while(strlen($uid) < $length){
				$uid .= random_int(0, 99);
			}
			return $uid;
		}
		$bytes = openssl_random_pseudo_bytes($length);
		$bytes = md5($bytes);
		$num = mt_rand(0,32-$length);		//md5 is 32 in length
		return substr($bytes,$num,$length);
	}
	
	public static function AutoInclude(string $path, string $ext="php", array $ignore=NULL){
		$fileArr = array();
		$files = scandir(get_include_path().$path);
		foreach($files as $file){
			if($file == '.' || $file == '..'){
				continue;
			}
			$fileName = $file;
			$file = $path."/".$file;
			if($ignore && in_array($file,$ignore)){
				continue;
			}
			if(is_dir($file)){
				$fileArr = self::AutoInclude($file,$ext,$ignore);
			}
			else{
				$pos = strrpos($file,".");
				if($pos !== FALSE){
					//does extension match
					if(substr($file,$pos+1) != $ext){
						continue;
					}
				}
				elseif($ext !== ''){
					continue;
				}
				include_once($file);
			}
		}
	}
	
	public static function InitModel(&$obj,$data,$removeUnset=TRUE){
		$setKeys = array();
		if(!$data){
			//do nothing
			return;
		}
		else if(is_array($data)){
			foreach($data as $key => $value){
				if(property_exists($obj,$key)){
					$obj->$key = $value;
					$setKeys[] = $key;
				}
			}
		}
		else if(is_object($data)){
			$list = get_object_vars($data);
			foreach($list as $key => $value){
				if(property_exists($obj,$key)){
					$obj->$key = $data->$key;
					$setKeys[] = $key;
				}
			}
		}
		if($removeUnset){
			//unset unset properties so that NULLs are not saved when not desired
			$list = get_object_vars($obj);
			foreach($list as $key => $value){
				if(!in_array($key,$setKeys)){
					unset($obj->$key);
				}
			}
		}
	}
	
	public static function ObjectStripDefaults(&$target,$reference){
		$properties = get_object_vars($reference);
		
		foreach($properties as $key => $value){
			if(property_exists($target,$key) && $target->$key === $reference->$key){
				unset($target->$key);
			}
		}
	}
	
	public static function GetRootURL(){
		$servername = $_SERVER['SERVER_NAME'];
		if(isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST']){
			$servername = $_SERVER['HTTP_HOST'];
		}
		$url = realpath(get_include_path()."/");
		$url = str_replace("\\","/",$url);				//do this here as DOCUMENT_ROOT will not have backslashes, even on Windows
		//if the path contains a drive letter, then drop it
		if(substr($url,1,1) == ":"){
			$url = substr($url,2);
			$url = str_replace(substr($_SERVER["DOCUMENT_ROOT"],2),"",$url);
		}
		else if(strpos($url,$_SERVER["DOCUMENT_ROOT"]) !== FALSE){
			$url = str_replace($_SERVER["DOCUMENT_ROOT"],"",$url);
		}
		//handle linux symlink
		else{
			$url = get_include_path();
			$count = 1;
			switch($url){
				case ".":break;
				case "..":
					$count = 2;break;
				default:
					$count = substr_count($url,"/");
					//if the last character is a slash, one less
					if(substr($url,strlen($url)-1,1) == "/"){
						$count--;
					}
					break;
			}
			$url = $_SERVER['PHP_SELF'];
			for($i=0; $i<$count; $i++){
				$url = substr($url,0,strrpos($url,"/"));
			}
		}
		if($url && strpos($url,"/") !== 0 && strrpos($servername,"/") !== strlen($servername) - 1){
			$url = "/".$url;
		}
		$url = "https://".$servername.$url."/";
		return $url;
	}
	
	public static function FetchHttpHeaders($target=NULL){
		error_log("FetchHttpHeaders deprecated: use equivalent in API.class instead");
		$target = strtoupper($target);
		$final = $headers = array();
		foreach($_SERVER as $name => $value){
			if(substr($name, 0, 5) == 'HTTP_'){
				$headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
			}
		}
		foreach($headers as $name => $value){
			$name = strtoupper($name);
			if($target && $target == $name){
				return $value;
			}
			$final[$name] = $value;
		}
		if($target){
			return FALSE;
		}
		return $final;
	}
	
	public static function CurlPost($url, $postdata, $contenttype="application/json"){
		$ch = curl_init();
		
		curl_setopt_array($ch, [
			CURLOPT_URL => $url,
			CURLOPT_POST => 1,
			CURLOPT_POSTFIELDS => $postdata,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER => 0,
			CURLOPT_HTTPHEADER => ["Content-Type: ".$contenttype, "Content-Length: ".strlen($postdata)],
			CURLOPT_RETURNTRANSFER => TRUE,
			CURLOPT_TIMEOUT => 4
		]);
		$server_output = curl_exec($ch);
		/*
		if(!$server_output){
			\Logger::Warn("error sending curl post - ".curl_error($ch));
		}
		*/
		$responseCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		
		curl_close ($ch);

		//if we did not get a good response
		if(!$server_output){
			return false;
		}
		if($responseCode != 200){
			//\Logger::Warn("error response on curl post: $responseCode - ".$server_output);
			return false;
		}
		
		return $server_output;
	}
}
?>
