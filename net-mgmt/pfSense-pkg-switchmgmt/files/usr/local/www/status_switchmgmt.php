<?php
/*
 * status_switchmgmt.php
 *
 * Status page for Switch Management package.
 * Shows discovered switches and per-port statistics.
 *
 * Licensed under the Apache License, Version 2.0
 */

##|+PRIV
##|*IDENT=page-status-switchmgmt
##|*NAME=Status: Switch Management
##|*DESCR=Allow access to the 'Status: Switch Management' page.
##|*MATCH=status_switchmgmt.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("/usr/local/pkg/switchmgmt.inc");

$pgtitle = array(gettext("Status"), gettext("Switch Management"));

/* Handle poll-now action */
if ($_POST) {
	if ($_POST['pollnow']) {
		switchmgmt_poll_all();
		$savemsg = gettext("All switches have been polled.");
	} elseif ($_POST['pollone'] && !empty($_POST['switch_ip'])) {
		$switches = switchmgmt_get_switches();
		foreach ($switches as $sw) {
			if ($sw['ipaddr'] == $_POST['switch_ip']) {
				switchmgmt_poll_switch($sw);
				$savemsg = sprintf(gettext("Switch %s has been polled."), htmlspecialchars($_POST['switch_ip']));
				break;
			}
		}
	} elseif ($_POST['assign_profile'] && !empty($_POST['switch_ip'])) {
		$profile_assignments = $_POST['profile'] ?? array();
		foreach ($profile_assignments as $ifindex => $profile_id) {
			switchmgmt_assignment_set($_POST['switch_ip'], $ifindex, $profile_id);
		}
		$savemsg = gettext("Profile assignments saved.");
	}
}

$selected_switch = $_GET['switch'] ?? $_POST['switch'] ?? '';

include("head.inc");

$settings = switchmgmt_get_settings();
if (!isset($settings['enable']) || $settings['enable'] != 'on') {
	print_info_box(
		sprintf(
			gettext('Switch polling is currently disabled. Enable it here: %1$s%2$s%3$s.'),
			'<a href="pkg_edit.php?xml=switchmgmt_settings.xml&id=0">',
			gettext('Services &gt; Switch Management &gt; Settings'),
			'</a>'
		),
		'warning'
	);
}

if ($savemsg) {
	print_info_box($savemsg, 'success');
	// Scroll to message after page load
	echo '<script>document.addEventListener("DOMContentLoaded",function(){window.scrollTo(0,0);});</script>';
}

/* Tabs */
$tab_array = array();
$tab_array[] = array(gettext("Settings"), false, "/pkg_edit.php?xml=switchmgmt_settings.xml&id=0");
$tab_array[] = array(gettext("Switches"), false, "/pkg.php?xml=switchmgmt.xml");
$tab_array[] = array(gettext("Switch Status"), true, "/status_switchmgmt.php");
$tab_array[] = array(gettext("Switch Port Profiles"), false, "/profiles_switchmgmt.php");
$tab_array[] = array(gettext("Neighbors"), false, "/neighbors_switchmgmt.php");
display_top_tabs($tab_array);

$switch_list = switchmgmt_get_switch_status();
?>

<!-- Switch Overview -->
<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title"><?=gettext("Discovered Switches")?></h2>
	</div>
	<div class="panel-body">
		<div class="table-responsive">
			<table class="table table-striped table-hover table-condensed sortable-theme-bootstrap" data-sortable>
				<thead>
					<tr>
						<th><?=gettext("IP Address")?></th>
						<th><?=gettext("System Name")?></th>
						<th><?=gettext("Model")?></th>
						<th><?=gettext("Version")?></th>
						<th><?=gettext("Uptime")?></th>
						<th><?=gettext("Status")?></th>
						<th><?=gettext("Last Polled")?></th>
						<th><?=gettext("Actions")?></th>
					</tr>
				</thead>
				<tbody>
<?php if (empty($switch_list)): ?>
					<tr>
						<td colspan="8"><?=gettext("No switches have been polled yet. Add switches and enable polling in Settings.")?></td>
					</tr>
<?php else: ?>
<?php foreach ($switch_list as $sw): ?>
					<tr>
						<td>
							<a href="?switch=<?=urlencode($sw['ipaddr'])?>"><?=htmlspecialchars($sw['ipaddr'])?></a>
						</td>
						<td><?=htmlspecialchars($sw['sysname'] ?? '-')?></td>
						<td><?=htmlspecialchars($sw['model'] ?? $sw['sysdescr'] ?? '-')?></td>
						<td><?=htmlspecialchars($sw['version'] ?? '-')?></td>
						<td><?=htmlspecialchars($sw['sysuptime'] ?? '-')?></td>
						<td>
<?php if ($sw['poll_status'] == 'ok'): ?>
							<span class="label label-success"><?=gettext("OK")?></span>
<?php elseif ($sw['poll_status'] == 'unreachable'): ?>
							<span class="label label-danger"><?=gettext("Unreachable")?></span>
