<?php
/**
 * Plugin Name: Remote control panel
 *
 * Description: Plugin to enable remote of wordpress administration via wpremotecontrolpanel.com
 *
 * Plugin URI: http://wordpress.org/plugins/remote-control-panel/
 * Version: 0.4.6
 * Author: Torgesta Technology
 * Author URI: http://erik.torgesta.com
 * @package remotecontrol
 * @copyright Torgesta Technology AB 2013
 */

require_once 'lib/remotecontrolpanel_utils.php';
require_once 'lib/db.php';

/**
 * The instantiated version of this plugin's class
 */
$GLOBALS['remotecontrolpanel'] = new remotecontrolpanel();

/**
 * Main class
 *
 * @package remotecontrolpanel
 * @link http://wordpress.org/extend/plugins/remotecontrolpanel/
 * @author Erik Torsner <erik@torgesta.com>
 * @copyright Torgesta Technology AB 2013
 *
 */
class remotecontrolpanel {
	/**
	 * This plugin's identifier
	 */
	const ID = 'remotecontrolpanel';

	/**
	 * This plugin's name
	 */
	const NAME = 'Remote Control Panel';

	/**
	 * This plugin's version
	 */
	const VERSION = '0.4.6';

	/**
	 * Folder name for our snapshots
	 */
	const SNAPSHOTS = 'rcpsnapshots';

	/**
	 * This plugin's options
	 *
	 */
	protected $options = array();

	/**
	 * This plugin's default options
	 * @var array
	 */
	protected $options_default = array(
		'apikey'              => '[notset]',
		'apikey_status'       => FALSE,
		'guid'                => '[notset]',
		'server_guid'         => '[notset]',
		'notifyurl'           => 'https://notify.remotecontrolpanel.net/',
		'notifyconn'          => FALSE,
		'cont_backup'         => FALSE,
	);

	/**
	 * Our option name for storing the plugin's settings
	 * @var string
	 */
	protected $option_name;

	/**
	 * DB update events 
	 * @var array
	 */
	protected $events = array();

	/**
	 * Declares the WordPress action and filter callbacks
	 *
	 * @return void
	 * @uses oop_plugin_template_solution::initialize()  to set the object's
	 *       properties
	 */
	public function __construct()
	{
		global $wpdb;

		if(class_exists('W3_DbProcessor')) {
			$tmp = new remotecontrolpanel_w3dbprocessor();
		} else {
			$tmp = new remotecontrolpanel_db();
		}
		$this->snapshotsfolder =  WP_CONTENT_DIR . '/' . self::SNAPSHOTS;

		$this->initialize();
		$this->utils = new remotecontrolpanel_utils();

		if($this->options['apikey'] == '[notset]') {
			$this->options['apikey'] = sha1(microtime(TRUE) . $_SERVER['REMOTE_ADDR'] . mt_rand() );
			update_option($this->option_name, $this->options);
		}
		if($this->options['guid'] == '[notset]') {
			$this->options['guid'] = sha1(get_site_url());
			update_option($this->option_name, $this->options);
		}

		if($this->options['cont_backup']) {
			add_action('updated_option', array($this, 'handle_updated_option'), 99, 3);
			add_action('added_option',   array($this, 'handle_added_option'),   99, 2);
			add_action('delete_option',  array($this, 'handle_deleted_option'), 99, 1);
			add_action('post_query',     array($this, 'handle_query'), 99, 2);
		}
		add_action('shutdown',       array($this, 'shutdown'), 99, 0 );		

		if (is_admin()) 
		{
			require_once dirname(__FILE__) . '/admin.php';
			$admin = new remotecontrolpanel_admin;
			$info = pathinfo(__FILE__);
			$filter = $info['filename'] . '/' . $info['basename'];

			if (is_multisite()) 
			{
				$admin_menu = 'network_admin_menu';
				$admin_notices = 'network_admin_notices';
				$plugin_action_links = 'network_admin_plugin_action_links_' . $filter;
			} 
			else 
			{
				$admin_menu = 'admin_menu';
				$admin_notices = 'admin_notices';
				$plugin_action_links = 'plugin_action_links_' . $filter;
			}

			add_action($admin_menu, array(&$admin, 'admin_menu'));
			add_action('admin_init', array(&$admin, 'admin_init'));
			add_filter($add_filter, array(&$admin, 'plugin_action_links'));

			register_activation_hook(__FILE__, array(&$admin, 'activate'));
			register_deactivation_hook(__FILE__, array(&$admin, 'deactivate'));

		}
		else
		{
			add_action( 'init', array(&$this, 'detect_api_request'), 1);
		}
	}

