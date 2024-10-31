<?php
/**
 * Various utils
 *
 */
class remotecontrolpanel_utils {
	
    public static $ignore = array(
        'wp-content/rcpsnapshots',   // this plugin
        'wp-snapshots',              // plugin: duplicator
        'wp-content/cache',          // General cache folder,
        '*/timthumb/cache',          // Timtumb can be found anywhere  
        '*/timthumb/cache',          // Timtumb can be found anywhere          
    );

    static public function rec_scandir($dir, $f) 
    {
        $dir = rtrim($dir, '/');
        $root = scandir($dir); 
        foreach($root as $value) 
        {
            if($value === '.' || $value === '..') continue; 
            if(self::fn_in_array("$dir/$value", self::$ignore)) continue;
            if(is_file("$dir/$value")) {
                self::fileinfo2file($f, "$dir/$value");
                continue;
            } 
            self::fileinfo2file($f, "$dir/$value");
            self::rec_scandir("$dir/$value", $f);
        } 
    }

    static public function fileinfo2file($f, $file) {
        $stat = stat($file);
        $sum = sha1($stat['size'] . $stat['mtime']);
        $relfile = substr($file, strlen(ABSPATH));
        $row =  array(
            $relfile, 
            is_dir($file)?0:$stat['mtime'],
            is_dir($file)?0:$stat['size'],
            is_dir($file)?0:$sum,
            (int)is_dir($file),
            (int)is_file($file),
            (int)is_link($file),
        );
        fwrite($f, join("\t", $row) . "\n");
    }


