<?php
/*
 * switchmgmt_ssh.inc
 *
 * SSH transport layer for Switch Management package.
 * Uses the PHP ssh2 extension for SSH connectivity.
 *
 * Licensed under the Apache License, Version 2.0
 */

require_once("/usr/local/pkg/switchmgmt.inc");

/* Ensure the ssh2 extension is loaded. pfSense's PHP-FPM config
 * does not include ssh2 by default, so we load it at runtime. */
if (!function_exists('ssh2_connect')) {
	$ssh2_so = '/usr/local/lib/php/' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION . '/ssh2.so';
	if (!file_exists($ssh2_so)) {
		// Try the dated directory
		$ssh2_so = glob('/usr/local/lib/php/*/ssh2.so')[0] ?? '';
	}
	if (!empty($ssh2_so) && file_exists($ssh2_so)) {
		// Load via ini setting since dl() is disabled in FPM
		$ini_file = '/usr/local/etc/php/ext-20-ssh2.ini';
		if (!file_exists($ini_file)) {
			@file_put_contents($ini_file, "extension=ssh2.so\n");
		}
	}
}

/**
 * Open an SSH connection and interactive shell to a switch.
 * Returns array with 'conn', 'shell', 'vendor' keys, or false on failure.
 */
function switchmgmt_ssh_connect($sw) {
	if (!function_exists('ssh2_connect')) {
		switchmgmt_log("SSH: php-ssh2 extension not available. Install php83-ssh2 and restart PHP-FPM.", LOG_ERR);
		return false;
	}

	$ip = $sw['ipaddr'];
	$port = !empty($sw['ssh_port']) ? (int)$sw['ssh_port'] : 22;
	$username = $sw['ssh_username'] ?? 'admin';
	$password = $sw['ssh_password'] ?? '';

	$conn = @ssh2_connect($ip, $port);
	if (!$conn) {
		switchmgmt_log("SSH: Failed to connect to {$ip}:{$port}", LOG_ERR);
		return false;
	}

	if (!@ssh2_auth_password($conn, $username, $password)) {
		switchmgmt_log("SSH: Authentication failed for {$username}@{$ip} (user={$username})", LOG_ERR);
		return false;
	}

	$shell = @ssh2_shell($conn, 'vt100');
	if (!$shell) {
		switchmgmt_log("SSH: Failed to open shell on {$ip}", LOG_ERR);
		return false;
	}

	stream_set_blocking($shell, false);

	// Wait for initial prompt
	$initial = switchmgmt_ssh_read($shell, 3);

	switchmgmt_log("SSH: Connected to {$ip} as {$username}");

	return array(
		'conn'    => $conn,
		'shell'   => $shell,
		'ip'      => $ip,
		'initial' => $initial,
	);
}

/**
 * Read from shell until timeout, collecting all available output.
 */
function switchmgmt_ssh_read($shell, $timeout = 2) {
	$buf = '';
	$end = time() + $timeout;
	while (time() < $end) {
		$chunk = @fread($shell, 8192);
		if ($chunk !== false && strlen($chunk) > 0) {
			$buf .= $chunk;
			// Reset timeout on data received
			$end = time() + 1;
		} else {
			usleep(100000); // 100ms
		}
	}
	return $buf;
}

/**
 * Read from shell until a prompt pattern is matched or timeout.
 */
function switchmgmt_ssh_read_until($shell, $prompt_pattern, $timeout = 10) {
	$buf = '';
	$end = time() + $timeout;
	while (time() < $end) {
		$chunk = @fread($shell, 8192);
		if ($chunk !== false && strlen($chunk) > 0) {
			$buf .= $chunk;
			if (preg_match($prompt_pattern, $buf)) {
				return $buf;
			}
			$end = time() + 2; // extend on data
		} else {
			usleep(100000);
		}
	}
	return $buf;
}

/**
 * Send a command and read the response until the prompt.
 */
function switchmgmt_ssh_exec($session, $command, $prompt_pattern = '/#\s*$/', $timeout = 10) {
	$shell = $session['shell'];
	fwrite($shell, $command . "\n");
	$output = switchmgmt_ssh_read_until($shell, $prompt_pattern, $timeout);
	return $output;
}

/**
 * Initialize a switch SSH session: disable paging, enter enable/config modes as needed.
 * Returns the session with 'vendor' added.
 */
