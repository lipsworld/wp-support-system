<?php
/**
 * @todo edd checks to see if new ticket or response to a ticket
 */

class Email_Support_System{

	private $_settings = array();

	private $conn = false;
	private $messages = array();
	private $setup = false;

	public function __construct(){
		add_action('init', array($this, 'init'));
        add_action('plugins_loaded', array($this, 'plugins_loaded'));
	}

	public function init(){

	}

	public function plugins_loaded(){

		$this->load_settings();
		
		// $this->check_emails(); // this is to test the check email functionality

		add_action( 'jc_support_system_cron', array($this, 'check_emails'));
		add_filter( 'cron_schedules', array($this, 'cron_add_schedules'));

		register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'unshedule_cron'));

        $this->schedule_cron();
	}

	public function activate(){
		$this->schedule_cron();
	}

	/**
     * Setup wp-cron to every 5 minutes
     * @return void
     */
    public function schedule_cron($rate = 'jc_5_minutes') {
        if ( !wp_next_scheduled( 'jc_support_system_cron' ) ) {
            wp_schedule_event( time(), $rate, 'jc_support_system_cron');
        }
    }

    /**
     * Add 5 Minutes cron schedule
     * @return array
     */
    public function cron_add_schedules( $schedules ) {
        $schedules['jc_5_minutes'] = array(
            'interval' => 60,
            'display' => __( 'Every 5 Minutes' )
        );
        return $schedules;
    }

    /**
     * Remove wp-cron schedule
     * @return void
     */
    public function unshedule_cron(){
        wp_clear_scheduled_hook('jc_support_system_cron');
    }

	public function check_emails(){

		if(!$this->setup)
			return false;

		$this->imap_connect();

		$this->imap_read_unread();

		foreach($this->messages as $msg){

			// test to see if registered user
			$user_id = $this->is_support_user($msg['from']);
			if(!$user_id){
				$this->send_register_email($msg);
				continue;
			}

			$structure = imap_fetchstructure($this->conn, $msg['id']);
			$body_encrypt = imap_fetchbody($this->conn, $msg['id'], '1', FT_PEEK);
			
			switch($structure->encoding){
				case 4:
				{
					$body = quoted_printable_decode($body_encrypt);
					break;
				}
				case 3:
				{
					$body = base64_decode($body_encrypt);
					break;
				}
				case 2:
				case 1:
				case 0:
				default:
				{
					$body = $body_encrypt;
				}
			}

			$ticket = $this->is_ticket_comment($msg);
			if($ticket){
				// add comment to ticket
				$create = insert_support_comment($ticket, $body, $user_id);

				if($create != false)
					$this->imap_mark_as_read($msg['id']);
			}else{
				// add new ticket
				$create = open_support_ticket($msg['subject'], $body, $user_id);

				if($create != false)
					$this->imap_mark_as_read($msg['id']);
			}
		}
		$this->imap_disconnect();
	}

	/**
	 * Send unregistered user an email asking them to register with the system before using the support email
	 * @return void
	 */
	private function send_register_email($msg){
		$send = wp_mail( $msg['from'], 'Re: '.$msg['subject'], 'This Support ticket could not be raised due to you not having an active account on the server');
		if($send != false)
			$this->imap_mark_as_read($msg['id']);
	}

	/**
	 * Check to email sender is a registered user
	 * @return boolean
	 */
	private function is_support_user($email = false){

		if(!$email)
			return false;

		$user = new WP_User_Query(array('search' => $email));
		if($user->total_users == 1){
			wp_reset_postdata();
			return $user->results[0]->ID;
		}

		return false;
	}

	/**
	 * Check to see if a response to an existing ticket
	 * @return boolean
	 */
	private function is_ticket_comment($msg = false){

		if(!$msg)
			return false;

		$subject = str_replace(array('Re: ', 'Re:'), '', $msg['subject']);
		$user_email = $msg['from'];

		$user_id = $this->is_support_user($user_email);
		global $wpdb;
		$query = "SELECT * FROM wp_posts WHERE post_type='SupportMessage' AND post_author = ".$user_id." AND post_parent = 0 AND post_title LIKE '".$subject."' AND post_status = 'private' LIMIT 1";
		$myrows = $wpdb->get_results( $query );

		// not working
		// $test = new WP_Query(array(
		// 	'post_type' => 'SupportMessage',
		// 	'author' => $user_id,
		// 	'post_parent' => 0,
		// 	// 'name' => $subject,
		// 	// 'meta_query' => array(
		// 	// 	array(
		// 	// 		'key' => '_answered',
		// 	// 		'value' => 0,
		// 	// 		'compare' => '=',
		// 	// 		'type' => 'INT'
		// 	// 	)
		// 	// )
		// ));

		// // print_r($test);

		if(count($myrows) == 1){
			return $myrows[0]->ID;
		}

		return false;
		
	}

	/**
	 * Load Email Settings
	 * @return void
	 */
	private function load_settings(){
		$host = get_option('email_imap_host');
		$port = get_option('email_imap_port');
		$user = get_option('email_username');
		$pass = get_option('email_password');

		if(empty($host) || empty($port) || empty($user) || empty($pass)){
			$this->setup = false;
			return false;
		}

		$this->_settings = array(
			'host' => $host,
			'port' => $port,
			'user' => $user,
			'pass' => $pass,
		);
		$this->setup = true;
	}

	/**
	 * Build imap connection hostname
	 * @return string
	 */
	private function imap_build_hostname(){
		return '{'.$this->_settings['host'].':'.$this->_settings['port'].'/imap/ssl}INBOX';
	}

	/**
	 * Loop through all unread messages
	 * @return void
	 */
	private function imap_read_unread(){
		$results = imap_search($this->conn, 'UNSEEN');
		$messages = array();

		foreach($results as $msg_id){
			$header = imap_header($this->conn, $msg_id);
			$messages[] = array(
				'id' => $msg_id,
				'subject' => $header->subject,
				'from' => $header->from[0]->mailbox."@".$header->from[0]->host
			); 
		}

		$this->messages = $messages;
	}

	private function imap_mark_as_read($id = null){

		if(intval($id) == 0)
			return false;

		imap_fetchbody($this->conn, $id, 1);
	}

	/**
	 * Connect to imap server
	 * @return void
	 */
	private function imap_connect(){
		$host = $this->imap_build_hostname();
		$this->conn = imap_open($host, $this->_settings['user'],$this->_settings['pass']);
	}

	/**
	 * Disconnect from imap server
	 * @return void
	 */
	private function imap_disconnect(){
		imap_close($this->conn);
	}

}

// if(is_admin())
$Email_Support_System = new Email_Support_System();

// add_action('plugins_loaded', array('Email_Support_System', 'init'));