<?php else: ?>
							<span class="label label-default"><?=gettext("Unknown")?></span>
<?php endif; ?>
						</td>
						<td><?=$sw['last_polled'] ? date('Y-m-d H:i:s', $sw['last_polled']) : '-'?></td>
						<td>
							<form action="status_switchmgmt.php" method="post" style="display:inline">
								<input type="hidden" name="switch_ip" value="<?=htmlspecialchars($sw['ipaddr'])?>"/>
								<input type="hidden" name="switch" value="<?=htmlspecialchars($selected_switch)?>"/>
								<button class="btn btn-xs btn-primary" type="submit" name="pollone" value="1" title="<?=gettext('Poll Now')?>">
									<i class="fa-solid fa-refresh"></i>
								</button>
							</form>
							<a class="btn btn-xs btn-info" href="?switch=<?=urlencode($sw['ipaddr'])?>" title="<?=gettext('View Ports')?>">
								<i class="fa-solid fa-list"></i>
							</a>
						</td>
					</tr>
<?php endforeach; ?>
<?php endif; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>

<?php if (!empty($selected_switch)):
	$ports = switchmgmt_get_port_status($selected_switch);
	$profiles = switchmgmt_profile_get_all();
	$assignments = switchmgmt_assignment_get_for_switch($selected_switch);
	// Find the config description for this switch
	$sw_desc = $selected_switch;
	$switches_config = switchmgmt_get_switches();
	foreach ($switches_config as $sc) {
		if ($sc['ipaddr'] == $selected_switch) {
			$sw_desc = $sc['description'] . " ({$selected_switch})";
			break;
		}
	}
?>

<!-- Port Details -->
<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title"><?=sprintf(gettext("Port Details: %s"), htmlspecialchars($sw_desc))?></h2>
	</div>
	<div class="panel-body">
		<form action="status_switchmgmt.php" method="post">
		<input type="hidden" name="switch_ip" value="<?=htmlspecialchars($selected_switch)?>"/>
		<input type="hidden" name="switch" value="<?=htmlspecialchars($selected_switch)?>"/>
		<div class="table-responsive">
			<table class="table table-striped table-hover table-condensed sortable-theme-bootstrap" data-sortable>
				<thead>
					<tr>
						<th rowspan="2"><?=gettext("Port")?></th>
						<th colspan="2" style="text-align:center"><?=gettext("Port Speed")?></th>
						<th colspan="2" style="text-align:center"><?=gettext("Port Status")?></th>
						<th colspan="2" style="text-align:center"><?=gettext("Octets")?></th>
						<th colspan="2" style="text-align:center"><?=gettext("Packets")?></th>
						<th colspan="2" style="text-align:center"><?=gettext("Errors")?></th>
						<th colspan="2" style="text-align:center"><?=gettext("Discards")?></th>
						<th rowspan="2"><?=gettext("Profile")?></th>
					</tr>
					<tr>
						<th><?=gettext("Capability")?></th>
						<th><?=gettext("Current")?></th>
						<th><?=gettext("Admin")?></th>
						<th><?=gettext("Oper")?></th>
						<th><?=gettext("In")?></th>
						<th><?=gettext("Out")?></th>
						<th><?=gettext("In")?></th>
						<th><?=gettext("Out")?></th>
						<th><?=gettext("In")?></th>
						<th><?=gettext("Out")?></th>
						<th><?=gettext("In")?></th>
						<th><?=gettext("Out")?></th>
					</tr>
				</thead>
				<tbody>
<?php if (empty($ports)): ?>
					<tr>
						<td colspan="14"><?=gettext("No port data available for this switch.")?></td>
					</tr>
<?php else: ?>
<?php foreach ($ports as $port):
	$oper = switchmgmt_oper_status_text($port['ifoperstatus']);
	$admin = switchmgmt_admin_status_text($port['ifadminstatus']);
	$oper_class = ($oper == 'up') ? 'success' : (($oper == 'down') ? 'danger' : 'default');
	$admin_class = ($admin == 'up') ? 'success' : (($admin == 'down') ? 'warning' : 'default');
?>
					<tr>
						<td><?=htmlspecialchars(switchmgmt_format_port_name($port['ifdescr'] ?: '-'))?></td>
						<td><?=switchmgmt_format_speed($port['ifspeed'], $port['ifmaxspeed'] ?? $port['ifhighspeed'])?></td>
						<td><?=($oper == 'up') ? switchmgmt_format_speed($port['ifspeed'], $port['ifhighspeed']) : '-'?></td>
						<td><span class="label label-<?=$admin_class?>"><?=$admin?></span></td>
						<td><span class="label label-<?=$oper_class?>"><?=$oper?></span></td>
						<td><?=switchmgmt_format_bytes($port['in_octets'])?></td>
						<td><?=switchmgmt_format_bytes($port['out_octets'])?></td>
						<td><?=number_format($port['in_ucast_pkts'])?></td>
						<td><?=number_format($port['out_ucast_pkts'])?></td>
						<td>
