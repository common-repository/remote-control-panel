<?php
/**
 * Theme functionality  *
 *
 * @package remotecontrol
 * @copyright Torgesta Technology AB 2013
 * 
 */

require_once(ABSPATH . 'wp-admin/includes/theme.php');
require_once(ABSPATH . 'wp-admin/includes/file.php');
require_once(ABSPATH . 'wp-admin/includes/class-wp-upgrader.php' );

/**
 * Theme functionality 
 *
 * @package remotecontrol
 * @copyright Torgesta Technology AB 2013
 */
class remotecontrolpanel_themes {

	function get_themes() 
	{

		$this->force_fresh_transient();

		// Get all themes
		if (function_exists('wp_get_themes'))
		{
			$themes = wp_get_themes();
		}
		else
		{
			$themes = get_themes();
		}
		$active_name = get_option('current_theme');


		// Different versions of wp store the updates in different places
		if( function_exists( 'get_site_transient' ) && $transient = get_site_transient( 'update_themes' ) )
		{
			$current = $transient;	
		}
		elseif( $transient = get_transient( 'update_themes' ) )
		{
			$current = $transient;
		}
		else
		{
			$current = get_option( 'update_themes' );	
		}

		foreach ( (array) $themes as $theme ) 
		{
			$new_version = NULL;
			if(isset($current->response[$theme['Stylesheet']]))
			{
				$new_version = $current->response[$theme['Stylesheet']]['new_version'];
			}			
			// Wordpress 3.4 or later.
			if (is_object($theme) && is_a($theme, 'WP_Theme')) 
			{
				$active = FALSE;
				$theme_temp = $theme->template;
				$theme_name = $theme->get('Name');				

				if($theme->get('Name') == $active_name)
				{
					$active = TRUE;
				}

				$theme_array = array(
					'Name'           => $theme->get('Name'),
					'Template'       => $theme->get('Template'),
					'active'         => $active,
					'Stylesheet'     => $theme->get('Stylesheet'),
					'Template'       => $theme->get_template(),
					'Stylesheet'     => $theme->get_stylesheet(),
					'Screenshot'     => $theme->get_screenshot(),
					'AuthorURI'      => $theme->get('AuthorURI'),
					'Author'         => $theme->get('Author'),
					'latest_version' => $new_version ? $new_version : $theme->get( 'Version'),
					'Version'        => $theme->get('Version'),
					'ThemeURI'       => $theme->get('ThemeURI')
				);
				unset($themes[$theme_array['Stylesheet']]);	
				$themes[$theme['Name']] = $theme_array;				
			}
			else
			{
				if ($active_name == $theme['Name'] || $active_template == $theme['Template'])
				{
					$themes[$theme['Name']]['active'] = TRUE;	
				}
				else
				{
					$themes[$theme['Name']]['active'] = FALSE;	
				}
				if ($new_version) 
				{
					$themes[$theme['Name']]['latest_version'] = $new_version;
					$themes[$theme['Name']]['latest_package'] = $current->response[$theme['Template']]['package'];
				} 
				else 
				{
					$themes[$theme['Name']]['latest_version'] = $theme['Version'];
				}
			}
		}
		return $themes;
	}

	function upgrade_theme($theme) 
	{

		$this->force_fresh_transient();

		$skin = new remotecontrolpanel_upgrade_theme_skin();
		$upgrader = new Theme_Upgrader($skin);

		// Do the upgrade
		ob_start();
		$result = $upgrader->upgrade($theme);
		$data = ob_get_contents();
		ob_clean();

		if ($skin->error)
			return array( 'status' => 501, 'error' => $skin->error );
		if((!$result && ! is_null( $result ) ) || $data )
			return array( 'status' => 502, 'error' => 'file_permissions_error' );
		elseif (is_wp_error( $result))
			return array( 'status' => 503, 'error' => $result->get_error_code() );

		return array( 'status' => 200 );

	}

	function force_fresh_transient() {
		// Force fresh data
		if ( function_exists( 'get_site_transient' ) )
		{
			delete_site_transient( 'update_themes' );
		}
		else
		{
			delete_transient( 'update_themes' );	
		}
		wp_update_themes();
	}
}

class remotecontrolpanel_upgrade_theme_skin extends Theme_Installer_Skin {
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
