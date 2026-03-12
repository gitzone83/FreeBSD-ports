<?php
/*
 * switchmgmt.priv.inc
 *
 * Licensed under the Apache License, Version 2.0
 */

global $priv_list;

$priv_list['page-services-switchmgmt'] = array();
$priv_list['page-services-switchmgmt']['name'] = "WebCfg - Services: Switch Management package";
$priv_list['page-services-switchmgmt']['descr'] = "Allow access to Switch Management package GUI";
$priv_list['page-services-switchmgmt']['match'] = array();

$priv_list['page-services-switchmgmt']['match'][] = "pkg.php?xml=switchmgmt.xml*";
$priv_list['page-services-switchmgmt']['match'][] = "pkg_edit.php?xml=switchmgmt.xml*";
$priv_list['page-services-switchmgmt']['match'][] = "pkg_edit.php?xml=switchmgmt_settings.xml*";

$priv_list['page-status-switchmgmt'] = array();
$priv_list['page-status-switchmgmt']['name'] = "WebCfg - Status: Switch Management";
$priv_list['page-status-switchmgmt']['descr'] = "Allow access to Switch Management status page";
$priv_list['page-status-switchmgmt']['match'] = array();

$priv_list['page-status-switchmgmt']['match'][] = "status_switchmgmt.php*";

?>