    static public function downloadfile($url, $target) {
        $ch = curl_init($url);
        // Make sure the file doesn't already exists
        @unlink($target);
        $fp = fopen($target, 'w');

        // set URL and other appropriate options
        $options = array(
                CURLOPT_FILE => $fp,
                CURLOPT_HEADER => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 60, // 1 minute timeout (should be enough)
                CURLOPT_SSL_VERIFYPEER => false,
        );
        curl_setopt_array($ch, $options);
        curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);
        fclose($fp);
    }


    static public function fn_in_array($needle, $haystack) {
        # this function allows wildcards in the array to be searched
        $needle = substr($needle, strlen(ABSPATH));# 
        foreach ($haystack as $value) {
            if (true === fnmatch($value, $needle)) {
                return true;
            }
        }
        return false;       
    } 

    static public function linecount($path) {
        $linecount = 0;
        $handle = fopen($path, "r");
        while(!feof($handle)){
            $line = fgets($handle);
            $linecount++;
        }
        return $linecount;
    }


    static public function get_db_state($f) {
        $db = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
        $db->set_charset( DB_CHARSET );	
        $tables = $db->query( 'SHOW TABLES' );
        while($table = $tables->fetch_row()) { 
            $meta = array();
            $meta[] = $table[0];
            $meta[] = self::table_checksum( $table[0], $db );
            $meta[] = md5(self::table_create( $table[0], $db ));
            fwrite($f, join("\t", $meta) . "\n");	
        }
    }

    static private function table_create( $table, $db ) {
        $query = 'SHOW CREATE TABLE `' .  $table . '`';
        if($result = $db->query( $query )) {
            $row = $result->fetch_row();
            return $row[1];
        }
        return FALSE;
    }

    static private function table_checksum( $table, $db ) {
        $query = "CHECKSUM TABLE $table";
        if( $result = $db->query( $query ) ) {
            $row = $result->fetch_object();
            return $row->Checksum;
        }
        return FALSE;
    }

    static public function compress_filelist($target_name, $file_list)
    {
        if(!class_exists('ZipArchive'))
        {
            return false;
        }
        $zip = new ZipArchive();
        if (!$zip->open($target_name, ZIPARCHIVE::CREATE)) 
        {
            return false;
        }
        foreach($file_list as $source) {
            $source = str_replace('\\', '/', realpath($source));
            $name = substr($source, strlen(ABSPATH));
            $zip->addFile($source, $name);
        }
        return $zip->close();
    }

    static public function compress_tables($target_name, $tables, $tmpfolder)
    {
        require_once('sqldump.php');
        $sql = new Sqldump();
        if(!class_exists('ZipArchive'))
        {
            return false;
        }
        $zip = new ZipArchive();
        if (!$zip->open($target_name, ZIPARCHIVE::CREATE)) 
        {
            return false;
        }
        foreach($tables as $table) {
            $sql->dump_file = $tmpfolder . '/' . $table;
            $sql->mysqldump($table);
            $zip->addFile($sql->dump_file, $table);
        }
	$ret = $zip->close();
        foreach($tables as $table) {
		@unlink($tmpfolder . '/' . $table);
	}
	return $ret;
        
    }



	/**
	 * Check is a function exists and is callable and is not blacklisted
	 * bu Sushoin
	 * 
	 * @param  string $name The name of the function
	 * 
	 * @return bool FALSE if we can't call the function. TRUE if we can
	 */
	public function function_exists($name)
	{
		if(!function_exists($name)) return FALSE;
		$disabled_functions = explode(',', @ini_get('disable_functions'));
		foreach($disabled_functions as $disabled_function)
		{
			if(trim(strtolower($disabled_function)) == trim(strtolower($name)))
			{
				return FALSE;
			}
		}

		$suhosin_functions = explode(',', @ini_get('suhosin.executor.func.blacklist'));
		foreach($suhosin_functions as $suhosin_function)
		{
			if(trim(strtolower($suhosin_function)) == trim(strtolower($name)))
			{
				return FALSE;
			}
		}
		return TRUE;
	}






	/**
	 * Execute an external function with whatever system
	 * command that is available
	 * 
	* @param 	string 			$command	external command to execute
	* @param 	bool[optional] 	$string		return as a system output string (default: false)
	* @param 	bool[optional] 	$rawreturn	return as a status of executed command
	* @return 	bool|int|string				output depends on parameters $string and $rawreturn, -1 if no one execute function is enabled
	*/
	function exec($command, $string = false, $rawreturn = false) {
		if ($command == '') return false;

		if($this->function_exists('exec')) 
		{
			$ret = @exec($command, $output, $return);
			if ($string) 	return $ret;
			if ($rawreturn)	return $return;
			return $return ? false : true;
		}

		if($this->function_exists('system')) 
		{
			$ret = @system($command, $return);
			if($string) return $ret;
			if ($rawreturn) return $return;
			return $return ? false : true;
		}

		if($this->function_exists('passthru') && !$string) 
		{
			$ret = passthru($command, $return);
			if ($rawreturn) return $return;
			return $return ? false : true;
		}

		if($rawreturn) return -1;
		return false;
	}



	public function get_memory_limit()
	{
		$memory_limit = trim(ini_get('memory_limit'));		
		$suffix = strtolower(substr($memory_limit, -1));
		if($suffix == 'g') $memory_limit = ((int) $memory_limit)*1024;
		if($suffix == 'm') $memory_limit = (int) $memory_limit;
		if($suffix == 'k') $memory_limit = ((int) $memory_limit)/1024;   		

		if($memory_limit == -1) $memory_limit = '9999';

		return $memory_limit;
	}

	public function set_memory_limit($new_limit = 384)
	{
		 @ini_set('memory_limit', $new_limit . 'M');
	}

	public function set_script_timeout($seconds)
	{
		@set_time_limit($seconds);	
	}	

	public function get_capabilities() {
		$caps = new stdClass();

		// Server OS
		$caps->server_os       = new stdClass();
		$caps->server_os->name = PHP_OS;
		$caps->server_os->pass = TRUE;
		if(stristr(PHP_OS, 'WIN'))
		{
			$caps->server_os->pass = FALSE;
		}

		// PHP Version
		$caps->php_version          = new stdClass();
		$caps->php_version->name    = PHP_VERSION;
		$caps->php_version->major   = PHP_MAJOR_VERSION;
		$caps->php_version->min§or  = PHP_MINOR_VERSION;
		$caps->php_version->release = PHP_RELEASE_VERSION;
		$caps->php_version->pass    = TRUE;
		if ((float) phpversion() < 5.1) $caps->php_version->pass = FALSE;

		// Content dir
		$caps->write_access = new stdClass();
		if (is_writable(WP_CONTENT_DIR)) 
		{
			$caps->write_access->name = 'Writable';
			$caps->write_access->pass = TRUE;
		} 
		else 
		{
			$caps->write_access->name = 'Not writable';
			$caps->write_access->pass = FALSE;
		}

		// Can we execute system commands?
		$caps->system_command = new stdClass();
		$caps->system_command->name = 'none';
		if ($this->function_exists('passhtru')) $caps->system_command->name = 'passthru';
		if ($this->function_exists('system')) $caps->system_command->name = 'system';
		if ($this->function_exists('exec')) $caps->system_command->name =  'exec';
		$caps->system_command->pass = FALSE;
		if($caps->system_command->name != 'none') $caps->system_command->pass = TRUE;

		// Can we do zip, tar, gzip etc?
		$caps->compression = new stdClass();
		$caps->compression->zip = new stdClass();
		if($caps->system_command->pass)
		{
			// there's a chance we can do zip from system
			$zip = $this->exec('which zip', TRUE);
			if($zip && strlen($zip) > 0) 
			{
				$caps->compression->zip->type = 'system';
				$caps->compression->zip->command = $zip;
			}
			else
			{
				// some type of internal php
				if(class_exists('ZipArchive'))
				{
					$caps->compression->zip->type = 'phpzip';
				}
				else
				{
					// pclzip comes budled with WP, it's the last
					// resort due to archive compatibility and memory consumption
					$caps->compression->zip->type = 'pclzip';	
				}
			}
		}


		//{
		//	$zip = $this->utils->exec('whitch zip');
		//	if($zip && $zip != '') $caps->compresssion->zip->path = $zip;
		//}

		$caps->php_memory = new stdClass();

		$caps->php_memory->default = $this->get_memory_limit();
		// Test if we can change it to at least 384 MB
		if($caps->php_memory->default < 384) $this->set_memory_limit(384);
		if($this->get_memory_limit() >= 384)
		{
			$caps->php_memory->pass = TRUE;
		}
		else
		{
			$caps->php_memory->pass = FALSE;
		}

		return $caps;
	}
}