	/**
	 * Sets the object's properties and options
	 *
	 * @return void
	 *
	 * @uses oop_plugin_template_solution::set_options()  to replace the default
	 *       options with those stored in the database
	 */
	protected function initialize() {
		$this->option_name = self::ID . '-options';
		$this->set_options();
	}

	public function handle_query($sql, $inserted_id) {
		global $wpdb;

		if($this->is_write_query($sql)) {
			$tmp = new stdClass();
			$tmp->query = $sql;
			$tmp->timestamp = microtime(true);
			$this->queries[] = $tmp;

			$event = new stdClass();
			$event->microtime = microtime(TRUE);
			$event->type = 'QUERY';
			$event->sql = $sql;
			$event->inserted_id = $inserted_id;
			$this->events[] = $event;

		}
		return $sql;
	}

	private function is_write_query( $q ) {
		global $table_prefix;
		// Quick and dirty: only SELECT statements are considered read-only.
		$q = ltrim($q, "\r\n\t (");
		$ret = !preg_match('/^(?:SELECT|SHOW|DESCRIBE|EXPLAIN)\s/i', $q);
		if(!$ret) return $ret;

		// check if it's a query to wp_options
		if(0 === strpos($q, "INSERT INTO `{$table_prefix}options`")) return FALSE;
		if(0 === strpos($q, "INSERT IGNORE INTO `{$table_prefix}options`")) return FALSE;		
		if(0 === strpos($q, "UPDATE `{$table_prefix}options`")) return FALSE;
		if(0 === strpos($q, "DELETE FROM {$table_prefix}options")) return FALSE;

		// filter out other common queries that can be ignored.
		if (preg_match("/INSERT INTO `wp_postmeta` \(`post_id`,`meta_key`,`meta_value`\) VALUES \((\d+),'_encloseme','1'\)/", $q)) return FALSE;
		if (preg_match("/INSERT INTO `wp_postmeta` \(`post_id`,`meta_key`,`meta_value`\) VALUES \((\d+),'_pingme','1'\)/", $q)) return FALSE;
		if (preg_match("/UPDATE `wp_postmeta` SET `meta_value` = '(\d+):(\d+)' WHERE `post_id` = (\d+) AND `meta_key` = '_edit_lock'/", $q)) return FALSE;

		return $ret;
	}

	public function handle_updated_option($option, $old_value, $value) {
		$this->handle_option('UPDATED_OPTION', $option, $value);
	}

	public function handle_added_option($option, $value) {
		$this->handle_option('ADDED_OPTION', $option, $value);
	}

	public function handle_deleted_option($option) {
		$this->handle_option('DELETED_OPTION', $option);
	}	

	private function handle_option($type, $option, $value = NULL) {

			if(0 === strpos($option, "_transient_")) return;
			if(0 === strpos($option, "_site_transient")) return;
			if(0 === strpos($option, "_wc_session")) return;
			if(0 === strpos($option, "iwp_client_user_hit_count")) return;
			if(0 === strpos($option, "wysija_last_php_cron_call")) return;

			if($option == 'cron') return;

			$event = new stdClass();
			$event->microtime = microtime(TRUE);
			$event->type = $type;
			$event->option = $option;
			$event->value = $value;

			$this->events[] = $event;

	}

