<?php
/*
 * neighbors_switchmgmt.php
 *
 * Neighbor discovery page for Switch Management package.
 * Shows LLDP/CDP neighbor relationships and topology graph.
 *
 * Licensed under the Apache License, Version 2.0
 */

##|+PRIV
##|*IDENT=page-status-switchmgmt-neighbors
##|*NAME=Status: Switch Management Neighbors
##|*DESCR=Allow access to the 'Status: Switch Management Neighbors' page.
##|*MATCH=neighbors_switchmgmt.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("/usr/local/pkg/switchmgmt.inc");

$pgtitle = array(gettext("Status"), gettext("Switch Management"), gettext("Neighbors"));

include("head.inc");

/* Tabs */
$tab_array = array();
$tab_array[] = array(gettext("Settings"), false, "/pkg_edit.php?xml=switchmgmt_settings.xml&id=0");
$tab_array[] = array(gettext("Switch Configuration"), false, "/pkg.php?xml=switchmgmt.xml");
$tab_array[] = array(gettext("Switch Port Profiles"), false, "/profiles_switchmgmt.php");
$tab_array[] = array(gettext("Switch & Port Status"), false, "/status_switchmgmt.php");
$tab_array[] = array(gettext("Neighbors"), true, "/neighbors_switchmgmt.php");
display_top_tabs($tab_array);

$all_neighbors = switchmgmt_get_all_neighbors();
$all_switches = switchmgmt_get_switch_status();

// Build sets for topology
$managed_ips = array();
foreach ($all_switches as $sw) {
	$managed_ips[$sw['ipaddr']] = $sw;
}
?>

<!-- Topology Graph -->
<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title"><?=gettext("Network Topology")?></h2>
	</div>
	<div class="panel-body">
		<div id="topology" style="width:100%; height:500px; border:1px solid #ddd;"></div>
		<div style="margin:15px 15px;">
			<span style="display:inline-block; width:14px; height:14px; background:#5cb85c; border:2px solid #4cae4c; vertical-align:middle; margin-right:4px;"></span> <?=gettext("Managed Switch")?>
			&nbsp;&nbsp;
			<span style="display:inline-block; width:14px; height:14px; background:#5bc0de; border:2px solid #46b8da; border-radius:50%; vertical-align:middle; margin-right:4px;"></span> <?=gettext("External Neighbor")?>
			&nbsp;&nbsp;
			<span style="display:inline-block; width:20px; height:3px; background:#5cb85c; vertical-align:middle; margin-right:4px;"></span> <?=gettext("LLDP")?>
			&nbsp;&nbsp;
			<span style="display:inline-block; width:20px; height:3px; background:#337ab7; vertical-align:middle; margin-right:4px;"></span> <?=gettext("CDP")?>
		</div>
	</div>
</div>

<!-- Neighbor Table -->
<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title"><?=gettext("Neighbor Relationships")?></h2>
	</div>
	<div class="panel-body">
		<div class="table-responsive">
			<table class="table table-striped table-hover table-condensed sortable-theme-bootstrap" data-sortable>
				<thead>
					<tr>
						<th><?=gettext("Local Switch")?></th>
						<th><?=gettext("Local Port")?></th>
						<th><?=gettext("Remote Device")?></th>
						<th><?=gettext("Remote Port")?></th>
						<th><?=gettext("Remote IP")?></th>
						<th><?=gettext("Protocol")?></th>
					</tr>
				</thead>
				<tbody>
<?php if (empty($all_neighbors)): ?>
					<tr>
						<td colspan="6"><?=gettext("No neighbors discovered. Ensure LLDP is enabled on your switches.")?></td>
					</tr>
<?php else: ?>
<?php foreach ($all_neighbors as $n):
	$local_name = $n['sysname'] ?: $n['switch_ip'];
	$local_port = $n['local_ifdescr'] ? switchmgmt_format_port_name($n['local_ifdescr']) : $n['local_port'];
	$remote_name = $n['remote_sysname'] ?: $n['remote_mgmtaddr'] ?: $n['remote_chassisid'];
	$remote_port = $n['remote_port'] ? switchmgmt_format_port_name($n['remote_port']) : '-';
	$proto_class = ($n['protocol'] == 'cdp') ? 'primary' : 'info';
?>
					<tr>
						<td><?=htmlspecialchars($local_name)?></td>
						<td><?=htmlspecialchars($local_port)?></td>
						<td><?=htmlspecialchars($remote_name)?></td>
						<td><?=htmlspecialchars($remote_port)?></td>
						<td><?=htmlspecialchars($n['remote_mgmtaddr'] ?: '-')?></td>
						<td><span class="label label-<?=$proto_class?>"><?=strtoupper($n['protocol'])?></span></td>
					</tr>
