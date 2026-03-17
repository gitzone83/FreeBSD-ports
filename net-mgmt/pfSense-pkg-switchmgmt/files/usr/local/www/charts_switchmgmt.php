<?php
/*
 * charts_switchmgmt.php
 *
 * Port traffic and error charts for Switch Management package.
 * Uses rrd_fetch() for data and Chart.js for rendering.
 *
 * Licensed under the Apache License, Version 2.0
 */

##|+PRIV
##|*IDENT=page-status-switchmgmt-charts
##|*NAME=Status: Switch Management Charts
##|*DESCR=Allow access to the 'Status: Switch Management Charts' page.
##|*MATCH=charts_switchmgmt.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("/usr/local/pkg/switchmgmt.inc");

$pgtitle = array(gettext("Status"), gettext("Switch Management"), gettext("Switch Port Charts"));

// Handle JSON data request
if (!empty($_GET['data'])) {
	header('Content-Type: application/json');
	$switch = $_GET['switch'] ?? '';
	$port = (int)($_GET['port'] ?? 0);
	$period = $_GET['period'] ?? '1d';
	$type = $_GET['type'] ?? 'traffic';

	if (empty($switch) || empty($port)) {
		echo json_encode(array('error' => 'Missing parameters'));
		exit;
	}

	$safe_ip = str_replace('.', '_', $switch);
	$rrdfile = SWITCHMGMT_RRD_PATH . "switchmgmt_{$safe_ip}_if{$port}.rrd";

	if (!file_exists($rrdfile)) {
		echo json_encode(array('error' => 'No RRD data'));
		exit;
	}

	$period_map = array('4h' => '-4h', '1d' => '-1d', '1w' => '-1w', '1m' => '-1m', '1y' => '-1y');
	$start = $period_map[$period] ?? '-1d';

	$result = @rrd_fetch($rrdfile, array('AVERAGE', '--start', $start));
	if ($result === false) {
		echo json_encode(array('error' => rrd_error()));
		exit;
	}

	$labels = array();
	$datasets = array();

	// Map data source names to chart series
	$ds_map = array(
		'traffic' => array('inoctets' => 'Inbound', 'outoctets' => 'Outbound'),
		'packets' => array('inpkts' => 'Inbound', 'outpkts' => 'Outbound'),
		'errors'  => array('inerrors' => 'In Errors', 'outerrors' => 'Out Errors',
		                   'indiscards' => 'In Discards', 'outdiscards' => 'Out Discards'),
	);

	$series_names = $ds_map[$type] ?? $ds_map['traffic'];
	$series_data = array();
	foreach ($series_names as $ds => $label) {
		$series_data[$ds] = array('label' => $label, 'data' => array());
	}

	$step = $result['step'];
	$time = $result['start'];
	foreach ($result['data'] as $ds_name => $values) {
		if (!isset($series_names[$ds_name])) continue;
		$t = $time;
		foreach ($values as $val) {
			if (!in_array($t, $labels)) {
				$labels[] = $t;
			}
			$series_data[$ds_name]['data'][] = is_nan($val) ? null : round($val, 2);
			$t += $step;
		}
	}

	// Build time labels
	if (empty($labels)) {
		$t = $time;
		$first_ds = array_key_first($result['data']);
		if ($first_ds) {
			foreach ($result['data'][$first_ds] as $val) {
				$labels[] = $t;
				$t += $step;
			}
		}
	}

	echo json_encode(array(
		'labels' => $labels,
		'series' => array_values($series_data),
		'step' => $step,
	));
	exit;
}

$selected_switch = $_GET['switch'] ?? '';
$selected_port = $_GET['port'] ?? '';
$selected_period = $_GET['period'] ?? '1d';

$periods = array(
	'4h'  => gettext('4 Hours'),
	'1d'  => gettext('1 Day'),
	'1w'  => gettext('1 Week'),
	'1m'  => gettext('1 Month'),
	'1y'  => gettext('1 Year'),
);

include("head.inc");

