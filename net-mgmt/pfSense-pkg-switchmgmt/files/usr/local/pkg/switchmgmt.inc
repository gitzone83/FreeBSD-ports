<?php
/*
 * switchmgmt.inc
 *
 * Switch Management package for pfSense
 * Discovers and monitors network switches via SNMP.
 *
 * Licensed under the Apache License, Version 2.0
 */

require_once("util.inc");
require_once("config.inc");
require_once("functions.inc");

define('SWITCHMGMT_DB_PATH', '/var/db/switchmgmt.sqlite');
define('SWITCHMGMT_RRD_PATH', '/var/db/rrd/');
define('SWITCHMGMT_LOG_PREFIX', 'switchmgmt');

/* ============================================================
 * Logging
 * ============================================================ */

function switchmgmt_log($msg, $level = LOG_NOTICE) {
	$text = "switchmgmt: {$msg}";
	if (function_exists('logger')) {
		logger($level, $text);
	} elseif (function_exists('log_error')) {
		log_error($text);
	} else {
		syslog($level, $text);
	}
}

/* ============================================================
 * SNMP helpers
 * ============================================================ */

/**
 * Build snmpwalk/snmpget command arguments for a switch config entry.
 * Returns the common argument string (version, credentials, timeout, retries, host).
 */
function switchmgmt_snmp_args($sw) {
	$settings = switchmgmt_get_settings();
	$timeout = !empty($settings['snmptimeout']) ? (int)$settings['snmptimeout'] : 5;
	$retries = !empty($settings['snmpretries']) ? (int)$settings['snmpretries'] : 2;

	$host = escapeshellarg($sw['ipaddr']);
	$ver = $sw['snmpversion'] ?: '2c';

	$args = "-t {$timeout} -r {$retries}";

	if ($ver == '3') {
		$user = escapeshellarg($sw['community']);
		$seclevel = escapeshellarg($sw['v3seclevel'] ?: 'authPriv');
		$args .= " -v 3 -u {$user} -l {$seclevel}";

		if (in_array($sw['v3seclevel'], ['authNoPriv', 'authPriv'])) {
			$authproto = escapeshellarg($sw['v3authproto'] ?: 'SHA');
			$authpass = escapeshellarg($sw['v3authpass']);
			$args .= " -a {$authproto} -A {$authpass}";
		}
		if ($sw['v3seclevel'] == 'authPriv') {
			$privproto = escapeshellarg($sw['v3privproto'] ?: 'AES');
			$privpass = escapeshellarg($sw['v3privpass']);
			$args .= " -x {$privproto} -X {$privpass}";
		}
	} else {
		$community = escapeshellarg($sw['community'] ?: 'public');
		$args .= " -v {$ver} -c {$community}";
	}

	$args .= " {$host}";
	return $args;
}

/**
 * Walk an SNMP OID tree. Returns associative array of index => value.
 */
function switchmgmt_snmpwalk($sw, $oid) {
	$args = switchmgmt_snmp_args($sw);
	$cmd = "/usr/local/bin/snmpwalk -OQn {$args} " . escapeshellarg($oid) . " 2>/dev/null";
	$output = array();
	exec($cmd, $output, $ret);
	if ($ret != 0) {
		return false;
	}

	$results = array();
	foreach ($output as $line) {
		// Format: .1.3.6.1.2.1.2.2.1.1.1 = 1
		if (preg_match('/^([\.\d]+)\s+=\s+(.*)$/', $line, $m)) {
			$idx = $m[1];
			$val = trim($m[2]);
			// Strip type prefixes like STRING: INTEGER: Gauge32: Counter32: etc.
			$val = preg_replace('/^[A-Za-z0-9]+:\s*/', '', $val);
			// Strip quotes
			$val = trim($val, '"');
			$results[$idx] = $val;
		}
	}
	return $results;
}

/**
 * Get a single SNMP value.
 */
function switchmgmt_snmpget($sw, $oid) {
	$args = switchmgmt_snmp_args($sw);
	$cmd = "/usr/local/bin/snmpget -OQvn {$args} " . escapeshellarg($oid) . " 2>/dev/null";
	$output = array();
	exec($cmd, $output, $ret);
	if ($ret != 0 || empty($output)) {
		return false;
	}
	$val = trim($output[0]);
	$val = preg_replace('/^[A-Za-z0-9]+:\s*/', '', $val);
	return trim($val, '"');
}

/* ============================================================
 * OID constants (IF-MIB, SNMPv2-MIB, ENTITY-MIB)
 * ============================================================ */

// System info
define('OID_SYSDESCR',    '.1.3.6.1.2.1.1.1.0');
define('OID_SYSNAME',     '.1.3.6.1.2.1.1.5.0');
define('OID_SYSUPTIME',   '.1.3.6.1.2.1.1.3.0');
define('OID_SYSOBJECTID', '.1.3.6.1.2.1.1.2.0');

