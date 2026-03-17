<?php
/*
 * profiles_switchmgmt.php
 *
 * Port profile management for Switch Management package.
 *
 * Licensed under the Apache License, Version 2.0
 */

##|+PRIV
##|*IDENT=page-services-switchmgmt-profiles
##|*NAME=Services: Switch Management Profiles
##|*DESCR=Allow access to the 'Services: Switch Management Profiles' page.
##|*MATCH=profiles_switchmgmt.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("/usr/local/pkg/switchmgmt.inc");

$pgtitle = array(gettext("Services"), gettext("Switch Management"), gettext("Profiles"));

$act = $_REQUEST['act'] ?? '';
$id = $_REQUEST['id'] ?? '';

$input_errors = array();
$savemsg = '';

/* Handle POST actions */
if ($_POST) {
	if ($_POST['act'] == 'delete' && !empty($_POST['id'])) {
		$usage = switchmgmt_assignment_get_usage_count($_POST['id']);
		if ($usage > 0) {
			$input_errors[] = sprintf(gettext("Cannot delete: profile is assigned to %d port(s). Remove assignments first."), $usage);
		} else {
			switchmgmt_profile_delete($_POST['id']);
			$savemsg = gettext("Profile deleted.");
		}
		$act = '';
	} elseif ($_POST['save']) {
		$pconfig = $_POST;
		switchmgmt_profile_validate($pconfig, $input_errors);

		if (empty($input_errors)) {
			$result = switchmgmt_profile_save($pconfig);
			if ($result) {
				$savemsg = gettext("Profile saved.");
				$act = '';
			} else {
				$input_errors[] = gettext("Failed to save profile. The name may already be in use.");
			}
		} else {
			$act = !empty($_POST['id']) ? 'edit' : 'new';
		}
	}
}

/* Load data for edit mode */
if ($act == 'edit' && !empty($id)) {
	$pconfig = switchmgmt_profile_get($id);
	if (!$pconfig) {
		$input_errors[] = gettext("Profile not found.");
		$act = '';
	}
} elseif ($act == 'new') {
	$pconfig = array(
		'name' => '', 'description' => '', 'vlan_mode' => 'access',
		'access_vlan' => 1, 'trunk_allowed' => '', 'trunk_native' => 1,
		'poe_enabled' => 0, 'speed' => 'auto', 'duplex' => 'auto', 'enabled' => 1,
	);
}

include("head.inc");

/* Tabs */
$tab_array = array();
$tab_array[] = array(gettext("Settings"), false, "/pkg_edit.php?xml=switchmgmt_settings.xml&id=0");
$tab_array[] = array(gettext("Switches"), false, "/pkg.php?xml=switchmgmt.xml");
$tab_array[] = array(gettext("Switch Status"), false, "/status_switchmgmt.php");
$tab_array[] = array(gettext("Switch Port Profiles"), true, "/profiles_switchmgmt.php");
$tab_array[] = array(gettext("Neighbors"), false, "/neighbors_switchmgmt.php");
display_top_tabs($tab_array);

if ($input_errors) {
	print_input_errors($input_errors);
}
if ($savemsg) {
	print_info_box($savemsg, 'success');
}

if ($act == 'edit' || $act == 'new'):
/* ---- Edit/New Form ---- */
$form = new Form();
$section = new Form_Section(empty($pconfig['id']) ? gettext('New Profile') : gettext('Edit Profile'));

$section->addInput(new Form_Input('name', '*' . gettext('Name'), 'text', $pconfig['name'])
)->setHelp(gettext('Unique profile name (e.g. "Access VLAN 10", "Uplink Trunk").'));

$section->addInput(new Form_Input('description', gettext('Description'), 'text', $pconfig['description'] ?? '')
)->setHelp(gettext('Optional description.'));

$section->addInput(new Form_Select('vlan_mode', '*' . gettext('VLAN Mode'), $pconfig['vlan_mode'],
	array('access' => gettext('Access'), 'trunk' => gettext('Trunk'), 'hybrid' => gettext('Hybrid'))
))->setHelp(gettext('Access: single VLAN. Trunk: tagged VLANs. Hybrid: native untagged + tagged.'));

$section->addInput(new Form_Input('access_vlan', gettext('Access VLAN'), 'number', $pconfig['access_vlan'])
)->setHelp(gettext('VLAN ID for access mode (1-4094).'));

$section->addInput(new Form_Input('trunk_allowed', gettext('Allowed VLANs'), 'text', $pconfig['trunk_allowed'] ?? '')
)->setHelp(gettext('Comma-separated VLAN IDs or ranges (e.g. "10,20,100-200"). Empty = all.'));

$section->addInput(new Form_Input('trunk_native', gettext('Native VLAN'), 'number', $pconfig['trunk_native'])
)->setHelp(gettext('Native (untagged) VLAN for trunk mode (1-4094).'));

$group = new Form_Group(gettext('Port Settings'));
$group->add(new Form_Select('speed', gettext('Speed'), $pconfig['speed'],
	array('auto' => 'Auto', '10' => '10 Mbps', '100' => '100 Mbps', '1000' => '1 Gbps',
		'2500' => '2.5 Gbps', '10000' => '10 Gbps', '25000' => '25 Gbps')
));
$group->add(new Form_Select('duplex', gettext('Duplex'), $pconfig['duplex'],
	array('auto' => 'Auto', 'full' => 'Full', 'half' => 'Half')
));
$section->add($group);