/* Tabs */
$tab_array = array();
$tab_array[] = array(gettext("Settings"), false, "/pkg_edit.php?xml=switchmgmt_settings.xml&id=0");
$tab_array[] = array(gettext("Switch Configuration"), false, "/pkg.php?xml=switchmgmt.xml");
$tab_array[] = array(gettext("Switch Port Profiles"), false, "/profiles_switchmgmt.php");
$tab_array[] = array(gettext("Switch & Port Status"), false, "/status_switchmgmt.php");
$tab_array[] = array(gettext("Switch Port Charts"), true, "/charts_switchmgmt.php");
$tab_array[] = array(gettext("Neighbors"), false, "/neighbors_switchmgmt.php");
display_top_tabs($tab_array);

$all_switches = switchmgmt_get_switch_status();
$ports = array();
if (!empty($selected_switch)) {
	$all_ports = switchmgmt_get_port_status($selected_switch);
	// Filter to ports that have seen traffic
	foreach ($all_ports as $p) {
		if (($p['in_octets'] > 0 || $p['out_octets'] > 0) || ($p['in_ucast_pkts'] > 0 || $p['out_ucast_pkts'] > 0)) {
			$ports[] = $p;
		}
	}
}

$sw_desc = '';
foreach (switchmgmt_get_switches() as $sc) {
	if ($sc['ipaddr'] == $selected_switch) {
		$sw_desc = $sc['description'];
		break;
	}
}
?>

<!-- Switch/Port Selector -->
<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title"><?=gettext("Select Port to Graph")?></h2>
	</div>
	<div class="panel-body">
		<form method="get" action="charts_switchmgmt.php" id="chartform">
			<div class="form-inline">
				<div class="form-group" style="margin-right:10px;">
					<label style="margin-right:5px;"><?=gettext("Switch")?></label>
					<select name="switch" class="form-control input-sm" onchange="if(this.form.port)this.form.port.value='';this.form.submit();">
						<option value="">-- <?=gettext("Select Switch")?> --</option>
<?php foreach ($all_switches as $sw): ?>
						<option value="<?=htmlspecialchars($sw['ipaddr'])?>" <?=$sw['ipaddr'] == $selected_switch ? 'selected' : ''?>>
							<?=htmlspecialchars(($sw['model'] ?? $sw['ipaddr']) . ' (' . $sw['ipaddr'] . ')')?>
						</option>
<?php endforeach; ?>
					</select>
				</div>
<?php if (!empty($selected_switch) && !empty($ports)): ?>
				<div class="form-group" style="margin-right:10px;">
					<label style="margin-right:5px;"><?=gettext("Port")?></label>
					<select name="port" class="form-control input-sm" onchange="this.form.submit();">
						<option value="">-- <?=gettext("Select Port")?> --</option>
<?php foreach ($ports as $p): ?>
						<option value="<?=$p['ifindex']?>" <?=$p['ifindex'] == $selected_port ? 'selected' : ''?>>
							<?=htmlspecialchars(switchmgmt_format_port_name($p['ifdescr']))?> (<?=switchmgmt_format_speed($p['ifspeed'], $p['ifmaxspeed'] ?? $p['ifhighspeed'])?>)
						</option>
<?php endforeach; ?>
					</select>
				</div>
<?php endif; ?>
				<div class="form-group" style="margin-right:10px;">
					<label style="margin-right:5px;"><?=gettext("Period")?></label>
					<select name="period" class="form-control input-sm" onchange="this.form.submit();">
<?php foreach ($periods as $val => $label): ?>
						<option value="<?=$val?>" <?=$val == $selected_period ? 'selected' : ''?>><?=$label?></option>
<?php endforeach; ?>
					</select>
				</div>
			</div>
		</form>
	</div>
</div>

<?php if (!empty($selected_switch) && !empty($selected_port)):
	$safe_ip = str_replace('.', '_', $selected_switch);
	$rrdfile = SWITCHMGMT_RRD_PATH . "switchmgmt_{$safe_ip}_if{$selected_port}.rrd";
	$port_name = $selected_port;
	foreach ($ports as $p) {
		if ($p['ifindex'] == $selected_port) {
			$port_name = switchmgmt_format_port_name($p['ifdescr']);
			break;
		}
	}

	if (!file_exists($rrdfile)):