// IF-MIB interface table
define('OID_IFDESCR',        '.1.3.6.1.2.1.2.2.1.2');
define('OID_IFTYPE',         '.1.3.6.1.2.1.2.2.1.3');
define('OID_IFSPEED',        '.1.3.6.1.2.1.2.2.1.5');
define('OID_IFOPERSTATUS',   '.1.3.6.1.2.1.2.2.1.8');
define('OID_IFADMINSTATUS',  '.1.3.6.1.2.1.2.2.1.7');

// IF-MIB counters (32-bit)
define('OID_IFINOCTETS',     '.1.3.6.1.2.1.2.2.1.10');
define('OID_IFOUTOCTETS',    '.1.3.6.1.2.1.2.2.1.16');
define('OID_IFINUCASTPKTS',  '.1.3.6.1.2.1.2.2.1.11');
define('OID_IFOUTUCASTPKTS', '.1.3.6.1.2.1.2.2.1.17');
define('OID_IFINERRORS',     '.1.3.6.1.2.1.2.2.1.14');
define('OID_IFOUTERRORS',    '.1.3.6.1.2.1.2.2.1.20');
define('OID_IFINDISCARDS',   '.1.3.6.1.2.1.2.2.1.13');
define('OID_IFOUTDISCARDS',  '.1.3.6.1.2.1.2.2.1.19');

// IF-MIB 64-bit counters (ifXTable)
define('OID_IFHCINOCTETS',   '.1.3.6.1.2.1.31.1.1.1.6');
define('OID_IFHCOUTOCTETS',  '.1.3.6.1.2.1.31.1.1.1.10');
define('OID_IFHIGHSPEED',    '.1.3.6.1.2.1.31.1.1.1.15');
define('OID_IFALIAS',        '.1.3.6.1.2.1.31.1.1.1.18');
define('OID_IFNAME',         '.1.3.6.1.2.1.31.1.1.1.1');

/* ============================================================
 * SNMP value normalization
 *
 * Some switches (e.g. EdgeCore) return textual enum names instead
 * of integer values for ifType, ifAdminStatus, ifOperStatus.
 * These maps convert text back to the standard integer values.
 * ============================================================ */

$GLOBALS['switchmgmt_snmp_enum_maps'] = array(
	'ifAdminStatus' => array(
		'up'      => 1,
		'down'    => 2,
		'testing' => 3,
	),
	'ifOperStatus' => array(
		'up'             => 1,
		'down'           => 2,
		'testing'        => 3,
		'unknown'        => 4,
		'dormant'        => 5,
		'notPresent'     => 6,
		'lowerLayerDown' => 7,
	),
	'ifType' => array(
		'ethernetCsmacd'   => 6,
		'softwareLoopback' => 24,
		'rs232'            => 33,
		'propVirtual'      => 53,
		'tunnel'           => 131,
		'l3ipvlan'         => 136,
		'ieee8023adLag'    => 161,
	),
);

/**
 * Normalize an SNMP value that may be a textual enum name to its integer.
 */
function switchmgmt_normalize_snmp_value($field, $value) {
	if (is_numeric($value)) {
		return (int)$value;
	}

	$maps = $GLOBALS['switchmgmt_snmp_enum_maps'];
	if (isset($maps[$field])) {
		$text = trim($value);
		if (isset($maps[$field][$text])) {
			return $maps[$field][$text];
		}
		$lower = strtolower($text);
		foreach ($maps[$field] as $name => $num) {
			if (strtolower($name) === $lower) {
				return $num;
			}
		}
	}

	switchmgmt_log("Unknown SNMP enum value for {$field}: '{$value}'", LOG_WARNING);
	return 0;
}

/* ============================================================
 * Vendor detection and interface filtering
 *
 * Different switch vendors expose different non-physical interface
 * types. The framework detects the vendor from sysObjectID/sysDescr
 * and applies vendor-specific ifType exclusion lists.
 *
 * To add a new vendor, add an entry before 'default' in
 * switchmgmt_get_vendor_filters().
 * ============================================================ */

function switchmgmt_get_vendor_filters() {
	return array(
		'edgecore' => array(
			'name' => 'EdgeCore',
			'match' => function($sysinfo) {
				$oid = $sysinfo['sysobjectid'] ?? '';
				$descr = $sysinfo['sysdescr'] ?? '';
				return (strpos($oid, '.1.3.6.1.4.1.259') === 0)
					|| (stripos($descr, 'edgecore') !== false)
					|| (stripos($descr, 'accton') !== false);
			},
			'exclude_types' => array(
				33,  // rs232 (console port)
				24,  // softwareLoopback
				136, // l3ipvlan (VLAN interfaces)
			),
			// Port capability map per model.
			// Keyed by sysDescr substring match -> array of port range definitions.
			// ifHighSpeed reports transceiver speed, not port capability,
			// so we need hardware knowledge for accurate max speed.
			'port_capabilities' => array(
				'ECS4155' => array(
					array('start' => 1, 'end' => 24, 'maxspeed' => 2500),
					array('start' => 25, 'end' => 30, 'maxspeed' => 25000),
				),
			),
		),
		'default' => array(
			'name' => 'Generic',
			'match' => function($sysinfo) { return true; },
			'exclude_types' => array(
				24,  // softwareLoopback
			),
		),
	);
}

