<?php
/**
 * Plugin functionality  *
 *
 * @package remotecontrol
 * @copyright Torgesta Technology AB 2013
 * 
 */

require_once(ABSPATH . 'wp-admin/includes/plugin.php');
require_once(ABSPATH . 'wp-admin/includes/file.php');
require_once(ABSPATH . 'wp-admin/includes/class-wp-upgrader.php' );
require_once(ABSPATH . 'wp-admin/includes/screen.php' );
require_once(ABSPATH . 'wp-admin/includes/misc.php' );
require_once(ABSPATH . 'wp-includes/http.php' );

/**
 * Plugin functionality 
 *
 * @package remotecontrol
 * @copyright Torgesta Technology AB 2013
 */
class remotecontrolpanel_plugins {

	function get_plugins() 
	{
		//_wpr_add_non_extend_plugin_support_filter();

		// Get all plugins
		$plugins = get_plugins();
		$active  = get_option( 'active_plugins', array() );

		// Force fresh data
		if ( function_exists( 'get_site_transient' ) )
		{
			delete_site_transient( 'update_plugins' );
		}
		else
		{
			delete_transient( 'update_plugins' );	
		}
		wp_update_plugins();

		// Different versions of wp store the updates in different places
		if( function_exists( 'get_site_transient' ) && $transient = get_site_transient( 'update_plugins' ) )
		{
			$current = $transient;	
		}
		elseif( $transient = get_transient( 'update_plugins' ) )
		{
			$current = $transient;
		}
		else
		{
			$current = get_option( 'update_plugins' );	
		}

		foreach((array) $plugins as $plugin_file => $plugin) 
		{
			$new_version = NULL;
			if(isset($current->response[$plugin_file]))
			{
				$new_version = $current->response[$plugin_file]->new_version;
			}

			if (is_plugin_active( $plugin_file))
			{
				$plugins[$plugin_file]['active'] = true;
			}
			else
			{
				$plugins[$plugin_file]['active'] = false;
			}
			
			if ($new_version) 
			{
				$plugins[$plugin_file]['latest_version'] = $new_version;
				$plugins[$plugin_file]['latest_package'] = $current->response[$plugin_file]->package;
				$plugins[$plugin_file]['slug']           = $current->response[$plugin_file]->slug;
			} 
			else 
			{
				$plugins[$plugin_file]['latest_version'] = $plugin['Version'];
			}
			$plugins[$plugin_file]['plugin_file'] = $plugin_file;
		}

		return $plugins;
	}

	function plugin_upgrader_exists() 
	{
		$retval = FALSE;
		if(class_exists('Plugin_Upgrader'))
		{
			$retval = TRUE;
		}
		return $retval;
	}


	function upgrade_plugin( $plugin ) 
	{

		$skin = new remotecontrolpanel_upgrade_plugin_skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$is_active = is_plugin_active( $plugin );

		// Force a plugin update check
		wp_update_plugins();

		// Do the upgrade
		ob_start();
		$result = $upgrader->upgrade( $plugin );
		$data = ob_get_contents();
		ob_clean();

		if ($skin->error)
			return array( 'status' => 500, 'error' => $skin->error );
		if((!$result && ! is_null( $result ) ) || $data )
			return array( 'status' => 500, 'error' => 'file_permissions_error' );
		elseif (is_wp_error( $result))
			return array( 'status' => 500, 'error' => $result->get_error_code() );

		$ret = array('status' => 200, 'reactivate' => 0);
		if($is_active) 
		{
			$ret['reactivate'] = 1;	
			if (strpos( $plugin, 'remotecontrolpanel' ) !== FALSE ) 
			{
				// call activate on our selves.
				$ret['reactivate'] = 0;	
				activate_plugin( $plugin, '', FALSE, TRUE );
			}
		}
		return $ret;
	}

	function install_plugin( $url )
	{
		$ret = array();

		$upgrader = new Plugin_Upgrader( new Plugin_Installer_Skin( compact('type', 'title', 'nonce', 'url'))); 
		$upgrader->init();
		$res = $upgrader->run(array(
					'package' => $url,
					'destination' => WP_PLUGIN_DIR,
					'clear_destination' => false, //Do not overwrite files.
					'clear_working' => true,
					'hook_extra' => array()
					));

		$plugin_info = $upgrader->plugin_info();
		if($plugin_info)
		{
			$ret['status']  = 200;
			$ret['plugin_file'] = $plugin_info;
		}
		else
		{
			// Since WP prints output directly to the browser, the problem
			// will be analyzed on the server
			$ret['status'] = 500;
		}

		// At this point, we most likely have some output from 
		// Wordpress core. So we need to mark it at such
		echo '__#@@#__';				
		return $ret;
	} 

	public function activate_plugin($plugin)
	{
		ob_start();
		$fail = new stdClass();
		$fail->action = 'activate_plugin';
		$fail->status = '500';
		$fail->message = 'Plugin broke PHP interpreter';
		echo json_encode($fail);
		$ret = activate_plugin($plugin);
		ob_clean();
		return array('status' => 200);
	}

	public function run_activate_plugin( $plugin ) 
	{
		$ret = activate_plugin($plugin);

		// if we're still here, we didn't get any fatal errors
		return array('status' => 200);
	}	

	public function deactivate_plugin($plugin)
	{
		$ret = array();

		$ret['status']  = 200;
		$this->run_deactivate_plugin($plugin);

		return $ret;
	}

	public function delete_plugin($plugin)
	{
		$ret = array();

		$ret['status']  = 200;
		delete_plugins(array($plugin));

		return $ret;
	}

	public function run_deactivate_plugin( $plugin ) 
	{
		$current = get_option( 'active_plugins' );
		$plugin = plugin_basename( trim( $plugin ) );

		if ( in_array( $plugin, $current ) ) 
		{
			$current = array_diff($current, array($plugin));
			sort( $current );
			do_action( 'deactivate_plugin', trim( $plugin ) );
			update_option( 'active_plugins', $current );
			do_action( 'deactivate_' . trim( $plugin ) );
			do_action( 'deactivated_plugin', trim( $plugin) );
		}
		return null;
	}	
}


class remotecontrolpanel_upgrade_plugin_skin extends Plugin_Installer_Skin {
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