?>
<div class="alert alert-warning"><?=sprintf(gettext("No RRD data available for port %s."), htmlspecialchars($port_name))?></div>
<?php else:
	$chart_title = htmlspecialchars(($sw_desc ?: $selected_switch) . ' — Port ' . $port_name);
?>

<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=sprintf(gettext("Traffic: %s"), $chart_title)?></h2></div>
	<div class="panel-body">
		<canvas id="chart_traffic" height="80"></canvas>
		<div style="margin:10px 15px;text-align:right;">
			<a href="#" onclick="exportChart('chart_traffic','traffic','png');return false;" style="display:inline-block;padding:4px 10px;background:#5bc0de;color:#fff;border-radius:4px;text-decoration:none;font-size:11px;margin-left:4px;"><i class="fa-solid fa-download"></i> PNG</a>
			<a href="#" onclick="exportChart('chart_traffic','traffic','jpg');return false;" style="display:inline-block;padding:4px 10px;background:#5bc0de;color:#fff;border-radius:4px;text-decoration:none;font-size:11px;margin-left:4px;"><i class="fa-solid fa-download"></i> JPG</a>
			<a href="#" onclick="exportChart('chart_traffic','traffic','pdf');return false;" style="display:inline-block;padding:4px 10px;background:#5bc0de;color:#fff;border-radius:4px;text-decoration:none;font-size:11px;margin-left:4px;"><i class="fa-solid fa-download"></i> PDF</a>
		</div>
	</div>
</div>

<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=sprintf(gettext("Packets: %s"), $chart_title)?></h2></div>
	<div class="panel-body">
		<canvas id="chart_packets" height="80"></canvas>
		<div style="margin:10px 15px;text-align:right;">
			<a href="#" onclick="exportChart('chart_packets','packets','png');return false;" style="display:inline-block;padding:4px 10px;background:#5bc0de;color:#fff;border-radius:4px;text-decoration:none;font-size:11px;margin-left:4px;"><i class="fa-solid fa-download"></i> PNG</a>
			<a href="#" onclick="exportChart('chart_packets','packets','jpg');return false;" style="display:inline-block;padding:4px 10px;background:#5bc0de;color:#fff;border-radius:4px;text-decoration:none;font-size:11px;margin-left:4px;"><i class="fa-solid fa-download"></i> JPG</a>
			<a href="#" onclick="exportChart('chart_packets','packets','pdf');return false;" style="display:inline-block;padding:4px 10px;background:#5bc0de;color:#fff;border-radius:4px;text-decoration:none;font-size:11px;margin-left:4px;"><i class="fa-solid fa-download"></i> PDF</a>
		</div>
	</div>
</div>

<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=sprintf(gettext("Errors & Discards: %s"), $chart_title)?></h2></div>
	<div class="panel-body">
		<canvas id="chart_errors" height="80"></canvas>
		<div style="margin:10px 15px;text-align:right;">
			<a href="#" onclick="exportChart('chart_errors','errors','png');return false;" style="display:inline-block;padding:4px 10px;background:#5bc0de;color:#fff;border-radius:4px;text-decoration:none;font-size:11px;margin-left:4px;"><i class="fa-solid fa-download"></i> PNG</a>
			<a href="#" onclick="exportChart('chart_errors','errors','jpg');return false;" style="display:inline-block;padding:4px 10px;background:#5bc0de;color:#fff;border-radius:4px;text-decoration:none;font-size:11px;margin-left:4px;"><i class="fa-solid fa-download"></i> JPG</a>
			<a href="#" onclick="exportChart('chart_errors','errors','pdf');return false;" style="display:inline-block;padding:4px 10px;background:#5bc0de;color:#fff;border-radius:4px;text-decoration:none;font-size:11px;margin-left:4px;"><i class="fa-solid fa-download"></i> PDF</a>
		</div>
	</div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