	public function shutdown() {
		if(defined( 'WP_IMPORTING' ) && constant( 'WP_IMPORTING')) return;
		if(count($this->events) == 0) return;
		if($this->options['guid'] != sha1(get_site_url())) return;

		require_once 'lib/restclient.php';
		$this->ensure_snapshotfolder_exists();
		$filename = 'notify_' . md5(microtime(TRUE));
		$path = $this->snapshotsfolder . "/" . $filename;
		file_put_contents($path, serialize($this->events));

		$attempts = 0;
		do {
			$attempts++;			

			$api = new RestClient(array(
				'base_url' => $this->options['notifyurl'],
				'format'   => 'json',
			));
			$response = $api->get('v1/notify', array(
				'type'		=> 'dbchange',
				'guid'		=> $this->options['guid'],
				'data'		=> $filename,
			));
			$path = $path . '_result';
			$result = json_decode($response->response);

			if ( $result->status == 200 || $attempts >= 3 )
				break;
			if ( !$rval )
				usleep(500000);
		} while ( true );
		if($result->status != 200) {
			// store some status info about our failure to ping 
		}
	}


	/**
	 * Replaces the default option values with those stored in the database
	 *
	 * @return  void
	 */
	protected function set_options() {
		if (is_multisite()) 
		{
			switch_to_blog(1);
			$options = get_option($this->option_name);
			restore_current_blog();
		} 
		else 
		{
			$options = get_option($this->option_name);
		}

		if (!is_array($options)) 
		{
			$options = array();
		}

		$this->options = array_merge($this->options_default, $options);
	}

	private function ensure_snapshotfolder_exists() {
		@mkdir($this->snapshotsfolder);
		chmod ($this->snapshotsfolder, 0755 );
	}

	/**
	 * Determine early if the current http request is for
	 * RCP to handle. If so, RCP will to it's job and exit
	 * out so that no other part of WP tries to deal with it.
	 * 
	 * @return void
	 */
	public function detect_api_request()
	{
		// Is a token set
		if( ! isset($_REQUEST['ttech_rcp_token'])) return FALSE;
		$token = $_REQUEST['ttech_rcp_token'];
		// Are there any actions?
		if( ! isset($_REQUEST['ttech_rcp_action'])) return FALSE;
		$action = $_REQUEST['ttech_rcp_action'];

		// Handle the API request, and then exit.
		$this->handle_api_request($action, $token);
		exit;
	}

	/**
	 * Check if a provided token is valid
	 * 
	 * @param  string $token Token passed via http
	 * 
	 * @return bool TRUE/FALSE
	 */
	private function validate_token($token)
	{
		$retval = FALSE;
		$apikey = $this->options['apikey'];
		$salt   = $_SESSION['salt'];
		if($token == sha1($apikey . $salt))
		{
			$retval =  TRUE;
		}
		return $retval;
	}