function switchmgmt_detect_vendor($sysinfo) {
	$filters = switchmgmt_get_vendor_filters();
	foreach ($filters as $key => $vendor) {
		if ($key === 'default') {
			continue;
		}
		if (call_user_func($vendor['match'], $sysinfo)) {
			switchmgmt_log("Detected vendor: {$vendor['name']}", LOG_INFO);
			return $vendor;
		}
	}
	return $filters['default'];
}

function switchmgmt_should_exclude_interface($iftype, $vendor) {
	return in_array((int)$iftype, $vendor['exclude_types'], true);
}

/**
 * Get the true port capability (max speed in Mbps) for a port.
 * Uses vendor-specific port capability maps when available,
 * falling back to ifHighSpeed.
 */
function switchmgmt_get_port_capability($vendor, $sysinfo, $ifindex, $fallback_speed) {
	if (!isset($vendor['port_capabilities'])) {
		return (int)$fallback_speed;
	}

	$descr = $sysinfo['sysdescr'] ?? '';
	foreach ($vendor['port_capabilities'] as $model => $ranges) {
		if (stripos($descr, $model) !== false) {
			$idx = (int)$ifindex;
			foreach ($ranges as $range) {
				if ($idx >= $range['start'] && $idx <= $range['end']) {
					return (int)$range['maxspeed'];
				}
			}
		}
	}

	return (int)$fallback_speed;
}

/* ============================================================
 * Config helpers
 * ============================================================ */

function switchmgmt_get_settings() {
	$settings = config_get_path('installedpackages/switchmgmtsettings/config/0');
	if (!is_array($settings)) {
		$settings = array();
	}
	return $settings;
}

function switchmgmt_get_switches() {
	$switches = config_get_path('installedpackages/switchmgmt/config');
	if (!is_array($switches)) {
		$switches = array();
	}
	return $switches;
}

/* ============================================================
 * SQLite database
 * ============================================================ */

