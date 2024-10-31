<?php
/** 
 * Administrative classes and functions for the RemoteControlPanel
 * plugin. 
 *
 * @package   remotecontrolpanel
 * @link      http://www.torgesta.com
 * @license   GPLv2
 * @author    erik@torgesta.com
 * @copyright Erik Torsner <erik@torgesta.com>
 *
 */

/**
 * The user interface and activation/deactivation methods for administering
 * the remotecontrolpanel
 *
 * @package   remotecontrolpanel
 * @link      http://www.torgesta.com
 * @license   GPLv2
 * @author    erik@torgesta.com
 * @copyright Erik Torsner <erik@torgesta.com>
 *
 */
class remotecontrolpanel_admin extends remotecontrolpanel {
	/**
	 * The WP privilege level required to use the admin interface
	 * @var string
	 */
	protected $capability_required;

	/**
	 * Metadata and labels for each element of the plugin's options
	 * @var array
	 */
	protected $fields;

	/**
	 * URI for the forms' action attributes
	 * @var string
	 */
	protected $form_action;

	/**
	 * Name of the page holding the options
	 * @var string
	 */
	protected $page_options;

	/**
	 * Metadata and labels for each settings page section
	 * @var array
	 */
	protected $settings;

	/**
	 * Title for the plugin's settings page
	 * @var string
	 */
	protected $text_settings;


	/**
	 * Sets the object's properties and options
	 *
	 * @return void
	 *
	 * @uses oop_plugin_template_solution::initialize()  to set the object's
	 *	     properties
	 * @uses oop_plugin_template_solution_admin::set_sections()  to populate the
	 *       $sections property
	 * @uses oop_plugin_template_solution_admin::set_fields()  to populate the
	 *       $fields property
	 */
	public function __construct() {
		$this->initialize();   // resuses parents initialize();
		$this->set_sections();
		$this->set_fields();

		if (is_multisite()) 
		{
			$this->capability_required = 'manage_network_options';
			$this->form_action = '../options.php';
			$this->page_options = 'settings.php';
		} 
		else 
		{
			$this->capability_required = 'manage_options';
			$this->form_action = 'options.php';
			$this->page_options = 'options-general.php';
		}
	}

	/**
	 * Called when plugin is activated
	 * @return void
	 */
	public function activate() 
	{
	}

	/**
	 * Called when plugin is deactivated
	 */
	public function deactivate() 
	{
	}

	/**
	 * A filter to add a "Settings" link in this plugin's description
	 *
	 * @param array $links  the links generated thus far
	 * @return array
	 */
	public function plugin_action_links($links) 
	{
		// Add html snippets to the $links[] array
		// and return it.	
		return $links;
	}	

	/**
	 * Sets the metadata and labels for each settings page section
	 *
	 * @return void
	 * @uses oop_plugin_template_solution_admin::$sections  to hold the data
	 */
	protected function set_sections() {
		$this->sections = array(
			'status' => array(
				'title' => __("Status", self::ID),
				'callback' => 'section_status',
			),			
			'apikey' => array(
				'title' => __("API Key", self::ID),
				'callback' => 'section_apikey',
			),
			'busettings'	=> array(
				'title' => __("Backup settings", self::ID),
				'callback' => 'section_busettings',				
			)
		);
	}

