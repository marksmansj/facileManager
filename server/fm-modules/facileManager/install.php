<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2013 The facileManager Team                               |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | facileManager: Easy System Administration                               |
 +-------------------------------------------------------------------------+
 | http://www.facilemanager.com/                                           |
 +-------------------------------------------------------------------------+
*/

/**
 * facileManager Installer Functions
 *
 * @package facileManager
 * @subpackage Installer
 *
 */

/**
 * Attempts to create config.inc.php
 *
 * @since 1.0
 * @package facileManager
 * @subpackage Installer
 */
function createConfig() {
	$temp_config = generateConfig();
	$temp_file = ABSPATH . 'config.inc.php';
	
	if (!file_exists($temp_file) || !file_get_contents($temp_file)) {
		if (@file_put_contents($temp_file, '') === false) {
			printf('
	<p>' . _('I cannot create %s so please manually create it with the following contents:') . '</p>
	<textarea rows="20">%s</textarea>
	<p>' . _('Once done, click "Install."') . '</p>
	<p class="step"><a href="?step=3" class="button click_once">' . _('Install') . '</a></p>', "<code>$temp_file</code>", $temp_config);
		} else {
			echo '<form method="post" action="?step=3"><center><table class="form-table">' . "\n";
			
			$retval = @file_put_contents($temp_file, $temp_config) ? true : false;
			displayProgress(_('Creating Configuration File'), $retval);
			
			echo "</table>\n</center>\n";
			
			if ($retval) {
				echo '<p style="text-align: center;">' .
					_("Config file has been created! Now let's create the database schema.") .
					'</p><p class="step"><a href="?step=3" class="button click_once">' . _('Continue') . '</a></p>';
			} else {
				echo '<p style="text-align: center;">' . _('Config file creation failed. Please try again.') .
					'</p><p class="step"><a href="?step=2" class="button click_once">' . _('Try Again') . '</a></p>';
			}
			
			echo "</form>\n";
		}
	}
}

/**
 * Generates config.inc.php content
 *
 * @since 1.0
 * @package facileManager
 * @subpackage Installer
 */
function generateConfig() {
	global $fm_name;
	
	extract($_POST);
	$dbname = sanitize($dbname, '_');
	
	$config = <<<CFG
<?php

/**
 * Contains configuration details for $fm_name
 *
 * @package $fm_name
 *
 */

/** Database credentials */
\$__FM_CONFIG['db']['host'] = '$dbhost';
\$__FM_CONFIG['db']['user'] = '$dbuser';
\$__FM_CONFIG['db']['pass'] = '$dbpass';
\$__FM_CONFIG['db']['name'] = '$dbname';

require_once(ABSPATH . 'fm-modules/facileManager/functions.php');

?>
CFG;

	return $config;
}

/**
 * Processes installation.
 *
 * @since 1.0
 * @package facileManager
 * @subpackage Installer
 */
function fmInstall($link, $database) {
	echo '<form method="post" action="?step=3"><center><table class="form-table">' . "\n";

	$retval = installDatabase($link, $database);
	
	echo "</table>\n</center>\n";

	if ($retval) {
		echo '<p style="text-align: center;">' . _("Database setup is complete! Now let's create your administrative account.") .
			'</p><p class="step"><a href="?step=4" class="button">' . _('Continue') . '</a></p>';
	} else {
		echo '<p style="text-align: center;">' . _("Database setup failed. Please try again.") .
			'</p><p class="step"><a href="?step=3" class="button click_once">' . _('Try Again') . '</a></p>';
	}
	
	echo "</form>\n";
}


function installDatabase($link, $database) {
	global $fm_version, $fm_name;
	
	$db_selected = @mysql_select_db($database, $link);
	if (!$db_selected) {
		$query = sanitize("CREATE DATABASE IF NOT EXISTS $database DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci");
		$result = mysql_query($query, $link);
		$output = displayProgress(_('Creating Database'), $result);
	} else {
		$output = _('Success');
	}
	
	if ($output == _('Success')) $output = installSchema($link, $database);
	if ($output == _('Success')) {
		$modules = getAvailableModules();
		if (count($modules)) {
			echo '<tr>
				<td colspan="2" id="install_module_list"><p><b>' . _('The following modules were installed as well:</b><br />(They can always be uninstalled later.)') . '</p></td>
			</tr>';

			foreach ($modules as $module_name) {
				if (file_exists(dirname(__FILE__) . '/../' . $module_name . '/install.php')) {
					include(dirname(__FILE__) . '/../' . $module_name . '/install.php');
					
					$function = 'install' . $module_name . 'Schema';
					if (function_exists($function)) {
						$output = $function($link, $database, $module_name);
					}
					if ($output == _('Success')) {
						addLogEntry(sprintf(_('%s %s was born.'), $module_name, $fm_version), $module_name, $link);
					}
				}
			}
		}
	}
	
	return ($output == _('Success')) ? true : false;
}


function installSchema($link, $database) {
	include(ABSPATH . 'fm-includes/version.php');
	include(ABSPATH . 'fm-modules/facileManager/variables.inc.php');
	
	$default_timezone = date_default_timezone_get() ? date_default_timezone_get() : 'America/Denver';

	$table[] = <<<TABLE
CREATE TABLE IF NOT EXISTS $database.`fm_accounts` (
  `account_id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY ,
  `account_key` varchar(255) NOT NULL,
  `account_name` VARCHAR(255) NOT NULL ,
  `account_status` ENUM( 'active',  'disabled',  'deleted') NOT NULL DEFAULT  'active'
) ENGINE = MYISAM DEFAULT CHARSET=utf8;
TABLE;

	$table[] = <<<TABLE
CREATE TABLE IF NOT EXISTS $database.`fm_logs` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL DEFAULT '0',
  `account_id` int(11) NOT NULL DEFAULT '1',
  `log_module` varchar(255) NOT NULL,
  `log_timestamp` int(10) NOT NULL DEFAULT '0',
  `log_data` text NOT NULL,
  PRIMARY KEY (`log_id`)
) ENGINE=INNODB DEFAULT CHARSET=utf8 ;
TABLE;

	$table[] = <<<TABLE
CREATE TABLE IF NOT EXISTS $database.`fm_options` (
  `option_id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) NOT NULL DEFAULT '0',
  `module_name` varchar(255) DEFAULT NULL,
  `option_name` varchar(50) NOT NULL,
  `option_value` text NOT NULL,
  PRIMARY KEY (`option_id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 ;
TABLE;

	$table[] = <<<TABLE
CREATE TABLE IF NOT EXISTS $database.`fm_pwd_resets` (
  `pwd_id` varchar(255) NOT NULL,
  `pwd_login` int(11) NOT NULL,
  `pwd_timestamp` int(10) NOT NULL DEFAULT '0',
  PRIMARY KEY (`pwd_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;
TABLE;

	$table[] = <<<TABLE
CREATE TABLE IF NOT EXISTS $database.`fm_users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) NOT NULL DEFAULT '1',
  `user_login` varchar(128) NOT NULL,
  `user_password` varchar(255) NOT NULL,
  `user_email` varchar(255) NOT NULL,
  `user_default_module` varchar(255) DEFAULT NULL,
  `user_auth_type` int(1) NOT NULL DEFAULT '1',
  `user_caps` text,
  `user_last_login` int(10) NOT NULL DEFAULT '0',
  `user_ipaddr` varchar(255) DEFAULT NULL,
  `user_force_pwd_change` enum('yes','no') NOT NULL DEFAULT 'no',
  `user_template_only` enum('yes','no') NOT NULL DEFAULT 'no',
  `user_status` enum('active','disabled','deleted') NOT NULL DEFAULT 'active',
  PRIMARY KEY (`user_id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8;
TABLE;



	$inserts[] = <<<INSERT
INSERT IGNORE INTO  $database.`fm_accounts` (`account_id` ,`account_key`, `account_name` ,`account_status`) VALUES ('1' , 'default', 'Default Account',  'active');
INSERT;

	$inserts[] = <<<INSERT
INSERT INTO $database.`fm_options` (option_name, option_value) 
	SELECT 'fm_db_version', '$fm_db_version' FROM DUAL
WHERE NOT EXISTS
	(SELECT option_name FROM $database.`fm_options` WHERE option_name = 'fm_db_version');
INSERT;

	$inserts[] = <<<INSERT
INSERT INTO $database.`fm_options` (`option_name`, `option_value`) 
	SELECT 'auth_method', '1' FROM DUAL
WHERE NOT EXISTS
	(SELECT option_name FROM $database.`fm_options` WHERE option_name = 'auth_method');
INSERT;

	$inserts[] = <<<INSERT
INSERT INTO $database.`fm_options` (`option_name`, `option_value`) 
	SELECT 'mail_enable', '1' FROM DUAL
WHERE NOT EXISTS
	(SELECT option_name FROM $database.`fm_options` WHERE option_name = 'mail_enable');
INSERT;

	$inserts[] = <<<INSERT
INSERT INTO $database.`fm_options` (`option_name`, `option_value`) 
	SELECT 'mail_smtp_host', 'localhost' FROM DUAL
WHERE NOT EXISTS
	(SELECT option_name FROM $database.`fm_options` WHERE option_name = 'mail_smtp_host');
INSERT;

	$inserts[] = "
INSERT INTO $database.`fm_options` (`option_name`, `option_value`) 
	SELECT 'mail_from', 'noreply@" . php_uname('n') . "' FROM DUAL
WHERE NOT EXISTS
	(SELECT option_name FROM $database.`fm_options` WHERE option_name = 'mail_from');
";

	$inserts[] = <<<INSERT
INSERT INTO $database.`fm_options` (`option_name`, `option_value`) 
	SELECT 'mail_smtp_tls', '0' FROM DUAL
WHERE NOT EXISTS
	(SELECT option_name FROM $database.`fm_options` WHERE option_name = 'mail_smtp_tls');
INSERT;

	$inserts[] = <<<INSERT
INSERT INTO $database.`fm_options` (`account_id` ,`option_name`, `option_value`) 
	SELECT 1, 'timezone', '$default_timezone' FROM DUAL
WHERE NOT EXISTS
	(SELECT option_name FROM $database.`fm_options` WHERE option_name = 'timezone');
INSERT;

	$inserts[] = <<<INSERT
INSERT INTO $database.`fm_options` (`account_id` ,`option_name`, `option_value`) 
	SELECT 1, 'date_format', 'D, d M Y' FROM DUAL
WHERE NOT EXISTS
	(SELECT option_name FROM $database.`fm_options` WHERE option_name = 'date_format');
INSERT;

	$inserts[] = <<<INSERT
INSERT INTO $database.`fm_options` (`account_id` ,`option_name`, `option_value`) 
	SELECT 1, 'time_format', 'H:i:s O' FROM DUAL
WHERE NOT EXISTS
	(SELECT option_name FROM $database.`fm_options` WHERE option_name = 'time_format');
INSERT;

	$inserts[] = <<<INSERT
INSERT INTO $database.`fm_options` (`account_id` ,`option_name`, `option_value`) 
	SELECT 0, 'fm_temp_directory', '/tmp' FROM DUAL
WHERE NOT EXISTS
	(SELECT option_name FROM $database.`fm_options` WHERE option_name = 'fm_temp_directory');
INSERT;

	$inserts[] = <<<INSERT
INSERT INTO $database.`fm_options` (`account_id` ,`option_name`, `option_value`) 
	SELECT 0, 'software_update', 1 FROM DUAL
WHERE NOT EXISTS
	(SELECT option_name FROM $database.`fm_options` WHERE option_name = 'software_update');
INSERT;

	$inserts[] = <<<INSERT
INSERT INTO $database.`fm_options` (`account_id` ,`option_name`, `option_value`) 
	SELECT 0, 'software_update_interval', 'week' FROM DUAL
WHERE NOT EXISTS
	(SELECT option_name FROM $database.`fm_options` WHERE option_name = 'software_update_interval');
INSERT;

/** Update user capabilities */
if ($link) {
	$fm_user_caps_query = "SELECT option_value FROM $database.`fm_options` WHERE option_name='fm_user_caps';";
	$result = mysql_query($fm_user_caps_query, $link);
	if ($result) {
		$row = mysql_fetch_array($result, MYSQL_NUM);
		$fm_user_caps = isSerialized($row[0]) ? unserialize($row[0]) : $row[0];
	}
} else {
	$fm_user_caps = getOption('fm_user_caps');
}
$fm_user_caps[$fm_name] = array(
		'do_everything'		=> '<b>Super Admin</b>',
		'manage_modules'	=> 'Module Management',
		'manage_users'		=> 'User Management',
		'run_tools'			=> 'Run Tools',
		'manage_settings'	=> 'Manage Settings'
	);
$fm_user_caps = serialize($fm_user_caps);
$inserts[] = <<<INSERT
INSERT INTO $database.`fm_options` (option_name, option_value) 
	SELECT 'fm_user_caps', '$fm_user_caps' FROM DUAL
WHERE NOT EXISTS
	(SELECT option_name FROM $database.`fm_options` WHERE option_name = 'fm_user_caps');
INSERT;


	/** Create table schema */
	foreach ($table as $schema) {
		$result = @mysql_query($schema, $link);
		if (mysql_error()) {
			echo mysql_error();
			return displayProgress(sprintf(_('Creating %s Schema'), $fm_name), $result);
		}
	}

	/** Insert site values if not already present */
	$query = "SELECT * FROM fm_options";
	$temp_result = mysql_query($query, $link);
	if (!@mysql_num_rows($temp_result)) {
		foreach ($inserts as $query) {
			$result = @mysql_query($query, $link);
			if (mysql_error()) {
				echo mysql_error();
				return displayProgress(sprintf(_('Creating %s Schema'), $fm_name), $result);
			}
		}
	}
	
	addLogEntry(sprintf(_('%s %s was born.'), $fm_name, $fm_version), $fm_name, $link);

	return displayProgress(sprintf(_('Creating %s Schema'), $fm_name), $result);
}


?>
