<?php
/**
 * Upgrade core functionality
 *
 * @package remotecontrol
 * @copyright Torgesta Technology AB 2013
 * 
 */

require_once(ABSPATH . 'wp-includes/update.php');
require_once(ABSPATH . 'wp-admin/includes/update.php');
require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
require_once(ABSPATH . 'wp-admin/includes/file.php');
require_once(ABSPATH . 'wp-admin/includes/class-wp-upgrader.php' );

/**
 * Upgrade core functionality 
 *
 * @package remotecontrol
 * @copyright Torgesta Technology AB 2013
 */
class remotecontrolpanel_core {

	function get_core() 
	{
		global $wp_version;
		$ret = array();
		$ret['current_version']	= (string)$wp_version;

		wp_version_check();

		// Different versions of wp store the updates in different places
		if( function_exists( 'get_site_transient' ) && $transient = get_site_transient( 'update_core' ) )
		{
			$current = $transient;	
		}
		elseif( $transient = get_transient( 'update_core' ) )
		{
			$current = $transient;
		}
		else
		{
			$current = get_option( 'update_core' );	
		}
		$update = $current->updates[0];
		$ret['latest_version'] = $update->current;
		$ret['hint'] = $update->response;

		return $ret;
	}

	function upgrade_core() 
	{
		wp_version_check();
		$updates = get_core_updates();
		$foo = AUTH_KEY;

		if (is_wp_error($updates) || ! $updates) 
		{
			return array('status' => 500, 'error' => 'No upgrades available');
		}

		$update = reset($updates);
		if (! $update) 
		{
			return array('status' => 500, 'error' => 'No upgrades available');
		}		
			
		$skin = new remotecontrolpanel_upgrade_core_skin();
		$upgrader = new Core_Upgrader($skin);
		$result = $upgrader->upgrade($update);
		if ($skin->error) 
		{
			return array('status' => 500, 'error' => $skin->error);
		}	

		require( ABSPATH . WPINC . '/version.php' );
		wp_upgrade();
		$update = reset( $updates );

		return array('status' => 200);
	}
}

class remotecontrolpanel_upgrade_core_skin extends WP_Upgrader_Skin {
	var $feedback;
	var $error;

	function error( $error ) 
	{
		$this->error = $error;
	}

	function feedback( $feedback ) 
	{
		$this->feedback = $feedback;
	}

	function before() { }

	function after() { }

	function header() { }

	function footer() { }

}	