	/**
	 * Do the actual work of a RCP request
	 * 
	 * @param  string $action Command
	 * @param  string $token  Token
	 * 
	 * @return void Return is written as JSON on std out
	 */
	private function handle_api_request($action, $token)
	{
		$ret = new stdClass();
		$ret->mem_start = memory_get_usage(TRUE);
		if(!session_id()) {
		        session_start();
			if(!isset($_SESSION['salt']))
				$_SESSION['salt'] = sha1(microtime(TRUE) . mt_rand() . 'salt');
		}

		$valid_token = $this->validate_token($token);
		$non_auth_actions = array('init');

		if(!in_array($action, $non_auth_actions))
		{
			if(!$valid_token)
			{
				$ret->action = $action;
				$ret->status = 502;
				$ret->message = 'Invalid api key or out of squence';
				$action = 'invalid_token';

				if($this->options['apikey_status']) {
					$this->options['apikey_status'] = FALSE;
					update_option($this->option_name, $this->options);
				}
			} else {
				if(!$this->options['apikey_status']) {
					$this->options['apikey_status'] = TRUE;
					update_option($this->option_name, $this->options);
				}				
			}

		}

		$this->impersonate();
		switch($action)
		{
			case 'init':
			{
				$ret->action = $action;
				$ret->status  = 200;
				break;
			}
			case 'noop':
			{
				// used to check that the API key is OK. If we're here
				// it means it was.
				$ret->status = 200;
				break;
			}
			case 'get_version':
			{
				$ret->action = $action;
				$ret->status  = 200;
				$ret->version   = self::VERSION;
				break;
			}
			case 'get_site_info':
			{
				global $wp_version;
				$this->ensure_snapshotfolder_exists();				
				
				$ret->action       = $action;
				$ret->site_url     = get_site_url();
				$ret->home_url     = get_home_url();
				$ret->guid         = $this->options['guid'];
				$ret->admin_url    = get_admin_url();
				$ret->abspath      = ABSPATH;
				$ret->snapshoturl  = content_url() . '/' . self::SNAPSHOTS .'/';
				$ret->wp_version   = (string) $wp_version;
				$ret->rcp_version  = VERSION;
				$ret->capabilities = $this->utils->get_capabilities();
				$ret->settings     = $this->options;
				$ret->status       = 200;
				break;
			}


			case 'set_settings':
			{
				$this->set_settings();
				$ret->action = $action;
				$ret->status = 200;
				break;			
			}


			case 'get_plugins':
			{	
				require_once('remotecontrolpanel_plugins.php');
				$plugins = new remotecontrolpanel_plugins();
				$ret->action  = $action;

				$ret->status  = 200;
				$ret->plugins = $plugins->get_plugins();

				break;
			}
			case 'upgrade_plugin':
			{	
				require_once('remotecontrolpanel_plugins.php');
				$plugins = new remotecontrolpanel_plugins();

				$pluginname = $_REQUEST['plugin'];

				$ret->action  = $action;
				$ret->plugin = $pluginname;
				$retArr = $plugins->upgrade_plugin($pluginname);
				if($retArr['status'] == 200)
				{
					$ret->status  = 200;
					$ret->reactivate = $retArr['reactivate'];
				}
				else
				{
					$ret->status  = 500;
					$ret->error = $retArr['error'];
				}
				break;
			}
			case 'install_plugin':
			{
				require_once('remotecontrolpanel_plugins.php');
				$plugins = new remotecontrolpanel_plugins();
				$url = $_POST['url'];

				$ret->action  = $action;
				$retArr = $plugins->install_plugin($url);
				if($retArr['status'] == 200)
				{
					$ret->status  = 200;
					$ret->plugin_file = $retArr['plugin_file'];
				}
				else
				{
					$ret->status  = 500;
					$ret->error = $retArr['error'];
				}
				break;				

			}
			case 'activate_plugin':
			{
				require_once('remotecontrolpanel_plugins.php');
				$plugins = new remotecontrolpanel_plugins();
				$pluginname = $_REQUEST['plugin'];

				$ret->action  = $action;
				$ret->plugin = $pluginname;
				$retArr = $plugins->activate_plugin($pluginname);
				if($retArr['status'] == 200)
				{
					$ret->status  = 200;
				}
				else
				{
					$ret->status  = 500;
					$ret->error = $retArr['error'];
				}
				break;
			}
			case 'run_activate_plugin':
			{
				require_once('remotecontrolpanel_plugins.php');
				$plugins = new remotecontrolpanel_plugins();

				$pluginname = $_REQUEST['plugin'];

				$ret->action  = $action;
				$ret->plugin = $pluginname;
				$retArr = $plugins->run_activate_plugin($pluginname);
				if($retArr['status'] == 200)
				{
					$ret->status  = 200;
				}
				else
				{
					$ret->status  = 500;
					$ret->error = $retArr['error'];
				}
				break;
			}
			case 'deactivate_plugin':
			{
				require_once('remotecontrolpanel_plugins.php');
				$plugins = new remotecontrolpanel_plugins();

				$pluginname = $_REQUEST['plugin'];

				$ret->action  = $action;
				$ret->plugin = $pluginname;
				$retArr = $plugins->deactivate_plugin($pluginname);
				if($retArr['status'] == 200)
				{
					$ret->status  = 200;
				}
				else
				{
					$ret->status  = 500;
					$ret->error = $retArr['error'];
				}
				break;
			}			
			case 'delete_plugin':
			{
				require_once('remotecontrolpanel_plugins.php');
				$plugins = new remotecontrolpanel_plugins();

				$pluginname = $_REQUEST['plugin'];

				$ret->action  = $action;
				$ret->plugin  = $pluginname;
				$retArr = $plugins->delete_plugin($pluginname);
				if($retArr['status'] == 200)
				{
					$ret->status  = 200;
				}
				else
				{
					$ret->status  = 500;
					$ret->error = $retArr['error'];
				}
				break;
			}			


			case 'get_themes':
			{	
				require_once('remotecontrolpanel_themes.php');
				$themes = new remotecontrolpanel_themes();

				$ret->action  = $action;

				$ret->status  = 200;
				$ret->themes = $themes->get_themes();

				break;
			}
			case 'upgrade_theme':
			{	
				require_once('remotecontrolpanel_themes.php');
				$themes = new remotecontrolpanel_themes();

				$themename = $_REQUEST['theme'];

				$ret->action  = $action;
				$ret->theme = $themename;
				$retArr = $themes->upgrade_theme($themename);
				if($retArr['status'] == 200)
				{
					$ret->status  = 200;
				}
				else
				{
					$ret->status  = $retArr['status'];
					$ret->error = $retArr['error'];
				}
				break;
			}
			case 'get_core':
			{	
				require_once('remotecontrolpanel_core.php');
				$core = new remotecontrolpanel_core();

				$ret->action  = $action;

				$ret->status  = 200;
				$ret->core = $core->get_core();

				break;
			}
			case 'upgrade_core':
			{	
				require_once('remotecontrolpanel_core.php');
				$core = new remotecontrolpanel_core();

				$ret->action  = $action;
				$retArr = $core->upgrade_core($themename);
				if($retArr['status'] == 200)
				{
					$ret->status  = 200;
				}
				else
				{
					$ret->status  = 500;
					$ret->error = $retArr['error'];
				}				
				break;
			}
			case 'set_storage_credentials':
			{
				$this->set_storage_credentials();
				$ret->action = $action;
				$ret->status = 200;
			}
			case 'get_files_state':
			{
				$filename = md5(time());
				$this->ensure_snapshotfolder_exists();
				$path = $this->snapshotsfolder . "/" . $filename;
				$f = fopen($path, 'w');
				remotecontrolpanel_utils::rec_scandir(ABSPATH, $f);
				fclose($f);

				$ret->path = content_url() . '/' . self::SNAPSHOTS .'/' . $filename;
				$ret->ref = $filename;
				$ret->action = $action;
				$ret->status  = 200;
				break;				
			}
			case 'get_db_state':
			{
				$filename = md5(time());
				$this->ensure_snapshotfolder_exists();
				$path = $this->snapshotsfolder . "/" . $filename;
				$f = fopen($path, 'w');
				remotecontrolpanel_utils::get_db_state($f);
				fclose($f);

				$ret->path = content_url() . '/' . self::SNAPSHOTS .'/' . $filename;
				$ret->ref = $filename;
				$ret->action = $action;
				$ret->status  = 200;
				break;	
			}

			case 'clear_statefile':
			{
				$path = $this->snapshotsfolder . "/" . $_REQUEST['fileref'];
				if(file_exists($path)) unlink($path);
				$ret->fileref = $path;
				$ret->action = $action;
				$ret->status  = 200;
				break;
			}
			case 'get_file':
			{
				$fileref = $_REQUEST['fileref'];
				$content = file_get_contents(ABSPATH . $fileref);
				$ret->action = $action;                
				if($content)
				{
					$ret->data = $content;
					$ret->status  = 200;

				} else {
					$ret->status = 500;
				}
				break;
			}
			case 'prepare_files':
			{
				$postdata = file_get_contents("php://input");
				$files = json_decode($postdata);
				$filename = sha1($postdata . microtime(TRUE) . mt_rand());
				$path = $this->snapshotsfolder . "/$filename";

				$result = remotecontrolpanel_utils::compress_filelist($path, $files);
				$ret->action = $action;
				if($result)
				{
					$ret->ref = $filename;					
					$ret->path = content_url() . '/' . self::SNAPSHOTS .'/' . $filename;
					$ret->status  = 200;

				} else {
					$ret->status = 500;
				}
				break;
			}

			case 'prepare_tables':
			{
				$postdata = file_get_contents("php://input");
				$tables = json_decode($postdata);
				$filename = sha1(microtime(TRUE) . mt_rand());
				$path = $this->snapshotsfolder . "/$filename";
				$result = remotecontrolpanel_utils::compress_tables(
					$path, $tables, $this->snapshotsfolder);
				if($result)
				{
					$ret->ref = $filename;					
					$ret->path = content_url() . '/' . self::SNAPSHOTS .'/' . $filename;
					$ret->status  = 200;
				} else {
					$ret->status = 500;
				}
			}
			break;

			case 'invalid_token':
			{
				break;
			}
			default:
			{
				$ret->status  = 500;
				$ret->error = 'Invalid command';
			}
		}

		// generate the next dynamic salt

		$_SESSION['salt'] = sha1($_SESSION['salt'] . rand() . 'nextToken');
		$ret->nextsalt = $_SESSION['salt'];
		$ret->mem_end = memory_get_usage(TRUE);
		$ret->mem_peak = memory_get_peak_usage(TRUE);

		echo json_encode($ret);
		return;			
	}


