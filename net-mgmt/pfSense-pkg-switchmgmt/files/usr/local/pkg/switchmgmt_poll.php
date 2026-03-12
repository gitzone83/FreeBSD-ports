<?php
/*
 * switchmgmt_poll.php
 *
 * Cron script to poll all configured switches via SNMP.
 * Called periodically by the cron job set up in switchmgmt.inc.
 *
 * Licensed under the Apache License, Version 2.0
 */

require_once("config.inc");
require_once("functions.inc");
require_once("/usr/local/pkg/switchmgmt.inc");

$settings = switchmgmt_get_settings();

if (!isset($settings['enable']) || $settings['enable'] != 'on') {
	exit(0);
}

switchmgmt_poll_all();
?>