function switchmgmt_ssh_init($session, $sw) {
	// Detect vendor from DB
	$db = switchmgmt_db_open();
	$vendor_info = null;
	if ($db) {
		$stmt = $db->prepare("SELECT * FROM switches WHERE ipaddr = :ip");
		$stmt->bindValue(':ip', $sw['ipaddr'], SQLITE3_TEXT);
		$res = $stmt->execute();
		$vendor_info = $res->fetchArray(SQLITE3_ASSOC);
		$db->close();
	}

	$vendor = $vendor_info ? switchmgmt_detect_vendor($vendor_info) : null;
	$vendor_name = $vendor ? strtolower($vendor['name']) : 'generic';
	$session['vendor'] = $vendor;
	$session['vendor_name'] = $vendor_name;

	$shell = $session['shell'];
	$prompt = '/#\s*$/';

	// Disable paging
	switchmgmt_ssh_exec($session, 'terminal length 0', $prompt);

	// Cisco: may need enable mode
	if ($vendor_name == 'cisco') {
		$enable_pass = $sw['ssh_enable_password'] ?? '';
		if (!empty($enable_pass)) {
			// Check if we're already in privileged mode
			if (strpos($session['initial'], '>') !== false) {
				fwrite($shell, "enable\n");
				usleep(500000);
				fwrite($shell, $enable_pass . "\n");
				switchmgmt_ssh_read_until($shell, $prompt, 5);
			}
		}
	}

	return $session;
}

/**
 * Enter configuration mode.
 */
function switchmgmt_ssh_enter_config($session) {
	$prompt = '/\(config[^)]*\)#\s*$/';
	if ($session['vendor_name'] == 'cisco') {
		return switchmgmt_ssh_exec($session, 'configure terminal', $prompt);
	} else {
		return switchmgmt_ssh_exec($session, 'configure', $prompt);
	}
}

/**
 * Exit configuration mode.
 */
function switchmgmt_ssh_exit_config($session) {
	return switchmgmt_ssh_exec($session, 'end', '/#\s*$/');
}

/**
 * Save running config to startup.
 */
function switchmgmt_ssh_save_config($session) {
	$prompt = '/#\s*$/';
	if ($session['vendor_name'] == 'cisco') {
		$out = switchmgmt_ssh_exec($session, 'write memory', $prompt, 15);
	} else {
		// EdgeCore: send copy command then handle prompts
		fwrite($session['shell'], "copy running-config startup-config\n");
		$out = switchmgmt_ssh_read($session['shell'], 3);
		// ECS4155 prompts "Startup configuration file name [exit]:" - send Enter to confirm
		// ECS2100 may prompt "y/n" or just proceed
		if (stripos($out, 'file name') !== false || stripos($out, 'startup') !== false) {
			fwrite($session['shell'], "\n");
			$out .= switchmgmt_ssh_read_until($session['shell'], $prompt, 15);
		} elseif (stripos($out, 'y/n') !== false || stripos($out, 'confirm') !== false) {
			fwrite($session['shell'], "y\n");
			$out .= switchmgmt_ssh_read_until($session['shell'], $prompt, 15);
		} else {
			$out .= switchmgmt_ssh_read_until($session['shell'], $prompt, 10);
		}
	}
	return $out;
}

/**
 * Push port profile configuration to a switch via SSH.
 * Returns array with 'success', 'log' (per-command output), 'errors'.
 */