<?php if ($port['in_errors'] > 0): ?>
							<span class="text-danger"><?=number_format($port['in_errors'])?></span>
<?php else: ?>
							<?=number_format($port['in_errors'])?>
<?php endif; ?>
						</td>
						<td>
<?php if ($port['out_errors'] > 0): ?>
							<span class="text-danger"><?=number_format($port['out_errors'])?></span>
<?php else: ?>
							<?=number_format($port['out_errors'])?>
<?php endif; ?>
						</td>
						<td>
<?php if ($port['in_discards'] > 0): ?>
							<span class="text-warning"><?=number_format($port['in_discards'])?></span>
<?php else: ?>
							<?=number_format($port['in_discards'])?>
<?php endif; ?>
						</td>
						<td>
<?php if ($port['out_discards'] > 0): ?>
							<span class="text-warning"><?=number_format($port['out_discards'])?></span>
<?php else: ?>
							<?=number_format($port['out_discards'])?>
<?php endif; ?>
						</td>
						<td style="white-space:nowrap;">
<?php
	$cur_assign = $assignments[$port['ifindex']] ?? null;
	$cur_pid = ($cur_assign && $cur_assign['sync_status'] != 'removing') ? $cur_assign['profile_id'] : '';
?>
							<select name="profile[<?=$port['ifindex']?>]" class="form-control input-sm" style="height:24px;padding:1px 4px;font-size:12px;display:inline-block;width:auto;">
								<option value="">--</option>
<?php foreach ($profiles as $p): ?>
								<option value="<?=$p['id']?>" <?=$cur_pid == $p['id'] ? 'selected' : ''?>><?=htmlspecialchars($p['name'])?></option>
<?php endforeach; ?>
							</select>
<?php
	$pa = $assignments[$port['ifindex']] ?? null;
	if ($pa) {
		$ss = $pa['sync_status'];
		if ($ss == 'synced') {
			echo '<i class="fa-solid fa-check text-success" title="' . gettext("Synced") . ' ' . date('Y-m-d H:i', $pa['last_synced']) . '"></i>';
		} elseif ($ss == 'failed') {
			echo '<i class="fa-solid fa-times text-danger" title="' . gettext("Push failed") . '"></i>';
		} elseif ($ss == 'drift') {
			echo '<i class="fa-solid fa-exclamation-triangle text-warning" title="' . gettext("Config drift detected") . '"></i>';
		} elseif ($ss == 'removing') {
			echo '<i class="fa-solid fa-undo text-warning" title="' . gettext("Will be reset to default on next push") . '"></i>';
		} else {
			echo '<i class="fa-solid fa-clock-o text-muted" title="' . gettext("Pending - not yet pushed") . '"></i>';
		}
	}
?>
						</td>
					</tr>
<?php endforeach; ?>
<?php endif; ?>
				</tbody>
			</table>
		</div>
		<div style="margin-top:8px; margin-bottom:8px; text-align:right;">
			<label style="margin-right:4px;"><?=gettext("Apply to all ports:")?></label>
			<select id="bulk_profile" class="form-control input-sm" style="width:auto;display:inline-block;height:24px;padding:1px 4px;font-size:12px;">
				<option value="">--</option>
<?php foreach ($profiles as $p): ?>
				<option value="<?=$p['id']?>"><?=htmlspecialchars($p['name'])?></option>
<?php endforeach; ?>
			</select>
			<button type="button" class="btn btn-default btn-xs" onclick="$('select[name^=profile]').val($('#bulk_profile').val());">
				<i class="fa-solid fa-check icon-embed-btn"></i><?=gettext("Apply")?>
			</button>
		</div>
		<nav class="action-buttons">
			<button class="btn btn-primary btn-sm" type="submit" name="assign_profile" value="1">
				<i class="fa-solid fa-save icon-embed-btn"></i>
				<?=gettext("Save Assignments")?>
			</button>
			<button type="button" class="btn btn-default btn-sm" onclick="window.location.reload();">
				<i class="fa-solid fa-undo icon-embed-btn"></i>
				<?=gettext("Reset Assignments")?>
			</button>
			<a class="btn btn-info btn-sm" href="preview_switchmgmt.php?switch=<?=urlencode($selected_switch)?>">
				<i class="fa-solid fa-eye icon-embed-btn"></i>
				<?=gettext("Preview Config")?>
			</a>
		</nav>
		</form>
	</div>
</div>

<?php endif; ?>

<!-- Poll All button -->
<div>
	<form action="status_switchmgmt.php" method="post">
		<input type="hidden" name="switch" value="<?=htmlspecialchars($selected_switch)?>"/>
		<nav class="action-buttons">
			<button class="btn btn-primary btn-sm" type="submit" name="pollnow" value="1">
				<i class="fa-solid fa-refresh icon-embed-btn"></i>
				<?=gettext("Poll All Switches Now")?>
			</button>
		</nav>
	</form>
</div>

<?php
include("foot.inc");
