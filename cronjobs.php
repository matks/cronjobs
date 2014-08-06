<?php
/**
* 2007-2014 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author    PrestaShop SA <contact@prestashop.com>
*  @copyright 2007-2014 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

if (!defined('_PS_VERSION_'))
	exit;

class CronJobs extends PaymentModule
{
	protected $_errors;
	protected $_successes;
	protected $_warnings;

	public $webservice_url = 'http://webcron.prestashop.com/crons';

	public function __construct()
	{
		$this->name = 'cronjobs';
		$this->tab = 'administration';
		$this->version = '1.0.5';
		$this->module_key = '';

		$this->currencies = true;
		$this->currencies_mode = 'checkbox';
		$this->author = 'PrestaShop';
		$this->need_instance = true;

		$this->bootstrap = true;
		$this->display = 'view';

		parent::__construct();

		$this->displayName = $this->l('Cron jobs');
		$this->description = $this->l('Manage all your automated web tasks from a single interface.');

		$this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);

		if (function_exists('curl_init') == false)
			$this->warning = $this->l('To be able to use this module, please activate cURL (PHP extension).');
	}

	public function install()
	{
		Configuration::updateValue('CRONJOBS_WEBSERVICE_ID', 0);
		Configuration::updateValue('CRONJOBS_MODE', 'webservice');
		Configuration::updateValue('CRONJOBS_EXECUTION_TOKEN', Tools::encrypt(_PS_ADMIN_DIR_.time()), false, 0, 0);

		return $this->installDb() && $this->installTab() && parent::install() &&
			$this->registerHook('actionModuleRegisterHookAfter') && $this->registerHook('actionModuleUnRegisterHookAfter') &&
			$this->registerHook('backOfficeHeader') &&
			$this->toggleWebservice(true);
	}

	public function uninstall()
	{
		Configuration::deleteByName('CRONJOBS_MODE');

		return $this->uninstallDb() && $this->uninstallTab() && parent::uninstall();
	}

	public function installDb()
	{
		return Db::getInstance()->execute('
			CREATE TABLE IF NOT EXISTS '._DB_PREFIX_.$this->name.' (
			`id_cronjob` INTEGER(10) NOT NULL AUTO_INCREMENT,
			`id_module` INTEGER(10) DEFAULT NULL,
			`description` TEXT DEFAULT NULL,
			`task` TEXT DEFAULT NULL,
			`hour` INTEGER DEFAULT \'-1\',
			`day` INTEGER DEFAULT \'-1\',
			`month` INTEGER DEFAULT \'-1\',
			`day_of_week` INTEGER DEFAULT \'-1\',
			`updated_at` DATETIME DEFAULT NULL,
			`active` BOOLEAN DEFAULT FALSE,
			`id_shop` INTEGER DEFAULT \'0\',
			`id_shop_group` INTEGER DEFAULT \'0\',
			PRIMARY KEY(`id_cronjob`),
			INDEX (`id_module`))
			ENGINE='._MYSQL_ENGINE_.' default CHARSET=utf8'
		);
	}

	public function uninstallDb()
	{
		return Db::getInstance()->execute('DROP TABLE IF EXISTS '._DB_PREFIX_.$this->name);
	}

	public function installTab()
	{
		$tab = new Tab();
		$tab->active = 1;
		$tab->name = array();
		$tab->class_name = 'AdminCronJobs';

		foreach (Language::getLanguages(true) as $lang)
			$tab->name[$lang['id_lang']] = 'Cron Jobs';

		$tab->id_parent = -1;
		$tab->module = $this->name;

		return $tab->add();
	}

	public function uninstallTab()
	{
		$id_tab = (int)Tab::getIdFromClassName('AdminCronJobs');

		if ($id_tab)
		{
			$tab = new Tab($id_tab);
			return $tab->delete();
		}

		return false;
	}
	
	public function hookActionModuleRegisterHookAfter($params)
	{
		$hook_name = $params['hook_name'];

		if (strcmp($hook_name, 'actionCronJob') === 0)
		{
			$module = $params['object'];
			$this->registerModuleHook($module->id);
		}
	}
	
	public function hookActionModuleUnRegisterHookAfter($params)
	{
		$hook_name = $params['hook_name'];

		if (strcmp($hook_name, 'actionCronJob') === 0)
		{
			$module = $params['object'];
			$this->unregisterModuleHook($module->id);
		}
	}

	public function hookBackOfficeHeader()
	{
		$this->context->controller->addCSS($this->_path.'css/configure.css');
	}

	public function getContent()
	{
		$output = null;

		$this->checkLocalEnvironment();

		if (Tools::isSubmit('submitCronJobs'))
			$this->postProcessConfiguration();
		elseif (Tools::isSubmit('submitNewCronJob'))
			$submit_cron = $this->postProcessNewJob();
		elseif (Tools::isSubmit('submitUpdateCronJob'))
			$submit_cron = $this->postProcessUpdateJob();

		$this->context->smarty->assign(array(
			'module_dir' => $this->_path,
			'module_local_dir' => $this->local_path,
		));

		$this->context->smarty->assign('form_errors', $this->_errors);
		$this->context->smarty->assign('form_infos', $this->_warnings);
		$this->context->smarty->assign('form_successes', $this->_successes);

		if ((Tools::isSubmit('submitNewCronJob') || Tools::isSubmit('newcronjobs') || Tools::isSubmit('updatecronjobs')) &&
			((isset($submit_cron) == false) || ($submit_cron === false)))
		{
			$back_url = $this->context->link->getAdminLink('AdminModules', false)
				.'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name
				.'&token='.Tools::getAdminTokenLite('AdminModules');
		}

		if (Tools::isSubmit('newcronjobs') || ((isset($submit_cron) == true) && ($submit_cron === false)))
			$output = $output.$this->renderForm($this->getJobForm(), $this->getNewJobFormValues(), 'submitNewCronJob', true, $back_url);
		elseif (Tools::isSubmit('updatecronjobs') && Tools::isSubmit('id_cronjob'))
		{
			$form_structure = $this->getJobForm('Update cron job', true);
			$form = $this->renderForm($form_structure, $this->getUpdateJobFormValues(), 'submitUpdateCronJob', true, $back_url, true);
			$output = $output.$form;
		}
		elseif (Tools::isSubmit('deletecronjobs') && Tools::isSubmit('id_cronjob'))
			$this->postProcessDeleteCronJob((int)Tools::getValue('id_cronjob'));
		elseif (Tools::isSubmit('statuscronjobs'))
			$this->postProcessUpdateJobStatus();
		elseif (defined('_PS_HOST_MODE_') == false)
		{
			$output = $output.$this->context->smarty->fetch($this->local_path.'views/templates/admin/configure.tpl');
			$output = $output.$this->renderForm($this->getForm(), $this->getFormValues(), 'submitCronJobs');
		}

		return $output.$this->renderTasksList();
	}

	public function sendCallback()
	{
		ignore_user_abort(true);
		set_time_limit(0);

		ob_start();
		echo $this->name.'_prestashop';
		header('Connection: close');
		header('Content-Length: '.ob_get_length());
		ob_end_flush();
		ob_flush();
		flush();
	}
	
	public static function isActive($id_module)
	{
		$query = 'SELECT `active` FROM '._DB_PREFIX_.'cronjobs WHERE `id_module` = \''.(int)$id_module.'\'';
		return (bool)Db::getInstance()->getValue($query);
	}

	protected function checkLocalEnvironment()
	{
		$local_ips = array('127.0.0.1', '::1');

		if (in_array(Tools::getRemoteAddr(), $local_ips) == true)
			$this->setWarningMessage('You are using the Cronjobs module on a local installation:
			you will not be able to use the Basic mode or reliably call remote cron tasks in your current environment.
			To use this module at its best, you should switch to an online installation.');
	}

	protected function renderForm($form, $form_values, $action, $cancel = false, $back_url = false, $update = false)
	{
		$helper = new HelperForm();

		$helper->show_toolbar = false;
		$helper->module = $this;
		$helper->default_form_language = $this->context->language->id;
		$helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

		$helper->identifier = $this->identifier;
		$helper->submit_action = $action;

		if ($update == true)
		{
			$helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
				.'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name
				.'&id_cronjob='.(int)Tools::getValue('id_cronjob');
		}
		else
		{
			$helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
				.'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
		}

		$helper->token = Tools::getAdminTokenLite('AdminModules');

		$helper->tpl_vars = array(
			'fields_value' => $form_values,
			'id_language' => $this->context->language->id,
			'languages' => $this->context->controller->getLanguages(),
			'back_url' => $back_url,
			'show_cancel_button' => $cancel,
		);

		return $helper->generateForm($form);
	}

	protected function renderTasksList()
	{
		$helper = new HelperList();

		$helper->title = $this->l('Cron tasks');
		$helper->table = $this->name;
		$helper->no_link = true;
		$helper->shopLinkType = '';
		$helper->identifier = 'id_cronjob';
		$helper->actions = array('edit', 'delete');

		$values = $this->getTasksListValues();
		$helper->listTotal = count($values);

		$helper->tpl_vars = array(
			'show_filters' => false,
		);

		$helper->toolbar_btn['new'] = array(
			'href' => $this->context->link->getAdminLink('AdminModules', false)
			.'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name
			.'&newcronjobs=1&token='.Tools::getAdminTokenLite('AdminModules'),
			'desc' => $this->l('Add new task')
		);

		$helper->token = Tools::getAdminTokenLite('AdminModules');
		$helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
			.'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;

		return $helper->generateList($values, $this->getTasksList());
	}

	protected function postProcessConfiguration()
	{
		if (Tools::isSubmit('cron_mode') == true)
		{
			$cron_mode = Tools::getValue('cron_mode');

			if (in_array($cron_mode, array('advanced', 'webservice')) == true)
				return $this->toggleWebservice();
		}
	}

	protected function postProcessNewJob()
	{
		if ($this->isNewJobValid() == true)
		{
			$description = Tools::getValue('description');
			$task = urlencode(Tools::getValue('task'));
			$hour = (int)Tools::getValue('hour');
			$day = (int)Tools::getValue('day');
			$month = (int)Tools::getValue('month');
			$day_of_week = (int)Tools::getValue('day_of_week');

			$result = Db::getInstance()->getRow('SELECT id_cronjob FROM '._DB_PREFIX_.$this->name.'
				WHERE `task` = \''.$task.'\' AND `hour` = \''.$hour.'\' AND `day` = \''.$day.'\'
				AND `month` = \''.$month.'\' AND `day_of_week` = \''.$day_of_week.'\'');

			if ($result == false)
			{
				$id_shop = (int)$this->context->shop->id;
				$id_shop_group = (int)$this->context->shop->id_shop_group;
				
				$query = 'INSERT INTO '._DB_PREFIX_.$this->name.'
					(`description`, `task`, `hour`, `day`, `month`, `day_of_week`, `updated_at`, `active`, `id_shop`, `id_shop_group`)
					VALUES (\''.$description.'\', \''.$task.'\', \''.$hour.'\', \''.$day.'\', \''.$month.'\', \''.$day_of_week.'\', NULL, TRUE, '.$id_shop.', '.$id_shop_group.')';

				if (($result = Db::getInstance()->execute($query)) != false)
					$this->setSuccessMessage('The task has been successfully added.');
				else
					$this->setErrorMessage('An error happened: the task could not be added.');

				return $result;
			}

			$this->setErrorMessage('This cron task already exists.');
		}

		return false;
	}

	protected function postProcessUpdateJob()
	{
		if (Tools::isSubmit('id_cronjob') == false)
			return false;

		$description = Tools::getValue('description');
		$task = urlencode(Tools::getValue('task'));
		$hour = (int)Tools::getValue('hour');
		$day = (int)Tools::getValue('day');
		$month = (int)Tools::getValue('month');
		$day_of_week = (int)Tools::getValue('day_of_week');

		$id_cronjob = (int)Tools::getValue('id_cronjob');
				
		$id_shop = (int)$this->context->shop->id;
		$id_shop_group = (int)$this->context->shop->id_shop_group;

		$query = 'UPDATE '._DB_PREFIX_.$this->name.'
			SET `description` = \''.$description.'\',
				`task` = \''.$task.'\',
				`hour` = \''.$hour.'\',
				`day` = \''.$day.'\',
				`month` = \''.$month.'\',
				`day_of_week` = \''.$day_of_week.'\'
			WHERE `id_cronjob` = \''.(int)$id_cronjob.'\'';

		if (($result = Db::getInstance()->execute($query)) != false)
			$this->setSuccessMessage('The task has been updated.');
		else
			$this->setErrorMessage('The task has not been updated');

		return $result;
	}

	protected function postProcessUpdateJobStatus()
	{
		if (Tools::isSubmit('id_cronjob') == false)
			return false;

		$id_cronjob = (int)Tools::getValue('id_cronjob');

		$id_shop = (int)$this->context->shop->id;
		$id_shop_group = (int)$this->context->shop->id_shop_group;

		Db::getInstance()->execute('UPDATE '._DB_PREFIX_.$this->name.' SET `active` = IF (`active`, 0, 1) WHERE `id_cronjob` = \''.(int)$id_cronjob.'\'');

		Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules', false)
			.'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name
			.'&token='.Tools::getAdminTokenLite('AdminModules'));
	}

	protected function isNewJobValid()
	{
		if ((Tools::isSubmit('description') == true) &&
			(Tools::isSubmit('task') == true) &&
			(Tools::isSubmit('hour') == true) &&
			(Tools::isSubmit('day') == true) &&
			(Tools::isSubmit('month') == true) &&
			(Tools::isSubmit('day_of_week') == true))
		{
			$task = urlencode(Tools::getValue('task'));

			if (strpos($task, urlencode(Tools::getShopDomain(true, true).__PS_BASE_URI__)) !== 0)
				return $this->setErrorMessage('The target link you entered is not valid. It should be an absolute URL, on the same domain as your shop.');

			$success = true;
			$hour = Tools::getValue('hour');
			$day = Tools::getValue('day');
			$month = Tools::getValue('month');
			$day_of_week = Tools::getValue('day_of_week');

			if ((($hour >= -1) && ($hour < 24)) == false)
				$success &= $this->setErrorMessage('The value you chose for the hour is not valid. It should be between 00:00 and 23:59.');
			if ((($day >= -1) && ($day <= 31)) == false)
				$success &= $this->setErrorMessage('The value you chose for the day is not valid.');
			if ((($month >= -1) && ($month <= 31)) == false)
				$success &= $this->setErrorMessage('The value you chose for the month is not valid.');
			if ((($day_of_week >= -1) && ($day_of_week < 7)) == false)
				$success &= $this->setErrorMessage('The value you chose for the day of the week is not valid.');

			return $success;
		}

		return false;
	}

	protected function setErrorMessage($message)
	{
		$this->_errors[] = $this->l($message);
		return false;
	}

	protected function setSuccessMessage($message)
	{
		$this->_successes[] = $this->l($message);
		return true;
	}

	protected function setWarningMessage($message)
	{
		$this->_warnings[] = $this->l($message);
		return false;
	}

	protected function toggleWebservice($force_webservice = false)
	{
		if ($force_webservice !== false)
			$cron_mode = 'webservice';
		else
			$cron_mode = Tools::getValue('cron_mode', 'webservice');

		Configuration::updateValue('CRONJOBS_MODE', $cron_mode);

		$admin_folder = str_replace(_PS_ROOT_DIR_.'/', null, _PS_ADMIN_DIR_);
		$path = Tools::getShopDomainSsl(true, true).__PS_BASE_URI__.$admin_folder;
		$cron_url = $path.'/'.$this->context->link->getAdminLink('AdminCronJobs', false);

		$webservice_id = Configuration::get('CRONJOBS_WEBSERVICE_ID') ? '/'.Configuration::get('CRONJOBS_WEBSERVICE_ID') : null;

		$data = array(
			'callback' => $this->context->link->getModuleLink('cronjobs', 'callback'),
			'cronjob' => $cron_url.'&token='.Configuration::get('CRONJOBS_EXECUTION_TOKEN', null, 0, 0),
			'cron_token' => Configuration::get('CRONJOBS_EXECUTION_TOKEN', null, 0, 0),
			'active' => ($cron_mode == 'advanced') ? false : true,
		);

		$context_options = array (
			'http' => array(
				'method' => $webservice_id ? 'PUT' : 'POST',
				'content' => http_build_query($data),
			)
		);

		$context = stream_context_create($context_options);
		$result = Tools::file_get_contents($this->webservice_url.$webservice_id, false, $context);
		Configuration::updateValue('CRONJOBS_WEBSERVICE_ID', (int)$result);

		if (((Tools::isSubmit('install') == false) && (Tools::isSubmit('reset') == false)) && ((bool)$result == false))
			return $this->setErrorMessage('An error occurred while trying to contact PrestaShop\'s webcron service.');
		elseif (((Tools::isSubmit('install') == true) || (Tools::isSubmit('reset') == true)) && ((bool)$result == false))
			return true;

		Configuration::updateValue('CRONJOBS_MODE', $cron_mode);

		switch ($cron_mode)
		{
			case 'advanced':
				return $this->setSuccessMessage('Your cron jobs have been successfully registered using the Advanced mode.');
			case 'webservice':
				return $this->setSuccessMessage('Your cron jobs have been successfully added to PrestaShop\'s webcrons service.');
			default:
				return;
		}
	}

	protected function postProcessDeleteCronJob($id_cronjob)
	{
		$id_cronjob = Tools::getValue('id_cronjob');
		$id_module = Db::getInstance()->getValue('SELECT `id_module` FROM '._DB_PREFIX_.$this->name.' WHERE `id_cronjob` = \''.(int)$id_cronjob.'\'');

		if ((bool)$id_module == false)
			Db::getInstance()->execute('DELETE FROM '._DB_PREFIX_.$this->name.' WHERE `id_cronjob` = \''.(int)$id_cronjob.'\'');
		else
			Db::getInstance()->execute('UPDATE '._DB_PREFIX_.$this->name.' SET `active` = FALSE WHERE `id_cronjob` = \''.(int)$id_cronjob.'\'');

		return Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules', false)
			.'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name
			.'&token='.Tools::getAdminTokenLite('AdminModules'));
	}

	protected function getForm()
	{
		$form = array(
			'form' => array(
				'legend' => array(
					'title' => $this->l('Settings'),
					'icon' => 'icon-cog',
				),
				'input' => array(
					array(
						'type' => 'radio',
						'name' => 'cron_mode',
						'label' => $this->l('Cron mode'),
						'values' => array(
							array('id' => 'webservice', 'value' => 'webservice', 'label' => $this->l('Basic'),
								'p' => $this->l('Use the PrestaShop cron jobs webservice to execute your tasks.')),
							array('id' => 'advanced', 'value' => 'advanced', 'label' => $this->l('Advanced'),
								'p' => $this->l('For advanced users only: use your own crontab manager instead of PrestaShop webcron service.'))
						),
					),
				),
				'submit' => array('title' => $this->l('Save'), 'type' => 'submit', 'class' => 'btn btn-default pull-right'),
			),
		);

		if (Configuration::get('CRONJOBS_MODE') == 'advanced')
			$form['form']['input'][] = array('type' => 'free','name' => 'advanced_help','col' => 12,'offset' => 0);

		return array($form);
	}

	protected function getFormValues()
	{
		$token = Configuration::get('CRONJOBS_EXECUTION_TOKEN', null, 0, 0);
		$php_client_path = $this->local_path.'classes/php_client.php token='.$token;

		$admin_folder = str_replace(_PS_ROOT_DIR_.'/', null, basename(_PS_ADMIN_DIR_));
		$path = Tools::getShopDomainSsl(true, true).__PS_BASE_URI__.$admin_folder.'/';
		$curl_url = $path.$this->context->link->getAdminLink('AdminCronJobs', false);
		$curl_url .= '&token='.$token;

		return array(
			'cron_mode' => Configuration::get('CRONJOBS_MODE'),
			'advanced_help' =>
				'<div class="alert alert-info">
					<p>'
						.$this->l('The Advanced mode enables you to use your own crontab file instead of PrestaShop webcron service. ')
						.$this->l('First of all, make sure \'php-cli\' or \'php-curl\' are installed on your server.')
						.'<br />'.$this->l('To execute your cron jobs, please insert one of the following lines (not both!) in your crontab manager:').'
					</p>
					<br />
					<ul class="list-unstyled">
						<li><code>0 * * * * php '.$php_client_path.'</code></li>
						<li><code>0 * * * * curl "'.$curl_url.'"</code></li>
					</ul>
				</div>'
		);
	}

	protected function getJobForm($title = 'New cron job', $update = false)
	{
		$form = array(
			array(
				'form' => array(
					'legend' => array(
						'title' => $this->l($title),
						'icon' => 'icon-plus',
					),
					'input' => array(),
					'submit' => array('title' => $this->l('Save'), 'type' => 'submit', 'class' => 'btn btn-default pull-right'),
				),
			),
		);
				
		$id_shop = (int)$this->context->shop->id;
		$id_shop_group = (int)$this->context->shop->id_shop_group;

		$currencies_cron_url = Tools::getShopDomain(true, true).__PS_BASE_URI__.basename(_PS_ADMIN_DIR_);
		$currencies_cron_url .= '/cron_currency_rates.php?secure_key='.md5(_COOKIE_KEY_.Configuration::get('PS_SHOP_NAME'));

		if (($update == true) && (Tools::isSubmit('id_cronjob')))
		{
			$id_cronjob = (int)Tools::getValue('id_cronjob');
			$id_module = (int)Db::getInstance()->getValue('SELECT `id_module` FROM `'._DB_PREFIX_.$this->name.'`
				WHERE `id_cronjob` = \''.(int)$id_cronjob.'\'
					AND `id_shop` = \''.$id_shop.'\' AND `id_shop_group` = \''.$id_shop_group.'\'');

			if ((bool)$id_module == true)
			{
				$form[0]['form']['input'][] = array(
					'type' => 'free',
					'name' => 'description',
					'label' => $this->l('Description'),
					'placeholder' => $this->l('Update my currencies'),
				);
				
				$form[0]['form']['input'][] = array(
					'type' => 'free',
					'name' => 'task',
					'label' => $this->l('Target link'),
				);
			}
			else
			{
				$form[0]['form']['input'][] = array(
					'type' => 'text',
					'name' => 'description',
					'label' => $this->l('Description'),
					'desc' => $this->l('Enter a description for this task.'),
					'placeholder' => $this->l('Update my currencies'),
				);

				$form[0]['form']['input'][] = array(
					'type' => 'text',
					'name' => 'task',
					'label' => $this->l('Target link'),
					'desc' => $this->l('Set the link of your cron job.'),
					'placeholder' => $currencies_cron_url,
				);
			}
		}
		else
		{
			$form[0]['form']['input'][] = array(
				'type' => 'text',
				'name' => 'description',
				'label' => $this->l('Description'),
				'desc' => $this->l('Enter a description for this task.'),
				'placeholder' => $this->l('Update my currencies'),
			);

			$form[0]['form']['input'][] = array(
				'type' => 'text',
				'name' => 'task',
				'label' => $this->l('Target link'),
				'desc' => $this->l('Do not forget to use an absolute URL to make it valid! The link also has to be on the same domain as the shop.'),
				'placeholder' => $currencies_cron_url,
			);
		}

		$form[0]['form']['input'][] = array(
			'type' => 'select',
			'name' => 'hour',
			'label' => $this->l('Frequency'),
			'options' => array(
				'query' => $this->getHoursFormOptions(),
				'id' => 'id', 'name' => 'name'
			),
		);
		$form[0]['form']['input'][] = array(
			'type' => 'select',
			'name' => 'day',
			'options' => array(
				'query' => $this->getDaysFormOptions(),
				'id' => 'id', 'name' => 'name'
			),
		);
		$form[0]['form']['input'][] = array(
			'type' => 'select',
			'name' => 'month',
			'options' => array(
				'query' => $this->getMonthsFormOptions(),
				'id' => 'id', 'name' => 'name'
			),
		);
		$form[0]['form']['input'][] = array(
			'type' => 'select',
			'name' => 'day_of_week',
			'options' => array(
				'query' => $this->getDaysofWeekFormOptions(),
				'id' => 'id', 'name' => 'name'
			),
		);

		return $form;
	}

	protected function getNewJobFormValues()
	{
		return array(
			'description' => Tools::safeOutput(Tools::getValue('description', null)),
			'task' => Tools::safeOutput(Tools::getValue('task', null)),
			'hour' => (int)Tools::getValue('hour', -1),
			'day' => (int)Tools::getValue('day', -1),
			'month' => (int)Tools::getValue('month', -1),
			'day_of_week' => (int)Tools::getValue('day_of_week', -1),
		);
	}

	protected function getUpdateJobFormValues()
	{
		$id_shop = (int)$this->context->shop->id;
		$id_shop_group = (int)$this->context->shop->id_shop_group;
		
		$id_cronjob = (int)Tools::getValue('id_cronjob');
		$cron = Db::getInstance()->getRow('SELECT * FROM `'._DB_PREFIX_.$this->name.'`
			WHERE `id_cronjob` = \''.$id_cronjob.'\'
			AND `id_shop` = \''.$id_shop.'\' AND `id_shop_group` = \''.$id_shop_group.'\'');

		if ((bool)$cron['id_module'] == false)
		{
			$description = Tools::safeOutput(Tools::getValue('description', $cron['description']));
			$task = Tools::safeOutput(urldecode(Tools::getValue('task', $cron['task'])));
		}
		else
		{
			$module_name = Db::getInstance()->getValue('SELECT `name` FROM `'._DB_PREFIX_.'module` WHERE `id_module` = \''.$id_cronjob.'\'');
			$description = '<p class="form-control-static"><strong>'.Tools::safeOutput(Module::getModuleName($module_name)).'</strong></p>';
			$task = '<p class="form-control-static"><strong>'.$this->l('Module - Hook').'</strong></p>';
		}

		return array(
			'description' => $description,
			'task' => $task,
			'hour' => (int)Tools::getValue('hour', $cron['hour']),
			'day' => (int)Tools::getValue('day', $cron['day']),
			'month' => (int)Tools::getValue('month', $cron['month']),
			'day_of_week' => (int)Tools::getValue('day_of_week', $cron['day_of_week']),
		);
	}

	protected function getHoursFormOptions()
	{
		$data = array(array('id' => '-1', 'name' => $this->l('Hourly (on the hour)')));

		for ($hour = 0; $hour < 24; $hour += 1)
			$data[] = array('id' => $hour, 'name' => date('H:i', mktime($hour, 0, 0, 0, 1)));

		return $data;
	}

	protected function getDaysFormOptions()
	{
		$data = array(array('id' => '-1', 'name' => $this->l('Daily')));

		for ($day = 1; $day <= 31; $day += 1)
			$data[] = array('id' => $day, 'name' => $day);

		return $data;
	}

	protected function getMonthsFormOptions()
	{
		$data = array(array('id' => '-1', 'name' => $this->l('Monthly')));

		for ($month = 1; $month <= 12; $month += 1)
			$data[] = array('id' => $month, 'name' => $this->l(date('F', mktime(0, 0, 0, $month, 1))));

		return $data;
	}

	protected function getDaysofWeekFormOptions()
	{
		$data = array(array('id' => '-1', 'name' => $this->l('Every day of the week')));

		for ($day = 1; $day <= 7; $day += 1)
			$data[] = array('id' => $day, 'name' => $this->l(date('l', mktime(0, 0, 0, 0, $day))));

		return $data;
	}

	protected function getTasksList()
	{
		return array(
			'description' => array('title' => $this->l('Description'), 'type' => 'text', 'orderby' => false),
			'task' => array('title' => $this->l('Target link'), 'type' => 'text', 'orderby' => false),
			'hour' => array('title' => $this->l('Hour'), 'type' => 'text', 'orderby' => false),
			'day' => array('title' => $this->l('Day'), 'type' => 'text', 'orderby' => false),
			'month' => array('title' => $this->l('Month'), 'type' => 'text', 'orderby' => false),
			'day_of_week' => array('title' => $this->l('Day of week'), 'type' => 'text', 'orderby' => false),
			'updated_at' => array('title' => $this->l('Last execution'), 'type' => 'text', 'orderby' => false),
			'active' => array('title' => $this->l('Active'), 'active' => 'status', 'type' => 'bool', 'align' => 'center', 'orderby' => false)
		);
	}

	protected function getTasksListValues()
	{
		$id_shop = (int)$this->context->shop->id;
		$id_shop_group = (int)$this->context->shop->id_shop_group;
		
		$this->addNewModulesTasks();
		$crons = Db::getInstance()->executeS('SELECT * FROM `'._DB_PREFIX_.$this->name.'` WHERE `id_shop` = \''.$id_shop.'\' AND `id_shop_group` = \''.$id_shop_group.'\'');

		foreach ($crons as $key => &$cron)
		{
			if (empty($cron['id_module']) == false)
			{
				$module = Module::getInstanceById((int)$cron['id_module']);
				
				if ($module == false)
				{
					Db::getInstance()->execute('DELETE FROM '._DB_PREFIX_.$this->name.' WHERE `id_cronjob` = \''.(int)$cron['id_cronjob'].'\'');
					unset($crons[$key]);
					break;
				}
				
				$query = 'SELECT `name` FROM `'._DB_PREFIX_.'module` WHERE `id_module` = \''.(int)$cron['id_module'].'\'';
				$module_name = Db::getInstance()->getValue($query);
			
				$cron['description'] = Tools::safeOutput(Module::getModuleName($module_name));
				$cron['task'] = $this->l('Module - Hook');
			}
			else
				$cron['task'] = Tools::safeOutput(urldecode($cron['task']));

			$cron['hour'] = ($cron['hour'] == -1) ? $this->l('Every hour') : date('H:i', mktime((int)$cron['hour'], 0, 0, 0, 1));
			$cron['day'] = ($cron['day'] == -1) ? $this->l('Every day') : (int)$cron['day'];
			$cron['month'] = ($cron['month'] == -1) ? $this->l('Every month') : $this->l(date('F', mktime(0, 0, 0, (int)$cron['month'], 1)));
			$cron['day_of_week'] = ($cron['day_of_week'] == -1) ? $this->l('Every day of the week') : $this->l(date('l', mktime(0, 0, 0, 0, (int)$cron['day_of_week'])));
			$cron['updated_at'] = ($cron['updated_at'] == 0) ? $this->l('Never') : date('Y-m-d H:i:s', strtotime($cron['updated_at']));
			$cron['active'] = (bool)$cron['active'];
		}
		
		return $crons;
	}
	
	protected function addNewModulesTasks()
	{
		$id_shop = (int)$this->context->shop->id;
		$id_shop_group = (int)$this->context->shop->id_shop_group;
		
		$crons = Hook::getHookModuleExecList('actionCronJob');
		
		if ($crons == false)
			return false;
		
		foreach ($crons as $cron)
		{
			$module = Module::getInstanceById((int)$cron['id_module']);
			
			if ($module == false)
			{
				Db::getInstance()->execute('DELETE FROM '._DB_PREFIX_.$this->name.' WHERE `id_cronjob` = \''.(int)$cron['id_cronjob'].'\'');
				break;
			}
			
			$id_module = (int)$cron['id_module'];
			$id_cronjob = (int)Db::getInstance()->getValue('SELECT `id_cronjob` FROM `'._DB_PREFIX_.$this->name.'`
				WHERE `id_module` = \''.$id_module.'\' AND `id_shop` = \''.$id_shop.'\' AND `id_shop_group` = \''.$id_shop_group.'\'');

			if ((bool)$id_cronjob == false)
				$this->registerModuleHook($id_module);
		}
	}
	
	protected function registerModuleHook($id_module)
	{
		$id_shop = (int)$this->context->shop->id;
		$id_shop_group = (int)$this->context->shop->id_shop_group;
		
		$module = Module::getInstanceById($id_module);
				
		if (is_callable(array($module, 'getCronFrequency')) == true)
		{
			$frequency = $module->getCronFrequency();
		
			$query = 'INSERT INTO '._DB_PREFIX_.$this->name.'
				(`id_module`, `hour`, `day`, `month`, `day_of_week`, `active`, `id_shop`, `id_shop_group`)
				VALUES (\''.$id_module.'\', \''.$frequency['hour'].'\', \''.$frequency['day'].'\',
					\''.$frequency['month'].'\', \''.$frequency['day_of_week'].'\',
					TRUE, '.$id_shop.', '.$id_shop_group.')';
		}
		else
			$query = 'INSERT INTO '._DB_PREFIX_.$this->name.'
				(`id_module`, `active`, `id_shop`, `id_shop_group`)
				VALUES ('.$id_module.', FALSE, '.$id_shop.', '.$id_shop_group.')';
			
		return Db::getInstance()->execute($query);
	}
	
	protected function unregisterModuleHook($id_module)
	{
		return Db::getInstance()->execute('DELETE FROM '._DB_PREFIX_.$this->name.' WHERE `id_module` = \''.(int)$id_module.'\'');
	}
}