function switchmgmt_ssh_push_config($sw) {
	$result = array('success' => false, 'log' => array(), 'errors' => array());
	$ip = $sw['ipaddr'];

	// Generate the config preview
	$preview = switchmgmt_generate_config_preview($ip);
	if (empty($preview['entries'])) {
		$result['errors'][] = gettext("No profile assignments to push.");
		return $result;
	}

	// Connect
	$session = switchmgmt_ssh_connect($sw);
	if (!$session) {
		$ssh2_avail = function_exists('ssh2_connect') ? 'yes' : 'no';
		$result['errors'][] = sprintf(
			gettext("SSH connection to %s failed. (user=%s, port=%s, ssh2_ext=%s)"),
			$ip, $sw['ssh_username'] ?? '?', $sw['ssh_port'] ?? '22', $ssh2_avail
		);
		return $result;
	}
	$session = switchmgmt_ssh_init($session, $sw);
	$result['log'][] = array('cmd' => '-- Connected --', 'output' => '');

	// EdgeCore: ensure VLANs exist in vlan database
	if ($session['vendor_name'] == 'edgecore') {
		$vlans_needed = array();
		foreach ($preview['entries'] as $entry) {
			foreach ($entry['commands'] as $line) {
				if (preg_match('/vlan\s+(?:add\s+)?(\d[\d,-]*)/i', $line, $m)) {
					foreach (explode(',', $m[1]) as $v) {
						if (strpos($v, '-') !== false) {
							list($start, $end) = explode('-', $v, 2);
							for ($i = (int)$start; $i <= (int)$end; $i++) {
								$vlans_needed[$i] = true;
							}
						} else {
							$vlans_needed[(int)$v] = true;
						}
					}
				}
			}
		}
		unset($vlans_needed[1]); // VLAN 1 always exists

		if (!empty($vlans_needed)) {
			$config_prompt = '/\(config[^)]*\)#\s*$/';
			switchmgmt_ssh_enter_config($session);
			$out = switchmgmt_ssh_exec($session, 'vlan database', '/\(config-vlan\)#\s*$/');
			foreach (array_keys($vlans_needed) as $vid) {
				$out = switchmgmt_ssh_exec($session, "vlan {$vid} media ethernet", '/\(config-vlan\)#\s*$/');
				$result['log'][] = array('cmd' => "vlan {$vid} media ethernet", 'output' => trim($out));
			}
			switchmgmt_ssh_exec($session, 'exit', $config_prompt);
			switchmgmt_ssh_exit_config($session);
		}
	}

	// Enter config mode
	switchmgmt_ssh_enter_config($session);
	$result['log'][] = array('cmd' => '-- Entering config mode --', 'output' => '');

	$config_prompt = '/\(config[^)]*\)#\s*$/';
	$pushed_ports = array();
	$failed_ports = array();
	$reset_ports = array();

	// Push each port's commands
	foreach ($preview['entries'] as $entry) {
		$port_name = $entry['port_name'];
		$action = $entry['action'] ?? 'apply';
		$port_ok = true;

		foreach ($entry['commands'] as $line) {
			$line = trim($line);
			if (empty($line) || $line === '!') {
				continue;
			}
			$out = switchmgmt_ssh_exec($session, $line, $config_prompt, 5);
			$result['log'][] = array('cmd' => $line, 'output' => trim($out));

			// Check for errors in output
			if (preg_match('/%(ERROR|Invalid|Unrecognized|Ambiguous|Incomplete)/i', $out)) {
				$result['errors'][] = sprintf(gettext("Error on %s: %s"), $port_name, trim($out));
				$port_ok = false;
			}
		}

		if ($port_ok) {
			if ($action == 'reset') {
				$reset_ports[] = $entry['ifindex'];
			} else {
				$pushed_ports[] = $entry['ifindex'];
			}
		} else {
			$failed_ports[] = $entry['ifindex'];
		}
	}

	// Exit config mode
	switchmgmt_ssh_exit_config($session);
	$result['log'][] = array('cmd' => '-- Exiting config mode --', 'output' => '');

	// Save config
	$save_out = switchmgmt_ssh_save_config($session);
	$result['log'][] = array('cmd' => '-- Saving config --', 'output' => trim($save_out));

	// Disconnect
	switchmgmt_ssh_disconnect($session);

	// Update sync status in database
	$db = switchmgmt_db_open();
	if ($db) {
		foreach ($pushed_ports as $ifindex) {
			$stmt = $db->prepare("
				UPDATE port_assignments SET sync_status = 'synced', last_synced = :time
				WHERE switch_ip = :ip AND ifindex = :idx
			");
			$stmt->bindValue(':time', time(), SQLITE3_INTEGER);
			$stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
			$stmt->bindValue(':idx', (int)$ifindex, SQLITE3_INTEGER);
			$stmt->execute();
		}
		foreach ($failed_ports as $ifindex) {
			$stmt = $db->prepare("
				UPDATE port_assignments SET sync_status = 'failed'
				WHERE switch_ip = :ip AND ifindex = :idx
			");
			$stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
			$stmt->bindValue(':idx', (int)$ifindex, SQLITE3_INTEGER);
			$stmt->execute();
		}
		// Delete assignments for successfully reset ports
		foreach ($reset_ports as $ifindex) {
			$stmt = $db->prepare("DELETE FROM port_assignments WHERE switch_ip = :ip AND ifindex = :idx");
			$stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
			$stmt->bindValue(':idx', (int)$ifindex, SQLITE3_INTEGER);
			$stmt->execute();
		}
		$db->close();
	}

	$result['success'] = empty($failed_ports);
	$pushed_count = count($pushed_ports);
	$failed_count = count($failed_ports);
	switchmgmt_log("SSH Push to {$ip}: {$pushed_count} ports synced, {$failed_count} failed");

	return $result;
}

/**
 * Disconnect SSH session.
 */
function switchmgmt_ssh_disconnect($session) {
	if (isset($session['shell']) && is_resource($session['shell'])) {
		fwrite($session['shell'], "exit\n");
		fclose($session['shell']);
	}
	switchmgmt_log("SSH: Disconnected from {$session['ip']}");
}

/**
 * Test SSH connectivity to a switch. Returns array with 'success', 'output', 'error'.
 */
function switchmgmt_ssh_test($sw) {
	$result = array('success' => false, 'output' => '', 'error' => '');

	$session = switchmgmt_ssh_connect($sw);
	if (!$session) {
		$result['error'] = sprintf(gettext("Failed to connect to %s via SSH."), $sw['ipaddr']);
		return $result;
	}

	$session = switchmgmt_ssh_init($session, $sw);

	// Run show version
	$output = switchmgmt_ssh_exec($session, 'show version', '/#\s*$/', 10);

	switchmgmt_ssh_disconnect($session);

	// Clean up ANSI escape sequences and command echo
	$output = preg_replace('/\x1b\[[0-9;]*[a-zA-Z]/', '', $output);
	$lines = explode("\n", $output);
	// Remove first line (command echo) and last line (prompt)
	if (count($lines) > 2) {
		array_shift($lines);
		array_pop($lines);
	}
	$output = trim(implode("\n", $lines));

	$result['success'] = true;
	$result['output'] = $output;
	return $result;
}