var colors = {
	traffic: [{bg:'rgba(115,210,22,0.3)',border:'#4e9a06'},{bg:'rgba(52,101,164,0.3)',border:'#3465a4'}],
	packets: [{bg:'rgba(115,210,22,0.3)',border:'#4e9a06'},{bg:'rgba(52,101,164,0.3)',border:'#3465a4'}],
	errors: [{bg:'rgba(204,0,0,0.2)',border:'#cc0000'},{bg:'rgba(239,41,41,0.2)',border:'#ef2929'},
	         {bg:'rgba(245,121,0,0.2)',border:'#f57900'},{bg:'rgba(252,175,62,0.2)',border:'#fcaf3e'}]
};

function fmtTime(ts) {
	var d = new Date(ts * 1000);
	var h = ('0'+d.getHours()).slice(-2);
	var m = ('0'+d.getMinutes()).slice(-2);
	var mo = ('0'+(d.getMonth()+1)).slice(-2);
	var dy = ('0'+d.getDate()).slice(-2);
	return mo+'/'+dy+' '+h+':'+m;
}

function loadChart(type, canvasId) {
	var url = 'charts_switchmgmt.php?data=1&switch=<?=urlencode($selected_switch)?>&port=<?=urlencode($selected_port)?>&period=<?=urlencode($selected_period)?>&type=' + type;
	fetch(url).then(function(r){return r.json();}).then(function(d) {
		if (d.error) { document.getElementById(canvasId).parentNode.innerHTML = '<p class="text-muted">'+d.error+'</p>'; return; }
		var labels = d.labels.map(fmtTime);
		var datasets = d.series.map(function(s, i) {
			var c = (colors[type] || colors.traffic)[i] || colors.traffic[0];
			return {
				label: s.label,
				data: s.data,
				borderColor: c.border,
				backgroundColor: c.bg,
				fill: (type!='errors'),
				borderWidth: type=='errors' ? 2 : 1,
				pointRadius: 0,
				tension: 0.2
			};
		});
		new Chart(document.getElementById(canvasId), {
			type: 'line',
			data: {labels: labels, datasets: datasets},
			options: {
				responsive: true,
				interaction: {mode:'index',intersect:false},
				scales: {
					x: {ticks:{maxTicksLimit:12,maxRotation:45}},
					y: {beginAtZero:true, ticks:{callback:function(v){
						if(type=='traffic'){
							if(v>=1e9)return(v/1e9).toFixed(1)+'GB/s';
							if(v>=1e6)return(v/1e6).toFixed(1)+'MB/s';
							if(v>=1e3)return(v/1e3).toFixed(1)+'KB/s';
							return v+'B/s';
						}
						if(v>=1e6)return(v/1e6).toFixed(1)+'M';
						if(v>=1e3)return(v/1e3).toFixed(1)+'K';
						return v;
					}}}
				},
				plugins:{legend:{position:'bottom'}}
			}
		});
	});
}

var chartTitles = {
	traffic: <?=json_encode(sprintf(gettext("Traffic: %s — Port %s"), $sw_desc ?: $selected_switch, $port_name))?>,
	packets: <?=json_encode(sprintf(gettext("Packets: %s — Port %s"), $sw_desc ?: $selected_switch, $port_name))?>,
	errors: <?=json_encode(sprintf(gettext("Errors & Discards: %s — Port %s"), $sw_desc ?: $selected_switch, $port_name))?>
};

function getChartCanvas(canvasId, name) {
	var src = document.getElementById(canvasId);
	var title = chartTitles[name] || '';
	var headerH = title ? 50 : 0;
	var canvas = document.createElement('canvas');
	canvas.width = src.width;
	canvas.height = src.height + headerH;
	var ctx = canvas.getContext('2d');
	ctx.fillStyle = '#ffffff';
	ctx.fillRect(0, 0, canvas.width, canvas.height);
	if (title) {
		ctx.fillStyle = '#333333';
		ctx.font = 'bold 20px Arial, sans-serif';
		ctx.textAlign = 'center';
		ctx.fillText(title, canvas.width / 2, 32);
	}
	ctx.drawImage(src, 0, headerH);
	return canvas;
}

