<?php
class API{
	public static function getHTTPHeader($headerName=NULL){
		$headers = [];
		foreach($_SERVER as $name => $value){
			if(substr($name, 0, 5) == 'HTTP_'){
				$name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
				if($headerName !== NULL && strtolower($headerName) === strtolower($name)){
					return $value;
				}
				$headers[$name] = $value;
			}
		}
		if($headerName !== NULL){
			//did not find a matching header
			return NULL;
		}
		return $headers;
	}
	
	public static function getHTTPMethod(){
		return strtoupper($_SERVER['REQUEST_METHOD']);
	}
	
	public static function getRequestBody(){
		return file_get_contents('php://input');
	}
	
	public static function outputJson($obj = null, $statusCode = 200){
		http_response_code($statusCode);
		self::sendCorsHeaders();
		$str = "";
		//check if the client will accept LOEN
		if(isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], "application/loen") !== false && class_exists("LOEN")){
			if($obj){
				$str = LOEN::encode($obj);
			}
			header('Content-type: application/loen; charset=utf-8');
			header("X-Content-Type-Options: nosniff");
		}
		//default to JSON
		else{
			if($obj){
				$str = json_encode($obj);
			}
			header('Content-type: application/json; charset=utf-8');
		}
		header('Content-Length: '.strlen($str));
		echo $str;
	}
	
	public static function sendCorsHeaders(){
		$headers = array(
			'Access-Control-Allow-Origin: *',
			'Access-Control-Max-Age: 86400',
			'Access-Control-Allow-Credentials: true',
			'Access-Control-Allow-Methods: GET, PUT, POST, DELETE, OPTIONS',
			'Access-Control-Allow-Headers: Authorization, Feedlinks, Pragma, Cache-Control',
			'Content-Type: text/plain'
		);
		foreach($headers as $header){
			header($header);
		}
	}
	
	public static function getPathSetGet(){
		$root = str_replace("index.php","",$_SERVER['PHP_SELF']);
		$path = $_SERVER['REQUEST_URI'];
		if($root != "/"){
			$path = str_replace($root,"",$_SERVER['REQUEST_URI']);
		}
		$pos = strpos($path,"?");
		if($pos !== FALSE){
			//restore $_GET
			parse_str(substr($path,$pos+1),$_GET);
			//var_dump($_GET);
			//strip parameters from the path
			$path = substr($path,0,$pos);
		}
		
		return $path;
	}
}
?>