	/**
	 * Sets the metadata and labels for each element of the plugin's
	 * options
	 *
	 * @return void
	 * @uses remotecontrolpanel::$fields  to hold the data
	 */
	protected function set_fields() {
		$this->fields = array(
				'apikey' => array(
					'section' => 'apikey',
					'label' => __("API Key", self::ID),
					'text' => __("", self::ID),
					'type' => 'apikey',
				),
				'ping' => array(
					'section' => 'busettings2',
					'label'   => __("Disable ping", self::ID),
					'text'    => __("By default, this plugin will ping our service when changes occur
									<br>so that they can be backed up immediately. Optionally, you may", self::ID),
					'bool0'   => __("No - ping if possible", self::ID),
					'bool1'   => __("Yes - diable automatic ping", self::ID),
					'type'    => 'bool',					
				)
		);
	}


	/**
	 * Declares a menu item and callback for this plugin's settings page
	 *
	 * NOTE: This method is automatically called by WordPress when
	 * any admin page is rendered
	 */
	public function admin_menu() 
	{
		add_submenu_page(
			$this->page_options, $this->text_settings,
			self::NAME, $this->capability_required,
			self::ID, array(&$this, 'page_settings')
		);
	}

	/**
	 * Declares the callbacks for rendering and validating this plugin's
	 * settings sections and fields
	 *
	 * NOTE: This method is automatically called by WordPress when
	 * any admin page is rendered
	 */
	public function admin_init() 
	{
		register_setting(
			$this->option_name,
			$this->option_name,
			array(&$this, 'validate')
		);

		add_action( 'admin_enqueue_scripts', array($this, 'enqueue_scripts' ));
		add_action('wp_ajax_generate_new_apikey', array($this,'ajax_generate_new_apikey'));

		// Dynamically declares each section using the info in $sections.
		foreach ($this->sections as $id => $section) {
			add_settings_section(
				self::ID . '-' . $id, $section['title'],
				array(&$this, $section['callback']),
				self::ID
			);
		}

		// Dynamically declares each field using the info in $fields.
		foreach ($this->fields as $id => $field) {
			add_settings_field(
				$id, $field['label'],
				array(&$this, $id),
				self::ID,
				self::ID . '-' . $field['section']
			);
		}
	}

	public function enqueue_scripts() {
		$d = plugin_dir_url( __FILE__ );
		wp_enqueue_script( 'rcp', $d . '/rcp.js' );
	}

	/**
	 * The callback for rendering the settings page
	 * @return void
	 */
	public function page_settings() 
	{
		if (is_multisite()) 
		{
			include_once ABSPATH . 'wp-admin/options-head.php';
		}

		echo '<div id="icon-options-general" class="icon32"><br></div>';
		echo '<h2>' . 'Remote Control Panel settings' . '</h2>';
		echo '<form action="' . $this->form_action . '" method="post">' . "\n";
		echo '<div class="wrap">';
		settings_fields($this->option_name);
		do_settings_sections(self::ID);
		submit_button();
		echo '</div>';
		echo '</form>';
	}

	/**
	 * The callback for rendering the sections that don't have descriptions
	 * @return void
	 */
	public function section_status() 
	{

		if(!$this->options['notifyconn']) {
			$this->options['notifyconn'] = $this->testnotify();
			update_option($this->option_name, $this->options);
		}

		if($this->options['guid'] != sha1(get_site_url())) {
			echo '<div class="error"><p>';
			echo 'Your site URL have changed since you first set up this plugin. Did you change your main URL? Is this a copy of the ';
			echo 'original site? If you want backups to continue to work as expected, you need to make sure that Remote Control Panel ';
			echo 'knows about new location.';
			echo '</p><p>'; 
			echo '<button name="new_apikey" id="new_apikey" class="button">';
			echo 'Reset';
			echo '</button>';			
			echo '</p></div>';			
		}

		if($this->options['apikey_status']) { 
			echo '<div class="good"><p>';
			echo '<img src="images/yes.png"> </img>';
			echo 'Your site is connected to remotecontrolpanel.net';
			echo 'Log in at <a href="https://www.remotecontrolpanel.net">https://www.remotecontrolpanel.net</a> ';
			echo 'to check status, change settings etc.' ;
			echo '</p></div>';
		} else {
			echo '<div class="error"><p>';
			echo 'Your site is not yet connected to remotecontrolpanel.net';
			echo 'To get started, sign up and log in at <a href="https://www.remotecontrolpanel.net">https://www.remotecontrolpanel.net</a> ';
			echo 'and add this site to your account.' ;
			echo '</p></div>';
		}
		if($this->options['notifyconn']) { 
			echo '<div class="good"><p>';
			echo '<img src="images/yes.png"> </img>';
			echo 'Your site can send notifications to our servers, changes on your site will be sent to backup as they happen';
			echo '</p></div>';			
		} else {
			echo '<div class="error"><p>';
			echo "Your site seem to have problems sending notifications to us at {$this->options['notifyurl']}. ";
			echo 'Most likely this is because your web server doesn\'t allow ';
			echo 'outgoing connections, Your site will be backed up continously, but changes may take up to 10 minutes before '; 
			echo 'they are backed up';
			echo '</p></div>';
		}
		

	}	


	/**
	 * The callback for rendering the sections that don't have descriptions
	 * @return void
	 */
	public function section_apikey() 
	{
		echo '<p>';
		echo "The API key displayed below is a security token that allows the Remote Control Panel servers ";
		echo "access this Wordpress installation. Make sure to keep it safe ";
		echo "If you suspect that your API key has fallen into the wrong hands, use the button below ";
		echo "to generate a new key and save your changes. <br><b>Please note</b> that when you regenreate a new API key, you also ";
		echo "need to update the API key settings in Remote Control Panel.<br>";		
		echo '</p>';		
	}

	/**
	 * The callback for rendering the sections that don't have descriptions
	 * @return void
	 */
	public function section_busettings() 
	{
		echo '<p>';
		echo  "All backup settings are controlled via your Remote Control Panel account. ";
		echo  "Please go to: <a href=\"https://www.remotecontrolpanel.net\">https://www.remotecontrolpanel.net</a><br>" ;
		echo  "to change your settings.";
		echo '</p>';		
	}


	/**
	 * The callback for rendering the fields
	 * @return void
	 *
	 * @uses oop_plugin_template_solution_admin::input_int()  for rendering
	 *       text input boxes for numbers
	 * @uses oop_plugin_template_solution_admin::input_radio()  for rendering
	 *       radio buttons
	 * @uses oop_plugin_template_solution_admin::input_string()  for rendering
	 *       text input boxes for strings
	 */
	public function __call($name, $params) {
		if (empty($this->fields[$name]['type'])) {
			return;
		}
		switch ($this->fields[$name]['type']) {
			case 'bool':
				$this->input_radio($name);
				break;
			case 'int':
				$this->input_int($name);
				break;
			case 'string':
				$this->input_string($name);
				break;
			case 'apikey':
				$this->input_apikey($name);
				break;				
		}
	}

	/**
	 * Renders the radio button inputs
	 * @return void
	 */
	protected function input_radio($name) {
		echo $this->fields[$name]['text'] . '<br/>';
		echo '<input type="radio" value="0" name="'
			. $this->option_name
			. '[' . $name . ']"'
			. ($this->options[$name] ? '' : ' checked="checked"') . ' /> ';
		echo $this->fields[$name]['bool0'];
		echo '<br/>';
		echo '<input type="radio" value="1" name="'
			. $this->option_name
			. '[' . $name . ']"'
			. ($this->options[$name] ? ' checked="checked"' : '') . ' /> ';
		echo $this->fields[$name]['bool1'];
	}

	/**
	 * Renders the text input boxes for editing integers
	 * @return void
	 */
	protected function input_int($name) {
		echo '<input type="text" size="3" name="'
			. $this->option_name
			. '[' . $name . ']"'
			. ' value="' . $this->options[$name] . '" /> ';
		echo $this->fields[$name]['text']
				. ' ' . __('Default:', self::ID) . ' '
				. $this->options_default[$name] . '.';
	}

	/**
	 * Renders the text input boxes for editing strings
	 * @return void
	 */
	protected function input_string($name) {
		echo '<input type="text" size="30" name="'
			. $this->option_name
			. '[' . $name . ']"'
			. ' value="' . $this->options[$name] . '" /> ';
		echo '<br />';
	}

	/**
	 * Renders the text input boxes for editing strings
	 * @return void
	 */
	protected function input_apikey($name) {
		echo '<input type="text" size="60" name="'
			. $this->option_name. '[' . $name . ']"'
			. ' id="apikey"'
			. ' value="' . $this->options[$name] . '" readonly /> ';
		echo '<br />&nbsp;<br >';
		echo '<button name="new_apikey" id="new_apikey" class="button">';
		echo 'Re-generate';
		echo '</button>';
		echo '<br />';
	}

	public function ajax_generate_new_apikey() {
		$ret = new stdClass();
		$ret->key = sha1(microtime(TRUE) . $_SERVER['REMOTE_ADDR'] . mt_rand() );
		echo json_encode($ret);
		die();
	}


	/**
	 * Validates the user input
	 *
	 * NOTE: WordPress saves the data even if this method says there are
	 * errors.  So this method sets any inappropriate data to the default
	 * values.
	 *
	 * @param array $in  the input submitted by the form
	 * @return array  the sanitized data to be saved
	 */
	public function validate($in) {
		return $in;
	}
}