function switchmgmt_db_open() {
	$db_path = SWITCHMGMT_DB_PATH;

	try {
		$db = new SQLite3($db_path);
		$db->busyTimeout(10000);
	} catch (Exception $e) {
		switchmgmt_log("Could not open database: " . $e->getMessage(), LOG_ERR);
		return false;
	}

	// Create tables if they don't exist
	$db->exec("
		CREATE TABLE IF NOT EXISTS switches (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			ipaddr TEXT UNIQUE NOT NULL,
			sysname TEXT,
			sysdescr TEXT,
			sysuptime TEXT,
			sysobjectid TEXT,
			last_polled INTEGER,
			poll_status TEXT DEFAULT 'unknown'
		);
		CREATE TABLE IF NOT EXISTS ports (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			switch_ip TEXT NOT NULL,
			ifindex INTEGER NOT NULL,
			ifdescr TEXT,
			ifname TEXT,
			ifalias TEXT,
			iftype INTEGER,
			ifspeed BIGINT,
			ifhighspeed INTEGER,
			ifmaxspeed INTEGER DEFAULT 0,
			ifadminstatus INTEGER,
			ifoperstatus INTEGER,
			in_octets BIGINT DEFAULT 0,
			out_octets BIGINT DEFAULT 0,
			in_ucast_pkts BIGINT DEFAULT 0,
			out_ucast_pkts BIGINT DEFAULT 0,
			in_errors BIGINT DEFAULT 0,
			out_errors BIGINT DEFAULT 0,
			in_discards BIGINT DEFAULT 0,
			out_discards BIGINT DEFAULT 0,
			last_updated INTEGER,
			UNIQUE(switch_ip, ifindex)
		);
		CREATE INDEX IF NOT EXISTS idx_ports_switch ON ports (switch_ip);
	");

	return $db;
}

/**
 * Update or insert switch system info in the database.
 */
function switchmgmt_db_update_switch($db, $ipaddr, $sysinfo) {
	$stmt = $db->prepare("
		INSERT INTO switches (ipaddr, sysname, sysdescr, sysuptime, sysobjectid, last_polled, poll_status)
		VALUES (:ip, :name, :descr, :uptime, :oid, :time, 'ok')
		ON CONFLICT(ipaddr) DO UPDATE SET
			sysname = :name,
			sysdescr = :descr,
			sysuptime = :uptime,
			sysobjectid = :oid,
			last_polled = :time,
			poll_status = 'ok'
	");
	$stmt->bindValue(':ip', $ipaddr, SQLITE3_TEXT);
	$stmt->bindValue(':name', $sysinfo['sysname'], SQLITE3_TEXT);
	$stmt->bindValue(':descr', $sysinfo['sysdescr'], SQLITE3_TEXT);
	$stmt->bindValue(':uptime', $sysinfo['sysuptime'], SQLITE3_TEXT);
	$stmt->bindValue(':oid', $sysinfo['sysobjectid'], SQLITE3_TEXT);
	$stmt->bindValue(':time', time(), SQLITE3_INTEGER);
	$stmt->execute();
}

/**
 * Mark a switch as unreachable.
 */
function switchmgmt_db_mark_unreachable($db, $ipaddr) {
	$stmt = $db->prepare("
		INSERT INTO switches (ipaddr, last_polled, poll_status)
		VALUES (:ip, :time, 'unreachable')
		ON CONFLICT(ipaddr) DO UPDATE SET
			last_polled = :time,
			poll_status = 'unreachable'
	");
	$stmt->bindValue(':ip', $ipaddr, SQLITE3_TEXT);
	$stmt->bindValue(':time', time(), SQLITE3_INTEGER);
	$stmt->execute();
}

/**
 * Update or insert port data.
 */
function switchmgmt_db_update_port($db, $switch_ip, $ifindex, $portdata) {
	$hispeed = (int)($portdata['ifhighspeed'] ?? 0);
	$maxspeed = (int)($portdata['ifmaxspeed'] ?? $hispeed);
	$stmt = $db->prepare("
		INSERT INTO ports (switch_ip, ifindex, ifdescr, ifname, ifalias, iftype, ifspeed, ifhighspeed, ifmaxspeed,
			ifadminstatus, ifoperstatus, in_octets, out_octets, in_ucast_pkts, out_ucast_pkts,
			in_errors, out_errors, in_discards, out_discards, last_updated)
		VALUES (:sw, :idx, :descr, :name, :alias, :type, :speed, :hispeed, :maxspeed,
			:admin, :oper, :ino, :outo, :inp, :outp, :ine, :oute, :ind, :outd, :time)
		ON CONFLICT(switch_ip, ifindex) DO UPDATE SET
			ifdescr = :descr, ifname = :name, ifalias = :alias, iftype = :type,
			ifspeed = :speed, ifhighspeed = :hispeed,
			ifmaxspeed = MAX(COALESCE(ifmaxspeed, 0), :maxspeed),
			ifadminstatus = :admin, ifoperstatus = :oper,
			in_octets = :ino, out_octets = :outo,
			in_ucast_pkts = :inp, out_ucast_pkts = :outp,
			in_errors = :ine, out_errors = :oute,
			in_discards = :ind, out_discards = :outd,
			last_updated = :time
	");
	$stmt->bindValue(':sw', $switch_ip, SQLITE3_TEXT);
	$stmt->bindValue(':idx', (int)$ifindex, SQLITE3_INTEGER);
	$stmt->bindValue(':descr', $portdata['ifdescr'] ?? '', SQLITE3_TEXT);
	$stmt->bindValue(':name', $portdata['ifname'] ?? '', SQLITE3_TEXT);
	$stmt->bindValue(':alias', $portdata['ifalias'] ?? '', SQLITE3_TEXT);
	$stmt->bindValue(':type', (int)($portdata['iftype'] ?? 0), SQLITE3_INTEGER);
	$stmt->bindValue(':speed', $portdata['ifspeed'] ?? 0, SQLITE3_INTEGER);
	$stmt->bindValue(':hispeed', $hispeed, SQLITE3_INTEGER);
	$stmt->bindValue(':maxspeed', $maxspeed, SQLITE3_INTEGER);
	$stmt->bindValue(':admin', (int)($portdata['ifadminstatus'] ?? 0), SQLITE3_INTEGER);
	$stmt->bindValue(':oper', (int)($portdata['ifoperstatus'] ?? 0), SQLITE3_INTEGER);
	$stmt->bindValue(':ino', $portdata['in_octets'] ?? 0, SQLITE3_INTEGER);
	$stmt->bindValue(':outo', $portdata['out_octets'] ?? 0, SQLITE3_INTEGER);
	$stmt->bindValue(':inp', $portdata['in_ucast_pkts'] ?? 0, SQLITE3_INTEGER);
	$stmt->bindValue(':outp', $portdata['out_ucast_pkts'] ?? 0, SQLITE3_INTEGER);
	$stmt->bindValue(':ine', $portdata['in_errors'] ?? 0, SQLITE3_INTEGER);
	$stmt->bindValue(':oute', $portdata['out_errors'] ?? 0, SQLITE3_INTEGER);
	$stmt->bindValue(':ind', $portdata['in_discards'] ?? 0, SQLITE3_INTEGER);
	$stmt->bindValue(':outd', $portdata['out_discards'] ?? 0, SQLITE3_INTEGER);
	$stmt->bindValue(':time', time(), SQLITE3_INTEGER);
	$stmt->execute();
}

/* ============================================================
 * RRD helpers
 * ============================================================ */

/**
 * Create an RRD file for a switch port if it doesn't exist.
 * Stores: inOctets, outOctets, inErrors, outErrors, inDiscards, outDiscards
 */
function switchmgmt_rrd_create($switch_ip, $ifindex) {
	$rrdfile = switchmgmt_rrd_filename($switch_ip, $ifindex);
	if (file_exists($rrdfile)) {
		return;
	}

	$settings = switchmgmt_get_settings();
	$step = !empty($settings['pollinterval']) ? (int)$settings['pollinterval'] : 300;
	$heartbeat = $step * 2;

	$rrdtool = "/usr/local/bin/rrdtool";
	$cmd = "{$rrdtool} create " . escapeshellarg($rrdfile) . " --step {$step} " .
		"DS:inoctets:COUNTER:{$heartbeat}:0:U " .
		"DS:outoctets:COUNTER:{$heartbeat}:0:U " .
		"DS:inpkts:COUNTER:{$heartbeat}:0:U " .
		"DS:outpkts:COUNTER:{$heartbeat}:0:U " .
		"DS:inerrors:COUNTER:{$heartbeat}:0:U " .
		"DS:outerrors:COUNTER:{$heartbeat}:0:U " .
		"DS:indiscards:COUNTER:{$heartbeat}:0:U " .
		"DS:outdiscards:COUNTER:{$heartbeat}:0:U " .
		"RRA:AVERAGE:0.5:1:1200 " .   // 5min * 1200 = ~4 days at 5min intervals
		"RRA:AVERAGE:0.5:5:720 " .    // 25min * 720 = ~12 days
		"RRA:AVERAGE:0.5:60:1860 " .  // 5hr * 1860 = ~1 year
		"RRA:AVERAGE:0.5:1440:2284 "; // 5day * 2284 = ~31 years

	exec($cmd, $output, $ret);
	if ($ret != 0) {
		switchmgmt_log("Failed to create RRD for {$switch_ip} ifIndex {$ifindex}", LOG_ERR);
	}
}

function switchmgmt_rrd_filename($switch_ip, $ifindex) {
	$safe_ip = str_replace('.', '_', $switch_ip);
	return SWITCHMGMT_RRD_PATH . "switchmgmt_{$safe_ip}_if{$ifindex}.rrd";
}

/**
 * Update RRD with current counter values.
 */
function switchmgmt_rrd_update($switch_ip, $ifindex, $portdata) {
	$rrdfile = switchmgmt_rrd_filename($switch_ip, $ifindex);
	if (!file_exists($rrdfile)) {
		return;
	}

	$rrdtool = "/usr/local/bin/rrdtool";
	$values = "N:" .
		($portdata['in_octets'] ?? 'U') . ":" .
		($portdata['out_octets'] ?? 'U') . ":" .
		($portdata['in_ucast_pkts'] ?? 'U') . ":" .
		($portdata['out_ucast_pkts'] ?? 'U') . ":" .
		($portdata['in_errors'] ?? 'U') . ":" .
		($portdata['out_errors'] ?? 'U') . ":" .
		($portdata['in_discards'] ?? 'U') . ":" .
		($portdata['out_discards'] ?? 'U');

	$cmd = "{$rrdtool} update " . escapeshellarg($rrdfile) . " {$values}";
	exec($cmd, $output, $ret);
	if ($ret != 0) {
		switchmgmt_log("Failed to update RRD for {$switch_ip} ifIndex {$ifindex}", LOG_ERR);
	}
}

/* ============================================================
 * SNMP polling
 * ============================================================ */

/**
 * Extract ifIndex from a full OID. E.g., ".1.3.6.1.2.1.2.2.1.2.5" -> "5"
 */
function switchmgmt_extract_ifindex($full_oid, $base_oid) {
	return str_replace($base_oid . '.', '', $full_oid);
}

/**
 * Walk an OID and return array indexed by ifIndex.
 */
function switchmgmt_walk_indexed($sw, $oid) {
	$raw = switchmgmt_snmpwalk($sw, $oid);
	if ($raw === false) {
		return false;
	}
	$result = array();
	foreach ($raw as $full_oid => $val) {
		$ifindex = switchmgmt_extract_ifindex($full_oid, $oid);
		$result[$ifindex] = $val;
	}
	return $result;
}

/**
 * Poll a single switch: gather system info and per-port stats.
 */
function switchmgmt_poll_switch($sw) {
	$ip = $sw['ipaddr'];
	$db = switchmgmt_db_open();
	if (!$db) {
		return false;
	}

	// System info
	$sysname = switchmgmt_snmpget($sw, OID_SYSNAME);
	if ($sysname === false) {
		switchmgmt_log("Switch {$ip} unreachable via SNMP", LOG_WARNING);
		switchmgmt_db_mark_unreachable($db, $ip);
		$db->close();
		return false;
	}

	$sysinfo = array(
		'sysname'     => $sysname,
		'sysdescr'    => switchmgmt_snmpget($sw, OID_SYSDESCR),
		'sysuptime'   => switchmgmt_snmpget($sw, OID_SYSUPTIME),
		'sysobjectid' => switchmgmt_snmpget($sw, OID_SYSOBJECTID),
	);
	switchmgmt_db_update_switch($db, $ip, $sysinfo);

	// Detect vendor for interface filtering
	$vendor = switchmgmt_detect_vendor($sysinfo);

	// Walk interface table
	$ifdescrs = switchmgmt_walk_indexed($sw, OID_IFDESCR);
	if ($ifdescrs === false || empty($ifdescrs)) {
		$db->close();
		return false;
	}

	// Determine which ports to monitor
	$monitor_indices = array_keys($ifdescrs);
	if (isset($sw['monitorall']) && $sw['monitorall'] != 'on' && !empty($sw['monitorports'])) {
		$monitor_indices = array_map('trim', explode(',', $sw['monitorports']));
	}

	// Bulk-walk all the OIDs we need
	$ifnames      = switchmgmt_walk_indexed($sw, OID_IFNAME) ?: array();
	$ifaliases    = switchmgmt_walk_indexed($sw, OID_IFALIAS) ?: array();
	$iftypes      = switchmgmt_walk_indexed($sw, OID_IFTYPE) ?: array();
	$ifspeeds     = switchmgmt_walk_indexed($sw, OID_IFSPEED) ?: array();
	$ifhighspeeds = switchmgmt_walk_indexed($sw, OID_IFHIGHSPEED) ?: array();
	$ifadmin      = switchmgmt_walk_indexed($sw, OID_IFADMINSTATUS) ?: array();
	$ifoper       = switchmgmt_walk_indexed($sw, OID_IFOPERSTATUS) ?: array();

	// Normalize text enum values to integers (handles vendors that return text)
	foreach ($iftypes as $idx => $val) {
		$iftypes[$idx] = switchmgmt_normalize_snmp_value('ifType', $val);
	}
	foreach ($ifadmin as $idx => $val) {
		$ifadmin[$idx] = switchmgmt_normalize_snmp_value('ifAdminStatus', $val);
	}
	foreach ($ifoper as $idx => $val) {
		$ifoper[$idx] = switchmgmt_normalize_snmp_value('ifOperStatus', $val);
	}

	// Try 64-bit counters first, fall back to 32-bit
	$inoctets  = switchmgmt_walk_indexed($sw, OID_IFHCINOCTETS);
	$outoctets = switchmgmt_walk_indexed($sw, OID_IFHCOUTOCTETS);
	if ($inoctets === false) {
		$inoctets  = switchmgmt_walk_indexed($sw, OID_IFINOCTETS) ?: array();
		$outoctets = switchmgmt_walk_indexed($sw, OID_IFOUTOCTETS) ?: array();
	} else {
		if ($outoctets === false) {
			$outoctets = array();
		}
	}

	$inpkts    = switchmgmt_walk_indexed($sw, OID_IFINUCASTPKTS) ?: array();
	$outpkts   = switchmgmt_walk_indexed($sw, OID_IFOUTUCASTPKTS) ?: array();
	$inerrors   = switchmgmt_walk_indexed($sw, OID_IFINERRORS) ?: array();
	$outerrors  = switchmgmt_walk_indexed($sw, OID_IFOUTERRORS) ?: array();
	$indiscards  = switchmgmt_walk_indexed($sw, OID_IFINDISCARDS) ?: array();
	$outdiscards = switchmgmt_walk_indexed($sw, OID_IFOUTDISCARDS) ?: array();

	$settings = switchmgmt_get_settings();
	$rrd_enabled = (!isset($settings['enablerrd']) || $settings['enablerrd'] == 'on');

	$excluded_indices = array();
	$db->exec("BEGIN TRANSACTION");
	foreach ($monitor_indices as $idx) {
		if (!isset($ifdescrs[$idx])) {
			continue;
		}

		// Filter out non-physical interfaces based on vendor rules
		$normalized_type = $iftypes[$idx] ?? 0;
		if (switchmgmt_should_exclude_interface($normalized_type, $vendor)) {
			$excluded_indices[] = (int)$idx;
			continue;
		}

		$capability = switchmgmt_get_port_capability(
			$vendor, $sysinfo, $idx, $ifhighspeeds[$idx] ?? 0
		);

		$portdata = array(
			'ifdescr'        => $ifdescrs[$idx] ?? '',
			'ifname'         => $ifnames[$idx] ?? '',
			'ifalias'        => $ifaliases[$idx] ?? '',
			'iftype'         => $iftypes[$idx] ?? 0,
			'ifspeed'        => $ifspeeds[$idx] ?? 0,
			'ifhighspeed'    => $ifhighspeeds[$idx] ?? 0,
			'ifmaxspeed'     => $capability,
			'ifadminstatus'  => $ifadmin[$idx] ?? 0,
			'ifoperstatus'   => $ifoper[$idx] ?? 0,
			'in_octets'      => $inoctets[$idx] ?? 0,
			'out_octets'     => $outoctets[$idx] ?? 0,
			'in_ucast_pkts'  => $inpkts[$idx] ?? 0,
			'out_ucast_pkts' => $outpkts[$idx] ?? 0,
			'in_errors'      => $inerrors[$idx] ?? 0,
			'out_errors'     => $outerrors[$idx] ?? 0,
			'in_discards'    => $indiscards[$idx] ?? 0,
			'out_discards'   => $outdiscards[$idx] ?? 0,
		);

		switchmgmt_db_update_port($db, $ip, $idx, $portdata);

		if ($rrd_enabled) {
			switchmgmt_rrd_create($ip, $idx);
			switchmgmt_rrd_update($ip, $idx, $portdata);
		}
	}
	$db->exec("COMMIT");

	// Clean up any previously-stored excluded interfaces
	if (!empty($excluded_indices)) {
		$placeholders = implode(',', array_fill(0, count($excluded_indices), '?'));
		$stmt = $db->prepare(
			"DELETE FROM ports WHERE switch_ip = ? AND ifindex IN ({$placeholders})"
		);
		$stmt->bindValue(1, $ip, SQLITE3_TEXT);
		$i = 2;
		foreach ($excluded_indices as $ifidx) {
			$stmt->bindValue($i++, $ifidx, SQLITE3_INTEGER);
		}
		$stmt->execute();
	}

	$db->close();

	switchmgmt_log("Polled switch {$ip} ({$sysname}): " . count($monitor_indices) . " ports");
	return true;
}

/**
 * Poll all configured switches.
 */
function switchmgmt_poll_all() {
	$switches = switchmgmt_get_switches();
	foreach ($switches as $sw) {
		if (!empty($sw['ipaddr'])) {
			switchmgmt_poll_switch($sw);
		}
	}
}

/* ============================================================
 * Status page data retrieval
 * ============================================================ */

/**
 * Get all switches from the database.
 */
function switchmgmt_get_switch_status() {
	$db = switchmgmt_db_open();
	if (!$db) {
		return array();
	}

	$results = array();
	$res = $db->query("SELECT * FROM switches ORDER BY sysname, ipaddr");
	while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
		$results[] = $row;
	}
	$db->close();
	return $results;
}

/**
 * Get all ports for a specific switch.
 */
function switchmgmt_get_port_status($switch_ip) {
	$db = switchmgmt_db_open();
	if (!$db) {
		return array();
	}

	$stmt = $db->prepare("SELECT * FROM ports WHERE switch_ip = :ip ORDER BY ifindex");
	$stmt->bindValue(':ip', $switch_ip, SQLITE3_TEXT);
	$res = $stmt->execute();

	$results = array();
	while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
		$results[] = $row;
	}
	$db->close();
	return $results;
}

/* ============================================================
 * Human-readable helpers
 * ============================================================ */

function switchmgmt_oper_status_text($status) {
	$map = array(1 => 'up', 2 => 'down', 3 => 'testing', 4 => 'unknown',
		5 => 'down', 6 => 'down', 7 => 'down');
	$status = is_numeric($status) ? (int)$status
		: switchmgmt_normalize_snmp_value('ifOperStatus', $status);
	return $map[$status] ?? 'unknown';
}

function switchmgmt_admin_status_text($status) {
	$map = array(1 => 'up', 2 => 'down', 3 => 'testing');
	$status = is_numeric($status) ? (int)$status
		: switchmgmt_normalize_snmp_value('ifAdminStatus', $status);
	return $map[$status] ?? 'unknown';
}

function switchmgmt_format_speed($speed_bps, $highspeed_mbps = 0) {
	if ($highspeed_mbps > 0) {
		if ($highspeed_mbps >= 1000) {
			return ($highspeed_mbps / 1000) . ' Gbps';
		}
		return $highspeed_mbps . ' Mbps';
	}
	if ($speed_bps > 0) {
		if ($speed_bps >= 1000000000) {
			return ($speed_bps / 1000000000) . ' Gbps';
		} elseif ($speed_bps >= 1000000) {
			return ($speed_bps / 1000000) . ' Mbps';
		}
		return $speed_bps . ' bps';
	}
	return '-';
}

function switchmgmt_format_bytes($bytes) {
	$bytes = (float)$bytes;
	if ($bytes >= 1099511627776) {
		return number_format($bytes / 1099511627776, 2) . ' TB';
	} elseif ($bytes >= 1073741824) {
		return number_format($bytes / 1073741824, 2) . ' GB';
	} elseif ($bytes >= 1048576) {
		return number_format($bytes / 1048576, 2) . ' MB';
	} elseif ($bytes >= 1024) {
		return number_format($bytes / 1024, 2) . ' KB';
	}
	return number_format($bytes, 0) . ' B';
}

/* ============================================================
 * Package hooks (called by pfSense package system)
 * ============================================================ */

function validate_form_switchmgmt($post, &$input_errors) {
	if (empty($post['ipaddr'])) {
		$input_errors[] = 'IP Address is required.';
	} elseif (!is_ipaddr($post['ipaddr']) && !is_hostname($post['ipaddr'])) {
		$input_errors[] = 'IP Address must be a valid IP address or hostname.';
	}

	if (empty($post['description'])) {
		$input_errors[] = 'Description is required.';
	}

	if (empty($post['community'])) {
		$input_errors[] = 'Community string / security name is required.';
	}

	if ($post['snmpversion'] == '3') {
		if (in_array($post['v3seclevel'], ['authNoPriv', 'authPriv'])) {
			if (empty($post['v3authpass']) || strlen($post['v3authpass']) < 8) {
				$input_errors[] = 'SNMPv3 authentication password must be at least 8 characters.';
			}
		}
		if ($post['v3seclevel'] == 'authPriv') {
			if (empty($post['v3privpass']) || strlen($post['v3privpass']) < 8) {
				$input_errors[] = 'SNMPv3 privacy password must be at least 8 characters.';
			}
		}
	}

	if (isset($post['monitorall']) && $post['monitorall'] != 'on' && !empty($post['monitorports'])) {
		$ports = explode(',', $post['monitorports']);
		foreach ($ports as $p) {
			$p = trim($p);
			if (!is_numeric($p) || (int)$p < 1) {
				$input_errors[] = "Invalid port index: '{$p}'. Must be a positive integer.";
			}
		}
	}
}

function validate_form_switchmgmt_settings($post, &$input_errors) {
	if (!empty($post['snmptimeout']) && (!is_numeric($post['snmptimeout']) || (int)$post['snmptimeout'] < 1)) {
		$input_errors[] = 'SNMP Timeout must be a positive integer.';
	}
	if (!empty($post['snmpretries']) && (!is_numeric($post['snmpretries']) || (int)$post['snmpretries'] < 0)) {
		$input_errors[] = 'SNMP Retries must be zero or a positive integer.';
	}
}

function sync_package_switchmgmt() {
	$settings = switchmgmt_get_settings();
	$switches = switchmgmt_get_switches();

	// Manage the cron job
	$cron_cmd = '/usr/local/bin/php -f /usr/local/pkg/switchmgmt_poll.php';

	// Remove existing cron entry
	$cron_jobs = config_get_path('cron/item');
	if (is_array($cron_jobs)) {
		foreach ($cron_jobs as $idx => $job) {
			if (strpos($job['command'], 'switchmgmt_poll.php') !== false) {
				config_del_path("cron/item/{$idx}");
			}
		}
	}

	if (isset($settings['enable']) && $settings['enable'] == 'on' && !empty($switches)) {
		$interval = !empty($settings['pollinterval']) ? (int)$settings['pollinterval'] : 300;

		// Convert seconds to cron minute interval
		$cron_minutes = max(1, intdiv($interval, 60));

		$cron_entry = array(
			'minute'  => "*/{$cron_minutes}",
			'hour'    => '*',
			'mday'    => '*',
			'month'   => '*',
			'wday'    => '*',
			'who'     => 'root',
			'command' => $cron_cmd,
		);
		config_set_path('cron/item/', $cron_entry);

		switchmgmt_log("Polling enabled every {$cron_minutes} minute(s) for " . count($switches) . " switch(es)");
	} else {
		switchmgmt_log("Polling disabled");
	}

	write_config("Switch Management: settings updated");
	configure_cron();
}

function switchmgmt_install() {
	// Ensure RRD directory exists
	if (!is_dir(SWITCHMGMT_RRD_PATH)) {
		@mkdir(SWITCHMGMT_RRD_PATH, 0755, true);
	}

	// Initialize the database
	$db = switchmgmt_db_open();
	if ($db) {
		$db->close();
	}

	switchmgmt_log("Package installed");
}

function switchmgmt_deinstall() {
	// Remove cron job
	$cron_jobs = config_get_path('cron/item');
	if (is_array($cron_jobs)) {
		foreach ($cron_jobs as $idx => $job) {
			if (strpos($job['command'], 'switchmgmt_poll.php') !== false) {
				config_del_path("cron/item/{$idx}");
			}
		}
		write_config("Switch Management: package removed");
		configure_cron();
	}

	// Remove database
	unlink_if_exists(SWITCHMGMT_DB_PATH);

	switchmgmt_log("Package deinstalled");
}
?>
