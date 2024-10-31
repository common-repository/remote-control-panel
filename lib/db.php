<?php

class remotecontrolpanel_db extends wpdb {

	private $has_connected = false;	

	public function __construct() {
		global $wpdb;

		if ( function_exists( 'mysqli_connect' ) ) {
			if ( defined( 'WP_USE_EXT_MYSQL' ) ) {
				$this->use_mysqli = ! WP_USE_EXT_MYSQL;
			} elseif ( version_compare( phpversion(), '5.5', '>=' ) || ! function_exists( 'mysql_connect' ) ) {
				$this->use_mysqli = true;
			} elseif ( false !== strpos( $GLOBALS['wp_version'], '-' ) ) {
				$this->use_mysqli = true;
			}
		}

		foreach (get_object_vars($wpdb) as $key => $value) {
        	$this->$key = $value;
    	}
    	if($this->dbh) $this->has_connected = TRUE;
    	$wpdb = $this;
	}
	
	function query( $query ) {
		$ret = parent::query( $query );
		do_action('post_query', $query, $this->insert_id);
		return $ret;
	}
}

if (class_exists('W3_DbProcessor')) {

	class remotecontrolpanel_w3dbprocessor extends W3_DbProcessor {

		public function __construct() {
			global $wpdb;

			for($i=0; $i<count($wpdb->processors); $i++) {
				$class = get_class($wpdb->processors[$i]);
				if($class == 'W3_DbProcessor') {
					$this->manager = $wpdb->processors[$i]->manager;						
					$this->underlying_manager = $wpdb->processors[$i]->underlying_manager;

					$wpdb->processors[$i] = $this;
					break;
				}
			}
		}

		function query( $query ) {
			$ret = parent::query( $query );
			do_action('post_query', $query, $this->manager->insert_id);
			return $ret;
		}		

	}
}