<?php endforeach; ?>
<?php endif; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>

<script src="https://unpkg.com/vis-network/standalone/umd/vis-network.min.js"></script>
<script>
(function() {
	// Build topology data from PHP
	var managed = <?=json_encode($managed_ips)?>;
	var neighbors = <?=json_encode($all_neighbors)?>;

	var nodeMap = {};
	var nodeId = 1;
	var nodes = [];
	var edges = [];

	function getNodeId(name, ip) {
		var key = ip || name;
		if (!key) return null;
		if (!nodeMap[key]) {
			var isManaged = ip && managed[ip];
			var label = name || ip || '?';
			// Shorten long names
			if (label.length > 25) {
				label = label.substring(0, 22) + '...';
			}
			var node = {
				id: nodeId,
				label: label,
				shape: isManaged ? 'box' : 'ellipse',
				color: isManaged ? {background: '#5cb85c', border: '#4cae4c'} : {background: '#5bc0de', border: '#46b8da'},
				font: {color: '#fff', face: 'arial', size: 12},
				borderWidth: 2,
				shadow: true
			};
			if (isManaged && managed[ip].model) {
				node.title = managed[ip].model + ' (' + ip + ')';
			} else if (ip) {
				node.title = ip;
			}
			nodes.push(node);
			nodeMap[key] = nodeId++;
		}
		return nodeMap[key];
	}

	// Create nodes for all managed switches first
	for (var ip in managed) {
		var sw = managed[ip];
		getNodeId(sw.sysname || sw.model || ip, ip);
	}

	// Create edges from neighbor data
	var edgeSet = {};
	neighbors.forEach(function(n) {
		var localName = n.sysname || n.switch_ip;
		var localId = getNodeId(localName, n.switch_ip);

		var remoteName = n.remote_sysname || n.remote_mgmtaddr || n.remote_chassisid;
		var remoteIp = n.remote_mgmtaddr || null;
		var remoteId = getNodeId(remoteName, remoteIp);

		if (!localId || !remoteId || localId === remoteId) return;

		// Deduplicate edges (A->B and B->A)
		var edgeKey = Math.min(localId, remoteId) + '-' + Math.max(localId, remoteId);
		if (edgeSet[edgeKey]) return;
		edgeSet[edgeKey] = true;

		var localPort = n.local_ifdescr || n.local_port;
		var remotePort = n.remote_port || '';
		// Format port names
		var lpMatch = String(localPort).match(/(\d+(?:\/\d+)+)/);
		var rpMatch = String(remotePort).match(/unit\s+(\d+),\s*port\s+(\d+)/i) ||
		              String(remotePort).match(/(\d+(?:\/\d+)+)/);
		var lpLabel = lpMatch ? lpMatch[1] : String(localPort);
		var rpLabel = rpMatch ? (rpMatch[2] ? rpMatch[1]+'/'+rpMatch[2] : rpMatch[1]) : String(remotePort);

		var proto = (n.protocol || 'lldp').toUpperCase();
		edges.push({
			from: localId,
			to: remoteId,
			label: lpLabel + ' \u2194 ' + rpLabel,
			title: proto + ': ' + localName + ' [' + lpLabel + '] \u2194 ' + remoteName + ' [' + rpLabel + ']',
			color: {color: proto === 'CDP' ? '#337ab7' : '#5cb85c'},
			font: {size: 10, align: 'middle'},
			width: 2,
			smooth: {type: 'continuous'}
		});
	});

	// Render
	var container = document.getElementById('topology');
	if (typeof vis !== 'undefined' && nodes.length > 0) {
		var data = {nodes: new vis.DataSet(nodes), edges: new vis.DataSet(edges)};
		var options = {
			physics: false,
			interaction: {hover: true, tooltipDelay: 200},
			layout: {
				hierarchical: {
					enabled: true,
					direction: 'LR',
					sortMethod: 'directed',
					levelSeparation: 250,
					nodeSpacing: 120
				}
			}
		};
		new vis.Network(container, data, options);
	} else if (nodes.length === 0) {
		container.innerHTML = '<p class="text-center text-muted" style="padding-top:200px">No topology data available.</p>';
	} else {
		container.innerHTML = '<p class="text-center text-muted" style="padding-top:200px">Could not load topology visualization library.</p>';
	}
})();
</script>

<?php
include("foot.inc");
