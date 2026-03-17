<?php
/*
 * push_switchmgmt.php
 *
 * Config push execution page for Switch Management package.
 *
 * Licensed under the Apache License, Version 2.0
 */

##|+PRIV
##|*IDENT=page-services-switchmgmt-push
##|*NAME=Services: Switch Management Push Config
##|*DESCR=Allow access to the 'Services: Switch Management Push Config' page.
##|*MATCH=push_switchmgmt.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("/usr/local/pkg/switchmgmt.inc");
require_once("/usr/local/pkg/switchmgmt_ssh.inc");

$pgtitle = array(gettext("Services"), gettext("Switch Management"), gettext("Push Config"));

$selected_switch = $_GET['switch'] ?? $_POST['switch'] ?? '';
$confirmed = !empty($_GET['confirmed']) || !empty($_POST['confirmed']);
$push_result = null;

if ($confirmed && !empty($selected_switch)) {
	$switches = switchmgmt_get_switches();
	foreach ($switches as $sc) {
		if ($sc['ipaddr'] == $selected_switch) {
			$push_result = switchmgmt_ssh_push_config($sc);
			break;
		}
	}
	if ($push_result === null) {
		$push_result = array('success' => false, 'errors' => array(gettext("Switch not found.")), 'log' => array());
	}
}

include("head.inc");

$tab_array = array();
$tab_array[] = array(gettext("Settings"), false, "/pkg_edit.php?xml=switchmgmt_settings.xml&id=0");
$tab_array[] = array(gettext("Switch Configuration"), false, "/pkg.php?xml=switchmgmt.xml");
$tab_array[] = array(gettext("Switch Port Profiles"), false, "/profiles_switchmgmt.php");
$tab_array[] = array(gettext("Switch & Port Status"), false, "/status_switchmgmt.php");
$tab_array[] = array(gettext("Switch Port Charts"), false, "/charts_switchmgmt.php");
$tab_array[] = array(gettext("Neighbors"), false, "/neighbors_switchmgmt.php");
display_top_tabs($tab_array);

if (empty($selected_switch)) {
	print_info_box(gettext("No switch selected."), 'warning');
	include("foot.inc");
	exit;
}

// Get switch info
$sw_desc = $selected_switch;
foreach (switchmgmt_get_switches() as $sc) {
	if ($sc['ipaddr'] == $selected_switch) {
		$sw_desc = $sc['description'] . " ({$selected_switch})";
		break;
	}
}

if ($push_result !== null):
	// Show results
	if ($push_result['success']) {
		print_info_box(sprintf(gettext("Config pushed successfully to %s."), htmlspecialchars($sw_desc)), 'success');
	} else {
		$errs = implode('<br/>', array_map('htmlspecialchars', $push_result['errors']));
		print_info_box(sprintf(gettext("Config push to %s failed."), htmlspecialchars($sw_desc)) . '<br/>' . $errs, 'danger');
	}

	if (!empty($push_result['log'])):
?>
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext("Push Log")?></h2></div>
	<div class="panel-body">
		<pre class="pre-scrollable" style="max-height:400px;"><?php
			foreach ($push_result['log'] as $entry) {
				$cmd = $entry['cmd'];
				$out = $entry['output'];
				if (strpos($cmd, '--') === 0) {
					echo htmlspecialchars($cmd) . "\n";
				} else {
					echo ">> " . htmlspecialchars($cmd) . "\n";
					if (!empty($out)) {
						$clean = preg_replace('/\x1b\[[0-9;]*[a-zA-Z]/', '', $out);
						foreach (explode("\n", $clean) as $l) {
							$l = trim($l);
							if (!empty($l) && $l !== $cmd) {
								echo "   " . htmlspecialchars($l) . "\n";
							}
						}
					}
				}
			}
		?></pre>
	</div>
</div>
<?php endif; ?>

<div style="margin:15px 0;">
	<a href="preview_switchmgmt.php?switch=<?=urlencode($selected_switch)?>" style="display:inline-block;padding:6px 12px;background:#5bc0de;color:#fff;border-radius:4px;text-decoration:none;font-size:12px;">
		<i class="fa-solid fa-arrow-left"></i> <?=gettext("Back to Preview")?>
	</a>
	<a href="status_switchmgmt.php?switch=<?=urlencode($selected_switch)?>" style="display:inline-block;padding:6px 12px;background:#5bc0de;color:#fff;border-radius:4px;text-decoration:none;font-size:12px;">
		<i class="fa-solid fa-list"></i> <?=gettext("Back to Switch & Port Status")?>
	</a>
</div>

<?php else: ?>

<div class="panel panel-warning">
	<div class="panel-heading">
		<h2 class="panel-title"><?=gettext("Confirm Config Push")?></h2>
	</div>
	<div class="panel-body">
		<p><?=sprintf(gettext("You are about to push the assigned port profile configuration to %s. This will modify the switch's running configuration."), '<strong>' . htmlspecialchars($sw_desc) . '</strong>')?></p>
		<p><?=gettext("Are you sure you want to proceed?")?></p>
		<div style="margin:15px;">
			<a href="push_switchmgmt.php?switch=<?=urlencode($selected_switch)?>&confirmed=1" style="display:inline-block;padding:6px 12px;background:#d9534f;color:#fff;border-radius:4px;text-decoration:none;font-size:12px;">
				<i class="fa-solid fa-upload"></i> <?=gettext("Yes, Push Config Now")?>
			</a>
			<a href="preview_switchmgmt.php?switch=<?=urlencode($selected_switch)?>" style="display:inline-block;padding:6px 12px;background:#5bc0de;color:#fff;border-radius:4px;text-decoration:none;font-size:12px;">
				<i class="fa-solid fa-arrow-left"></i> <?=gettext("Cancel")?>
			</a>
		</div>
	</div>
</div>

<?php endif; ?>

<?php
include("foot.inc");
