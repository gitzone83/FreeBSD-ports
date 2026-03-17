<?php
/*
 * testssh_switchmgmt.php
 *
 * SSH connectivity test page for Switch Management package.
 *
 * Licensed under the Apache License, Version 2.0
 */

##|+PRIV
##|*IDENT=page-services-switchmgmt-testssh
##|*NAME=Services: Switch Management Test SSH
##|*DESCR=Allow access to the 'Services: Switch Management Test SSH' page.
##|*MATCH=testssh_switchmgmt.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("/usr/local/pkg/switchmgmt.inc");
require_once("/usr/local/pkg/switchmgmt_ssh.inc");

$pgtitle = array(gettext("Services"), gettext("Switch Management"), gettext("Test SSH"));

$switch_ip = $_REQUEST['switch'] ?? '';
$test_result = null;

if ($_POST && $_POST['test_ssh'] && !empty($_POST['switch'])) {
	$switch_ip = $_POST['switch'];
	$switches = switchmgmt_get_switches();
	foreach ($switches as $sw) {
		if ($sw['ipaddr'] == $switch_ip) {
			$test_result = switchmgmt_ssh_test($sw);
			break;
		}
	}
	if ($test_result === null) {
		$test_result = array('success' => false, 'error' => gettext("Switch not found in configuration."));
	}
}

include("head.inc");

/* Tabs */
$tab_array = array();
$tab_array[] = array(gettext("Settings"), false, "/pkg_edit.php?xml=switchmgmt_settings.xml&id=0");
$tab_array[] = array(gettext("Switches"), false, "/pkg.php?xml=switchmgmt.xml");
$tab_array[] = array(gettext("Switch Status"), false, "/status_switchmgmt.php");
$tab_array[] = array(gettext("Switch Port Profiles"), false, "/profiles_switchmgmt.php");
$tab_array[] = array(gettext("Neighbors"), false, "/neighbors_switchmgmt.php");
display_top_tabs($tab_array);

$switches = switchmgmt_get_switches();
?>

<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title"><?=gettext("Test SSH Connectivity")?></h2>
	</div>
	<div class="panel-body">
		<form action="testssh_switchmgmt.php" method="post">
			<div class="form-group">
				<label><?=gettext("Select Switch")?></label>
				<select name="switch" class="form-control" style="width:auto;display:inline-block;">
<?php foreach ($switches as $sw): ?>
					<option value="<?=htmlspecialchars($sw['ipaddr'])?>" <?=$sw['ipaddr'] == $switch_ip ? 'selected' : ''?>>
						<?=htmlspecialchars($sw['description'] . ' (' . $sw['ipaddr'] . ')')?>
					</option>
<?php endforeach; ?>
				</select>
				<button class="btn btn-primary btn-sm" type="submit" name="test_ssh" value="1">
					<i class="fa-solid fa-plug icon-embed-btn"></i>
					<?=gettext("Test Connection")?>
				</button>
			</div>
		</form>

<?php if ($test_result !== null): ?>
<?php if ($test_result['success']): ?>
		<div class="alert alert-success">
			<strong><?=gettext("SSH connection successful!")?></strong>
		</div>
		<pre class="pre-scrollable" style="max-height:400px;"><?=htmlspecialchars($test_result['output'])?></pre>
<?php else: ?>
		<div class="alert alert-danger">
			<strong><?=gettext("SSH connection failed:")?></strong>
			<?=htmlspecialchars($test_result['error'])?>
		</div>
<?php endif; ?>
<?php endif; ?>
	</div>
</div>

<?php
include("foot.inc");
