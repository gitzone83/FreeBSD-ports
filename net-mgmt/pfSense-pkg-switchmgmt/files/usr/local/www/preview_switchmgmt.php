<?php
/*
 * preview_switchmgmt.php
 *
 * Config preview and push page for Switch Management package.
 * Shows generated CLI commands and allows pushing to switch via SSH.
 *
 * Licensed under the Apache License, Version 2.0
 */

##|+PRIV
##|*IDENT=page-services-switchmgmt-preview
##|*NAME=Services: Switch Management Config Preview
##|*DESCR=Allow access to the 'Services: Switch Management Config Preview' page.
##|*MATCH=preview_switchmgmt.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("/usr/local/pkg/switchmgmt.inc");

$pgtitle = array(gettext("Services"), gettext("Switch Management"), gettext("Config Preview"));

$selected_switch = $_GET['switch'] ?? '';

include("head.inc");

/* Tabs */
$tab_array = array();
$tab_array[] = array(gettext("Settings"), false, "/pkg_edit.php?xml=switchmgmt_settings.xml&id=0");
$tab_array[] = array(gettext("Switches"), false, "/pkg.php?xml=switchmgmt.xml");
$tab_array[] = array(gettext("Switch Status"), false, "/status_switchmgmt.php");
$tab_array[] = array(gettext("Switch Port Profiles"), false, "/profiles_switchmgmt.php");
$tab_array[] = array(gettext("Neighbors"), false, "/neighbors_switchmgmt.php");
display_top_tabs($tab_array);

if (empty($selected_switch)) {
	print_info_box(gettext("No switch selected. Go to Switch Status and click Preview Config."), 'warning');
	include("foot.inc");
	exit;
}

$preview = switchmgmt_generate_config_preview($selected_switch);

// Get switch description and SSH creds status
$sw_desc = $selected_switch;
$has_ssh_creds = false;
$switches_config = switchmgmt_get_switches();
foreach ($switches_config as $sc) {
	if ($sc['ipaddr'] == $selected_switch) {
		$sw_desc = $sc['description'] . " ({$selected_switch})";
		$has_ssh_creds = !empty($sc['ssh_username']) && !empty($sc['ssh_password']);
		break;
	}
}

?>

<!-- Config Preview Panel -->
<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title">
			<?=sprintf(gettext("Config Preview: %s"), htmlspecialchars($sw_desc))?>
<?php if (!empty($preview['vendor'])): ?>
			<small class="text-muted">(<?=htmlspecialchars($preview['vendor'])?>)</small>
<?php endif; ?>
		</h2>
	</div>
	<div class="panel-body">
<?php
$total_assignments = switchmgmt_assignment_get_for_switch($selected_switch);
$synced_count = 0;
foreach ($total_assignments as $a) {
	if ($a['sync_status'] == 'synced') $synced_count++;
}
?>
<?php if (empty($preview['entries']) && empty($total_assignments)): ?>
		<p class="text-muted"><?=gettext("No port profiles assigned to this switch.")?></p>
<?php elseif (empty($preview['entries'])): ?>
		<div class="alert alert-success">
			<i class="fa-solid fa-check"></i>
			<?=sprintf(gettext("All %d port(s) are in sync. Nothing to push."), $synced_count)?>
		</div>
		<a class="btn btn-default btn-sm" href="status_switchmgmt.php?switch=<?=urlencode($selected_switch)?>">
			<i class="fa-solid fa-arrow-left icon-embed-btn"></i>
			<?=gettext("Back to Switch Status")?>
		</a>
<?php else: ?>
		<pre id="config-output" class="pre-scrollable" style="max-height:600px;"><?php
			foreach ($preview['entries'] as $entry) {
				echo "! Port: " . htmlspecialchars(switchmgmt_format_port_name($entry['port_name'])) .
					" - Profile: " . htmlspecialchars($entry['profile_name']) . "\n";
				foreach ($entry['commands'] as $line) {
					echo htmlspecialchars($line) . "\n";
				}
				echo "\n";
			}
		?></pre>
		<button class="btn btn-default btn-sm" onclick="var t=document.getElementById('config-output');var r=document.createRange();r.selectNodeContents(t);var s=window.getSelection();s.removeAllRanges();s.addRange(r);document.execCommand('copy');s.removeAllRanges();">
			<i class="fa-solid fa-copy icon-embed-btn"></i>
			<?=gettext("Copy to Clipboard")?>
		</button>
		<a class="btn btn-default btn-sm" href="status_switchmgmt.php?switch=<?=urlencode($selected_switch)?>">
			<i class="fa-solid fa-arrow-left icon-embed-btn"></i>
			<?=gettext("Back to Switch Status")?>
		</a>
<?php endif; ?>
	</div>
</div>

<?php if (!empty($preview['entries'])): ?>
<?php if ($has_ssh_creds): ?>
<div style="margin-top:10px;">
	<a href="push_switchmgmt.php?switch=<?=urlencode($selected_switch)?>" style="display:inline-block;padding:6px 12px;background:#d9534f;color:#fff;border-radius:4px;text-decoration:none;font-size:12px;">
		<i class="fa-solid fa-upload"></i> <?=gettext("Push Config to Switch")?>
	</a>
</div>
<?php else: ?>
<div class="alert alert-warning" style="margin-top:10px;">
	<i class="fa-solid fa-exclamation-triangle"></i>
	<?=sprintf(
		gettext('SSH credentials are not configured for this switch. %sEdit switch settings%s to add SSH username and password before pushing config.'),
		'<a href="/pkg_edit.php?xml=switchmgmt.xml">',
		'</a>'
	)?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php
include("foot.inc");