$section->addInput(new Form_Checkbox('poe_enabled', gettext('PoE'), gettext('Enable Power over Ethernet'),
	!empty($pconfig['poe_enabled']) ? 'on' : ''
))->setValue('on');

$section->addInput(new Form_Checkbox('enabled', gettext('Port Enabled'), gettext('Enable the port (admin up)'),
	!empty($pconfig['enabled']) ? 'on' : ''
))->setValue('on');

if (!empty($pconfig['id'])) {
	$form->addGlobal(new Form_Input('id', '', 'hidden', $pconfig['id']));
}
$form->addGlobal(new Form_Input('act', '', 'hidden', !empty($pconfig['id']) ? 'edit' : 'new'));

$form->add($section);
print($form);

?>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	function updateVlanFields() {
		var mode = $('#vlan_mode').val();
		hideInput('access_vlan', (mode != 'access' && mode != 'hybrid'));
		hideInput('trunk_allowed', (mode != 'trunk' && mode != 'hybrid'));
		hideInput('trunk_native', (mode != 'trunk' && mode != 'hybrid'));
	}
	$('#vlan_mode').on('change', updateVlanFields);
	updateVlanFields();
});
//]]>
</script>

<?php
else:
/* ---- List View ---- */
$profiles = switchmgmt_profile_get_all();
?>

<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title"><?=gettext("Port Profiles")?></h2>
	</div>
	<div class="panel-body">
		<div class="table-responsive">
			<table class="table table-striped table-hover table-condensed sortable-theme-bootstrap" data-sortable>
				<thead>
					<tr>
						<th><?=gettext("Name")?></th>
						<th><?=gettext("VLAN Mode")?></th>
						<th><?=gettext("VLAN")?></th>
						<th><?=gettext("Speed")?></th>
						<th><?=gettext("PoE")?></th>
						<th><?=gettext("Enabled")?></th>
						<th><?=gettext("Ports")?></th>
						<th><?=gettext("Actions")?></th>
					</tr>
				</thead>
				<tbody>
<?php if (empty($profiles)): ?>
					<tr>
						<td colspan="8"><?=gettext("No profiles defined. Create one to get started.")?></td>
					</tr>
<?php else: ?>
<?php foreach ($profiles as $p):
	$usage = switchmgmt_assignment_get_usage_count($p['id']);
	$vlan_info = '';
	if ($p['vlan_mode'] == 'access') {
		$vlan_info = "Access: {$p['access_vlan']}";
	} elseif ($p['vlan_mode'] == 'trunk') {
		$vlan_info = "Native: {$p['trunk_native']}";
		if (!empty($p['trunk_allowed'])) {
			$vlan_info .= ", Allowed: {$p['trunk_allowed']}";
		}
	} elseif ($p['vlan_mode'] == 'hybrid') {
		$vlan_info = "Native: {$p['access_vlan']}";
		if (!empty($p['trunk_allowed'])) {
			$vlan_info .= ", Tagged: {$p['trunk_allowed']}";
		}
	}
?>
					<tr>
						<td><strong><?=htmlspecialchars($p['name'])?></strong>
<?php if (!empty($p['description'])): ?>
							<br/><small class="text-muted"><?=htmlspecialchars($p['description'])?></small>
<?php endif; ?>
						</td>
						<td><span class="label label-<?=$p['vlan_mode'] == 'trunk' ? 'primary' : ($p['vlan_mode'] == 'hybrid' ? 'warning' : 'default')?>"><?=ucfirst($p['vlan_mode'])?></span></td>
						<td><?=htmlspecialchars($vlan_info)?></td>
						<td><?=$p['speed'] == 'auto' ? 'Auto' : htmlspecialchars($p['speed'])?><?=$p['duplex'] != 'auto' ? '/' . $p['duplex'] : ''?></td>
						<td><?=$p['poe_enabled'] ? '<span class="label label-success">On</span>' : '-'?></td>
						<td><?=$p['enabled'] ? '<span class="label label-success">Yes</span>' : '<span class="label label-danger">No</span>'?></td>
						<td><?=$usage > 0 ? "<span class=\"badge\">{$usage}</span>" : '-'?></td>
						<td>
							<a class="btn btn-xs btn-primary" href="?act=edit&id=<?=$p['id']?>" title="<?=gettext('Edit')?>">
								<i class="fa-solid fa-pencil"></i>
							</a>
							<form action="profiles_switchmgmt.php" method="post" style="display:inline"
								onsubmit="return confirm('<?=gettext("Delete this profile?")?>');">
								<input type="hidden" name="act" value="delete"/>
								<input type="hidden" name="id" value="<?=$p['id']?>"/>
								<button class="btn btn-xs btn-danger" type="submit" title="<?=gettext('Delete')?>">
									<i class="fa-solid fa-trash"></i>
								</button>
							</form>
						</td>
					</tr>
<?php endforeach; ?>
<?php endif; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>

<nav class="action-buttons">
	<a href="?act=new" class="btn btn-sm btn-success">
		<i class="fa-solid fa-plus icon-embed-btn"></i>
		<?=gettext("Add Profile")?>
	</a>
</nav>

<?php endif; ?>

<?php
include("foot.inc");