	public function set_settings() {
		$raw_settings = file_get_contents('php://input');
		$new_settings = json_decode($raw_settings);
		$updated = FALSE;

		foreach($new_settings as $name => $value) {
			if(strlen($name) == 0) continue;
			if(!isset($this->options[$name])) {
				$this->options[$setting->name] = $value;
				$updated = TRUE;				
			}
			if($this->options[$name] != $value) {
				$this->options[$name] = $value;
				$updated = TRUE;
			}
		}

		if($updated) update_option($this->option_name, $this->options);
	}


	public function set_storage_credentials() {
		$cred = new stdClass();
		if(isset($this->options['credentials'])) $cred = $this->options['credentials'];

		$raw_cred = file_get_contents('php://input');
		$new_cred = json_decode($raw_cred);
		switch($new_cred->name) {
			case 'dropbox':
			{
				$cred->dropbox = new stdClass();
				$cred->dropbox->consumer_key    = $new_cred->consumer_key;
				$cred->dropbox->consumer_secret = $new_cred->consumer_secret;
				$cred->dropbox->oauth_key       = $new_cred->oauth_key;
				$cred->dropbox->oauth_secret    = $new_cred->oauth_secret;
				$this->options['cred'] = $cred;
				update_option($this->option_name, $this->options);
				break;
			}
		}
	}

	public function testnotify() {	
		require_once 'lib/restclient.php';

		$attempts = 0;
		do {
			$attempts++;			

			$api = new RestClient(array(
				'base_url' => $this->options['notifyurl'],
				'format'   => 'json',
			));
			$response = $api->get('v1/testnotify');
			$result = json_decode($response->response);

			if ( $result->status == 200 || $attempts >= 3 )
				break;
			if ( !$rval )
				usleep(500000);
		} while ( true );
		if($result->status != 200) {
			return FALSE;
		}
		return TRUE;
	}


	private function impersonate()
	{
		global $current_user;
		$current_user = new WP_User( 1, 'RemoteControlPanel' );
		$current_user->add_cap('activate_plugins');
		$current_user->add_cap('install_themes');
		$current_user->add_cap('install_plugins');
		$current_user->add_cap('update_plugins');
		$current_user->add_cap('update_themes');
		$current_user->add_cap('update_core');
		$current_user->add_cap('delete_plugins');
		$current_user->add_cap('delete_themes');
	}
}
