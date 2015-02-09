<?php
/**
 * Gothick New reCAPTCHA
 *
 * @package phpBB Extension - New reCAPTCHA
 * @copyright (c) 2015 Matt Gibson Creative Ltd.
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 *
 */

namespace gothick\newrecaptcha\captcha;

class recaptcha extends \phpbb\captcha\plugins\captcha_abstract
{
	// https://www.google.com/recaptcha/api/siteverify?secret=your_secret&response=response_string&remoteip=user_ip_address
	var $recaptcha_verify_url = 'https://www.google.com/recaptcha/api/siteverify';

	var $g_recaptcha_response;

	// PHP really needs const with an access modifier.
	protected static $CONFIG_SITEKEY = 'gothick_newrecaptcha_sitekey';
	protected static $CONFIG_SECRETKEY = 'gothick_newrecaptcha_secretkey';

	/**
	 * @var \phpbb\config\config
	 */
	protected $config;

	/**
	 * @var \phpbb\db\driver\driver_interface
	 */
	protected $db;

	/**
	 * @var \phpbb\user
	 */
	protected $user;

	/**
	 * @var \phpbb\request\request
	 */
	protected $request;

	/**
	 * @var \phpbb\template\template
	 */
	protected $template;

	/**
	 * @var \phpbb\log\log_interface
	 */
	protected $log;

	/**
	 * @var scalar string
	 */
	protected $phpbb_root_path;

	/**
	 * @var scalar string
	 */
	protected $phpEx;


	/**
	* Constructor
	*
	* @param \phpbb\config\config $config
	* @param \phpbb\db\driver\driver_interface $db
	* @param \phpbb\user $user
	* @param \phpbb\request\request $request
	* @param \phpbb\template\template $tempate
	* @param \phpbb\log\log_interface $log
	* @param string $phpbb_root_path
	* @param string $phpEx
	*/
	public function __construct(
			\phpbb\config\config $config,
			\phpbb\db\driver\driver_interface $db,
			\phpbb\user $user,
			\phpbb\request\request $request,
			\phpbb\template\template $template,
			\phpbb\log\log_interface $log,
			$phpbb_root_path,
			$phpEx
	)
	{
		// DI
		$this->config = $config;
		$this->db = $db;
		$this->user = $user;
		$this->request = $request;
		$this->template = $template;
		$this->log = $log;
		$this->phpbb_root_path = $phpbb_root_path;
		$this->phpEx = $phpEx;
	}

	function init($type)
	{
		$this->user->add_lang_ext('gothick/newrecaptcha', 'captcha_newrecaptcha');
		parent::init($type);
		$this->g_recaptcha_response = $this->request->variable('g-recaptcha-response', '');
	}

	public function is_available()
	{
		// We need to load the language files here for the ACP page, as it doesn't call init. This is
		// where the "old" reCAPTCHA plug in core does it, anyway...
		$this->user->add_lang_ext('gothick/newrecaptcha', 'captcha_newrecaptcha');

		return (!empty($this->config[self::$CONFIG_SITEKEY]) && !empty($this->config[self::$CONFIG_SECRETKEY]));
	}

	/**
	*  API function
	*/
	function has_config()
	{
		return true;
	}

	static public function get_name()
	{
		return 'GOTHICK_NEWRECAPTCHA';
	}

	/**
	* This function is implemented because required by the upper class, but is never used for reCaptcha.
	*/
	function get_generator_class()
	{
		throw new \Exception('No generator class given.');
	}

