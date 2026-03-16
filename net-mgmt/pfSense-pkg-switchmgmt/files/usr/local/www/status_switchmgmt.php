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
}

/* Tabs */
$tab_array = array();
$tab_array[] = array(gettext("Settings"), false, "/pkg_edit.php?xml=switchmgmt_settings.xml&id=0");
$tab_array[] = array(gettext("Switches"), false, "/pkg.php?xml=switchmgmt.xml");
$tab_array[] = array(gettext("Switch Status"), true, "/status_switchmgmt.php");
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
						<th><?=gettext("Description")?></th>
						<th><?=gettext("Uptime")?></th>
						<th><?=gettext("Status")?></th>
						<th><?=gettext("Last Polled")?></th>
						<th><?=gettext("Actions")?></th>
					</tr>
				</thead>
				<tbody>
<?php if (empty($switch_list)): ?>
					<tr>
						<td colspan="7"><?=gettext("No switches have been polled yet. Add switches and enable polling in Settings.")?></td>
					</tr>
<?php else: ?>
<?php foreach ($switch_list as $sw): ?>
					<tr>
						<td>
							<a href="?switch=<?=urlencode($sw['ipaddr'])?>"><?=htmlspecialchars($sw['ipaddr'])?></a>
						</td>
						<td><?=htmlspecialchars($sw['sysname'] ?? '-')?></td>
						<td><?=htmlspecialchars(substr($sw['sysdescr'] ?? '-', 0, 80))?></td>
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
		<div class="table-responsive">
			<table class="table table-striped table-hover table-condensed sortable-theme-bootstrap" data-sortable>
				<thead>
					<tr>
						<th><?=gettext("Description")?></th>
						<th><?=gettext("Alias")?></th>
						<th><?=gettext("Capability")?></th>
						<th><?=gettext("Speed")?></th>
						<th><?=gettext("Admin")?></th>
						<th><?=gettext("Oper")?></th>
						<th><?=gettext("In Octets")?></th>
						<th><?=gettext("Out Octets")?></th>
						<th><?=gettext("In Pkts")?></th>
						<th><?=gettext("Out Pkts")?></th>
						<th><?=gettext("In Err")?></th>
						<th><?=gettext("Out Err")?></th>
						<th><?=gettext("In Disc")?></th>
						<th><?=gettext("Out Disc")?></th>
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
						<td><?=htmlspecialchars($port['ifdescr'] ?: '-')?></td>
						<td><?=htmlspecialchars($port['ifalias'] ?: '-')?></td>
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
					</tr>
<?php endforeach; ?>
<?php endif; ?>
				</tbody>
			</table>
		</div>
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