function exportChart(canvasId, name, fmt) {
	var canvas = getChartCanvas(canvasId, name);
	var base = '<?=htmlspecialchars($sw_desc ?: $selected_switch)?>_port_<?=htmlspecialchars($port_name)?>_' + name;
	var link = document.createElement('a');
	if (fmt == 'jpg') {
		link.download = base + '.jpg';
		link.href = canvas.toDataURL('image/jpeg', 0.95);
		link.click();
	} else if (fmt == 'pdf') {
		var w = canvas.width;
		var h = canvas.height;
		var imgData = canvas.toDataURL('image/png');
		// Simple PDF with embedded image
		var pw = 842; var ph = Math.round(h * pw / w);
		var stream = 'q ' + pw + ' 0 0 ' + ph + ' 0 0 cm /Img Do Q';
		var imgRaw = atob(imgData.split(',')[1]);
		var pdf = '%PDF-1.4\n';
		var offsets = [];
		// Obj 1: Catalog
		offsets.push(pdf.length); pdf += '1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n';
		// Obj 2: Pages
		offsets.push(pdf.length); pdf += '2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n';
		// Obj 3: Page
		offsets.push(pdf.length); pdf += '3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 '+pw+' '+ph+']/Contents 4 0 R/Resources<</XObject<</Img 5 0 R>>>>>>endobj\n';
		// Obj 4: Content stream
		offsets.push(pdf.length); pdf += '4 0 obj<</Length '+stream.length+'>>stream\n'+stream+'\nendstream\nendobj\n';
		// Obj 5: Image XObject - use raw PNG bytes
		offsets.push(pdf.length);
		var imgHead = '5 0 obj<</Type/XObject/Subtype/Image/Width '+w+'/Height '+h+'/ColorSpace/DeviceRGB/BitsPerComponent 8/Filter/DCTDecode/Length ';
		// Convert to JPEG for PDF embedding
		var jpgData = canvas.toDataURL('image/jpeg', 0.92);
		var jpgRaw = atob(jpgData.split(',')[1]);
		var binArr = new Uint8Array(jpgRaw.length);
		for(var i=0;i<jpgRaw.length;i++) binArr[i]=jpgRaw.charCodeAt(i);
		pdf += imgHead + binArr.length + '>>stream\n';
		// Build final as binary
		var enc = new TextEncoder();
		var pdfBytes = enc.encode(pdf);
		var combined = new Uint8Array(pdfBytes.length + binArr.length);
		combined.set(pdfBytes);
		combined.set(binArr, pdfBytes.length);
		var tail = '\nendstream\nendobj\n';
		// xref
		var xrefOff = pdfBytes.length + binArr.length + tail.length;
		tail += 'xref\n0 6\n0000000000 65535 f \n';
		for(var i=0;i<offsets.length;i++) tail += ('0000000000'+offsets[i]).slice(-10)+' 00000 n \n';
		tail += 'trailer<</Size 6/Root 1 0 R>>\nstartxref\n'+xrefOff+'\n%%EOF';
		var tailBytes = enc.encode(tail);
		var final = new Uint8Array(combined.length + tailBytes.length);
		final.set(combined);
		final.set(tailBytes, combined.length);
		var blob = new Blob([final], {type:'application/pdf'});
		link.download = base + '.pdf';
		link.href = URL.createObjectURL(blob);
		link.click();
		URL.revokeObjectURL(link.href);
	} else {
		link.download = base + '.png';
		link.href = canvas.toDataURL('image/png');
		link.click();
	}
}

loadChart('traffic', 'chart_traffic');
loadChart('packets', 'chart_packets');
loadChart('errors', 'chart_errors');
</script>

<?php endif; ?>
<?php endif; ?>

<?php
include("foot.inc");