	function acp_page($id, &$module)
	{
		$captcha_vars = array(
			self::$CONFIG_SITEKEY => 'NEWRECAPTCHA_SITEKEY',
			self::$CONFIG_SECRETKEY => 'NEWRECAPTCHA_SECRETKEY',
		);

		$module->tpl_name = '@gothick_newrecaptcha/captcha_newrecaptcha_acp';
		$module->page_title = 'ACP_VC_SETTINGS';
		$form_key = 'acp_captcha';
		add_form_key($form_key);

		if ($this->request->is_set_post('submit') && check_form_key($form_key))
		{
			$captcha_vars = array_keys($captcha_vars);
			foreach ($captcha_vars as $captcha_var)
			{
				$value = $this->request->variable($captcha_var, '');
				if ($value)
				{
					$this->config->set($captcha_var, $value);
				}
			}

			$this->log->add('admin', $this->user->data['user_id'], $this->user->ip, 'LOG_CONFIG_VISUAL');
			trigger_error($this->user->lang['CONFIG_UPDATED'] . adm_back_link($module->u_action));
		}
		else if ($this->request->is_set_post('submit'))
		{
			trigger_error($this->user->lang['FORM_INVALID'] . adm_back_link($module->u_action));
		}

		foreach ($captcha_vars as $captcha_var => $template_var)
		{
			$var = ($this->request->is_set_post($captcha_var)) ? $this->request->variable($captcha_var, '') : ((isset($this->config[$captcha_var])) ? $this->config[$captcha_var] : '');
			$this->template->assign_var($template_var, $var);
		}

		$this->template->assign_vars(array(
			'CAPTCHA_PREVIEW'	=> $this->get_demo_template($id),
			'CAPTCHA_NAME'		=> $this->get_service_name(),
			'U_ACTION'			=> $module->u_action,
		));
	}

	// not needed
	function execute_demo()
	{
	}

	// not needed
	function execute()
	{
	}

	function get_template()
	{
		if ($this->is_solved())
		{
			return false;
		}
		else
		{
			$contact_link = phpbb_get_board_contact_link($this->config, $this->phpbb_root_path, $this->phpEx);
			$explain = $this->user->lang(($this->type != CONFIRM_POST) ? 'GOTHICK_RECAPTCHA_CONFIRM_EXPLAIN' : 'GOTHICK_RECAPTCHA_POST_CONFIRM_EXPLAIN', '<a href="' . $contact_link . '">', '</a>');

			$recaptcha_lang = $this->user->lang('GOTHICK_NEWRECAPTCHA_LANG');
			if ($recaptcha_lang == 'GOTHICK_NEWRECAPTCHA_LANG')
			{
				// If we don't have a language code set in our language file, then we don't
				// pass anything; reCAPTCHA will attempt to guess the user's language.
				$recaptcha_lang = '';
			}

			// TODO: Do we need all these set up?
			$this->template->assign_vars(array(
				'NEWRECAPTCHA_SITEKEY'			=> isset($this->config[self::$CONFIG_SITEKEY]) ? $this->config[self::$CONFIG_SITEKEY] : '',
				'RECAPTCHA_ERRORGET'		=> '',
				'S_RECAPTCHA_AVAILABLE'		=> self::is_available(),
				'S_CONFIRM_CODE'			=> true,
				'S_TYPE'					=> $this->type,
				'L_CONFIRM_EXPLAIN'			=> $explain,
				'L_GOTHICK_NEWRECAPTCHA_LANG' => $recaptcha_lang // If we don't pass it explicitly, INCLUDEJS won't use it.
			));

			return '@gothick_newrecaptcha/captcha_newrecaptcha.html';
		}
	}

	function get_demo_template($id)
	{
		return $this->get_template();
	}

	function get_hidden_fields()
	{
		$hidden_fields = array();

		// this is required for posting.php - otherwise we would forget about the captcha being already solved
		if ($this->solved)
		{
			$hidden_fields['confirm_code'] = $this->code;
		}
		$hidden_fields['confirm_id'] = $this->confirm_id;
		return $hidden_fields;
	}

	function uninstall()
	{
		$this->garbage_collect(0);
	}

	function install()
	{
		return;
	}

	function validate()
	{
		if (!parent::validate())
		{
			return false;
		}
		else
		{
			//TODO: Exception handling
			$recaptcha = new \gothick\newrecaptcha\google\ReCaptcha($this->config[self::$CONFIG_SECRETKEY]);
			$response = $recaptcha->verifyResponse($this->user->ip, $this->request->variable('g-recaptcha-response', ''));
			if ($response->success)
			{
				$this->solved = true;
				return false;
			}
			else
			{
				// TODO: Can we pass something less general back from the response?
				return $this->user->lang['GOTHICK_NEWRECAPTCHA_INCORRECT'];
			}
		}
	}
}