<?php

if (!defined('BB_ROOT')) die(basename(__FILE__));

function get_torrent_info ($attach_id)
{
	global $lang;

	$attach_id = intval($attach_id);

	$sql = "
		SELECT
			a.post_id, d.physical_filename, d.extension, d.tracker_status,
			t.topic_first_post_id,
			p.poster_id, p.topic_id, p.forum_id,
			f.allow_reg_tracker
		FROM
			". BB_ATTACHMENTS      ." a,
			". BB_ATTACHMENTS_DESC ." d,
			". BB_POSTS            ." p,
			". BB_TOPICS           ." t,
			". BB_FORUMS           ." f
		WHERE
			    a.attach_id = $attach_id
			AND d.attach_id = $attach_id
			AND p.post_id = a.post_id
			AND t.topic_id = p.topic_id
			AND f.forum_id = p.forum_id
		LIMIT 1
	";

	if (!$torrent = DB()->fetch_row($sql))
	{
		message_die(GENERAL_ERROR, $lang['INVALID_ATTACH_ID']);
	}

	return $torrent;
}

function torrent_auth_check ($forum_id, $poster_id)
{
	global $userdata, $lang, $attach_config;

	if (IS_ADMIN) return true;

	$is_auth = auth(AUTH_ALL, $forum_id, $userdata);

	if ($poster_id != $userdata['user_id'] && !$is_auth['auth_mod'])
	{
		message_die(GENERAL_MESSAGE, $lang['NOT_MODERATOR'], $lang['NOT_AUTHORISED']);
	}
	else if (!$is_auth['auth_view'] || !$is_auth['auth_attachments'] || $attach_config['disable_mod'])
	{
		$message = sprintf($lang['SORRY_AUTH_READ'], $is_auth['auth_read_type']);
		message_die(GENERAL_MESSAGE, $message);
	}
	return $is_auth;
}

function tracker_unregister ($attach_id, $mode = '')
{
	global $lang, $bb_cfg;

	$attach_id = (int) $attach_id;
	$post_id = $topic_id = $forum_id = $info_hash = null;
	
	// Get torrent info
	if ($torrent = get_torrent_info($attach_id))
	{
		$post_id  = $torrent['post_id'];
		$topic_id = $torrent['topic_id'];
		$forum_id = $torrent['forum_id'];
	}

	if ($mode == 'request')
	{
		if (!$torrent)
		{
			message_die(GENERAL_ERROR, $lang['TOR_NOT_FOUND']);
		}
		if (!$torrent['tracker_status'])
		{
			message_die(GENERAL_ERROR, 'Torrent already unregistered');
		}
		torrent_auth_check($forum_id, $torrent['poster_id']);
	}

	if (!$topic_id)
	{
		$sql = "SELECT topic_id FROM ". BB_BT_TORRENTS ." WHERE attach_id = $attach_id";

		if (!$result = DB()->sql_query($sql))
		{
			message_die(GENERAL_ERROR, 'Could not query torrent information', '', __LINE__, __FILE__, $sql);
		}
		if ($row = DB()->sql_fetchrow($result))
		{
			$topic_id = $row['topic_id'];
		}
	}

	// Unset DL-Type for topic
	if ($bb_cfg['bt_unset_dltype_on_tor_unreg'] && $topic_id)
	{
		$sql = "UPDATE ". BB_TOPICS ." SET topic_dl_type = ". TOPIC_DL_TYPE_NORMAL ." WHERE topic_id = $topic_id LIMIT 1";

		if (!$result = DB()->sql_query($sql))
		{
			message_die(GENERAL_ERROR, 'Could not update topics table', '', __LINE__, __FILE__, $sql);
		}
	}

	// Remove peers from tracker
	$sql = "DELETE FROM ". BB_BT_TRACKER ." WHERE topic_id = $topic_id";

	if (!DB()->sql_query($sql))
	{
		message_die(GENERAL_ERROR, 'Could not delete peers', '', __LINE__, __FILE__, $sql);
	}

	//Ocelot stuff
	if($bb_cfg['ocelot_enabled'])
	{
		if($row = DB()->fetch_row("SELECT info_hash FROM ". BB_BT_TORRENTS ." WHERE attach_id = $attach_id LIMIT 1"))
		{
			$info_hash = $row['info_hash'];
		}
		update_tracker('delete_torrent', array('info_hash' => rawurlencode($info_hash), 'id' => $topic_id));
	}
	//Ocelot stuff

	// Delete torrent
	$sql = "DELETE FROM ". BB_BT_TORRENTS ." WHERE attach_id = $attach_id";

	if (!DB()->sql_query($sql))
	{
		message_die(GENERAL_ERROR, 'Could not delete torrent from torrents table', '', __LINE__, __FILE__, $sql);
	}

	// Update tracker_status
	$sql = "UPDATE ". BB_ATTACHMENTS_DESC ." SET tracker_status = 0 WHERE attach_id = $attach_id LIMIT 1";

	if (!DB()->sql_query($sql))
	{
		message_die(GENERAL_ERROR, 'Could not update torrent status', '', __LINE__, __FILE__, $sql);
	}

	if ($mode == 'request')
	{
		set_die_append_msg($forum_id, $topic_id);
		bb_die($lang['BT_UNREGISTERED']);
	}
	
	if($bb_cfg['portal_enabled'])
	{
		CACHE('portal')->rm();
		CACHE('bb_feed')->rm();
	}
}

function delete_torrent ($attach_id, $mode = '')
{
	global $lang, $reg_mode, $topic_id;

	$attach_id = intval($attach_id);
	$reg_mode = $mode;

	if (!$torrent = get_torrent_info($attach_id))
	{
		message_die(GENERAL_ERROR, $lang['TOR_NOT_FOUND']);
	}

	$topic_id  = $torrent['topic_id'];
	$forum_id  = $torrent['forum_id'];
	$poster_id = $torrent['poster_id'];

	if ($torrent['extension'] !== TORRENT_EXT)
	{
		message_die(GENERAL_ERROR, $lang['NOT_TORRENT']);
	}	
	
	torrent_auth_check($forum_id, $poster_id);
	tracker_unregister($attach_id);
	delete_attachment(0, $attach_id);

	return;
}

function change_tor_status ($attach_id, $new_tor_status)
{
	global $topic_id, $userdata;

	$attach_id = (int) $attach_id;
	$new_tor_status = (int) $new_tor_status;

	if (!$torrent = get_torrent_info($attach_id))
	{
		bb_die($lang['TOR_NOT_FOUND']);
	}

	$topic_id = $torrent['topic_id'];

	torrent_auth_check($torrent['forum_id'], $torrent['poster_id']);

	DB()->query("
		UPDATE ". BB_BT_TORRENTS ." SET
			tor_status = $new_tor_status,
			checked_user_id = {$userdata['user_id']},
			checked_time = '". TIMENOW ."'
		WHERE attach_id = $attach_id
		LIMIT 1
	");
}

// Set gold/silver type for torrent
function change_tor_type ($attach_id, $tor_status_gold)
{
	global $topic_id, $lang, $bb_cfg;

	if (!$torrent = get_torrent_info($attach_id))
	{
		bb_die($lang['TOR_NOT_FOUND']);
	}

	if (!IS_AM) bb_die($lang['ONLY_FOR_MOD']);

	$topic_id = $torrent['topic_id'];
	$info_hash = null;
	$tor_status_gold = intval($tor_status_gold);
	DB()->query("UPDATE ". BB_BT_TORRENTS ." SET tor_type = $tor_status_gold WHERE topic_id = $topic_id LIMIT 1");
	
	//Ocelot stuff
	if($bb_cfg['ocelot_enabled'])
	{
		if($row = DB()->fetch_row("SELECT info_hash FROM ". BB_BT_TORRENTS ." WHERE topic_id = $topic_id LIMIT 1"))
		{
			$info_hash = $row['info_hash'];
		}
		update_tracker('update_torrent', array('info_hash' => rawurlencode($info_hash), 'freetorrent' => $tor_status_gold));
	}
	//Ocelot stuff
}

function tracker_register ($attach_id, $mode = '', $tor_status = TOR_NOT_APPROVED, $reg_time = TIMENOW)
{
	global $bb_cfg, $lang, $reg_mode, $tr_cfg;

	$attach_id = intval($attach_id);
	$reg_mode = $mode;

	if (!$torrent = get_torrent_info($attach_id))
	{
		bb_die($lang['TOR_NOT_FOUND']);
	}

	$post_id   = $torrent['post_id'];
	$topic_id  = $torrent['topic_id'];
	$forum_id  = $torrent['forum_id'];
	$poster_id = $torrent['poster_id'];
	$info_hash = null;
	
	if ($torrent['extension'] !== TORRENT_EXT) return torrent_error_exit($lang['NOT_TORRENT']);
	if (!$torrent['allow_reg_tracker']) return torrent_error_exit($lang['REG_NOT_ALLOWED_IN_THIS_FORUM']);
	if ($post_id != $torrent['topic_first_post_id']) return torrent_error_exit($lang['ALLOWED_ONLY_1ST_POST_REG']);
	if ($torrent['tracker_status']) return torrent_error_exit($lang['ALREADY_REG']);
	if ($this_topic_torrents = get_registered_torrents($topic_id, 'topic')) return torrent_error_exit($lang['ONLY_1_TOR_PER_TOPIC']);

	torrent_auth_check($forum_id, $torrent['poster_id']);

	$filename = get_attachments_dir() .'/'. $torrent['physical_filename'];

	if (!is_file($filename)) return torrent_error_exit('File name error');
	if (!file_exists($filename)) return torrent_error_exit('File not exists');
	if (!$tor = bdecode_file($filename)) return torrent_error_exit('This is not a bencoded file');

	if ($bb_cfg['bt_disable_dht'])
	{
		$tor['info']['private'] = (int) 1;
		$fp = fopen($filename, 'w+');
		fwrite ($fp, bencode($tor));
		fclose ($fp);
	}

	if ($bb_cfg['bt_check_announce_url'])
	{
		include(INC_DIR .'torrent_announce_urls.php');

		$ann = (@$tor['announce']) ? $tor['announce'] : '';
		$announce_urls['main_url'] = $bb_cfg['bt_announce_url'];

		if (!$ann || !in_array($ann, $announce_urls))
		{
			$msg = sprintf($lang['INVALID_ANN_URL'], htmlspecialchars($ann), $announce_urls['main_url']);
			return torrent_error_exit($msg);
		}
	}

	$info = (@$tor['info']) ? $tor['info'] : array();

	if (!isset($info['name']) || !isset($info['piece length']) || !isset($info['pieces']) || strlen($info['pieces']) % 20 != 0)
	{
		return torrent_error_exit($lang['TORFILE_INVALID']);
	}

	$info_hash     = pack('H*', sha1(bencode($info)));
	$info_hash_sql = rtrim(DB()->escape($info_hash), ' ');
	$info_hash_md5 = md5($info_hash);

	//Ocelot stuff
	if($bb_cfg['ocelot_enabled'])
	{
		update_tracker('add_torrent', array('info_hash' => rawurlencode($info_hash), 'id' => $topic_id, 'freetorrent' => 0));
	}
	//Ocelot stuff


	if ($row = DB()->fetch_row("SELECT topic_id FROM ". BB_BT_TORRENTS ." WHERE info_hash = '$info_hash_sql' LIMIT 1"))
	{
		$msg = sprintf($lang['BT_REG_FAIL_SAME_HASH'], TOPIC_URL . $row['topic_id']);
		bb_die($msg);
		set_die_append_msg($forum_id, $topic_id);
	}

	$totallen = 0;

	if (isset($info['length']))
	{
		$totallen = (float) $info['length'];
	}
	else if (isset($info['files']) && is_array($info['files']))
	{
		foreach ($info['files'] as $fn => $f)
		{
			$totallen += (float) $f['length'];
		}
	}
	else
	{
		return torrent_error_exit($lang['TORFILE_INVALID']);
	}

	$size = sprintf('%.0f', (float) $totallen);

	$columns = ' info_hash,       post_id,  poster_id,  topic_id,  forum_id,  attach_id,    size,  reg_time,  tor_status';
	$values = "'$info_hash_sql', $post_id, $poster_id, $topic_id, $forum_id, $attach_id, '$size', $reg_time, $tor_status";

	$sql = "INSERT INTO ". BB_BT_TORRENTS ." ($columns) VALUES ($values)";

	if (!DB()->sql_query($sql))
	{
		$sql_error = DB()->sql_error();

		if ($sql_error['code'] == 1062) // Duplicate entry
		{
			return torrent_error_exit($lang['BT_REG_FAIL_SAME_HASH']);
		}
		message_die(GENERAL_ERROR, 'Could not register torrent on tracker', '', __LINE__, __FILE__, $sql);
	}

	// update tracker status for this attachment
	$sql = 'UPDATE '. BB_ATTACHMENTS_DESC ." SET tracker_status = 1 WHERE attach_id = $attach_id LIMIT 1";

	if (!DB()->sql_query($sql))
	{
		message_die(GENERAL_ERROR, 'Could not update torrent status', '', __LINE__, __FILE__, $sql);
	}

	// set DL-Type for topic
	if ($bb_cfg['bt_set_dltype_on_tor_reg'])
	{
		$sql = 'UPDATE '. BB_TOPICS .' SET topic_dl_type = '. TOPIC_DL_TYPE_DL ." WHERE topic_id = $topic_id LIMIT 1";

		if (!$result = DB()->sql_query($sql))
		{
			message_die(GENERAL_ERROR, 'Could not update topics table', '', __LINE__, __FILE__, $sql);
		}
	}

	if ($tr_cfg['tor_topic_up'])
	{
		DB()->query("UPDATE ". BB_TOPICS ." SET topic_last_post_time = GREATEST(topic_last_post_time, ". (TIMENOW - 3*86400) .") WHERE topic_id = $topic_id LIMIT 1");
	}

	if ($reg_mode == 'request' || $reg_mode == 'newtopic')
	{
		set_die_append_msg($forum_id, $topic_id);
		$mess = sprintf($lang['BT_REGISTERED'], DOWNLOAD_URL . $attach_id);
		bb_die($mess);
	}
	
	if($bb_cfg['portal_enabled'])
	{
		CACHE('portal')->rm();
		CACHE('bb_feed')->rm();
	}
	
	return;
}

function send_torrent_with_passkey ($filename)
{
	global $attachment, $auth_pages, $userdata, $bb_cfg, $lang;

	if (!$bb_cfg['bt_add_auth_key'] || $attachment['extension'] !== TORRENT_EXT || !$size = @filesize($filename))
	{
		return;
	}

	$post_id = $poster_id = $passkey_val = '';
	$user_id = $userdata['user_id'];
	$attach_id = $attachment['attach_id'];

	if (!$passkey_key = $bb_cfg['passkey_key'])
	{
		message_die(GENERAL_ERROR, 'Could not add passkey (wrong config $bb_cfg[\'passkey_key\'])');
	}

	// Get $post_id & $poster_id
	foreach ($auth_pages as $rid => $row)
	{
		if ($row['attach_id'] == $attach_id)
		{
			$post_id = $row['post_id'];
			$poster_id = $row['user_id_1'];
			break;
		}
	}

	// Get $topic_id
	$topic_id_sql = 'SELECT topic_id FROM ' . BB_POSTS . ' WHERE post_id = ' . (int) $post_id;
	if (!($topic_id_result = DB()->sql_query($topic_id_sql)))
	{
		message_die(GENERAL_ERROR, 'Could not query post information', '', __LINE__, __FILE__, $topic_id_sql);
	}
	$topic_id_row = DB()->sql_fetchrow($topic_id_result);
	$topic_id = $topic_id_row['topic_id'];

	if (!$attachment['tracker_status'])
	{
		message_die(GENERAL_ERROR, $lang['PASSKEY_ERR_TOR_NOT_REG']);
	}

	if (bf($userdata['user_opt'], 'user_opt', 'dis_passkey') && !IS_GUEST)
	{
		message_die(GENERAL_ERROR, 'Could not add passkey');
	}

	if ($bt_userdata = get_bt_userdata($user_id))
	{
		$passkey_val = $bt_userdata['auth_key'];
	}

	if (!$passkey_val)
	{
		if (!$passkey_val = generate_passkey($user_id))
		{
			bb_simple_die('Could not generate passkey');
		}
		elseif($bb_cfg['ocelot_enabled'])
		{
			update_tracker('add_user', array('id' => $user_id ,'passkey' => $passkey_val));
		}
	}

	// Ratio limits
	$min_ratio = $bb_cfg['bt_min_ratio_allow_dl_tor'];

	if ($min_ratio && $user_id != $poster_id && ($user_ratio = get_bt_ratio($bt_userdata)) !== null)
	{
		if ($user_ratio < $min_ratio && $post_id)
		{
			$dl = DB()->fetch_row("
				SELECT dl.user_status
				FROM ". BB_POSTS ." p
				LEFT JOIN ". BB_BT_DLSTATUS ." dl ON dl.topic_id = p.topic_id AND dl.user_id = $user_id
				WHERE p.post_id = $post_id
				LIMIT 1
			");

			if (!isset($dl['user_status']) || $dl['user_status'] != DL_STATUS_COMPLETE)
			{
				$mess = sprintf($lang['BT_LOW_RATIO_FOR_DL'], round($user_ratio, 2), "search.php?dlu=$user_id&amp;dlc=1");
				message_die(GENERAL_ERROR, $mess);
			}
		}
	}

	// Announce URL
	$ann_url = $bb_cfg['bt_announce_url'];

	if (!$tor = bdecode_file($filename))
	{
		message_die(GENERAL_ERROR, 'This is not a bencoded file');
	}

	$announce = $bb_cfg['ocelot_enabled'] ? strval($bb_cfg['ocelot_url']. "$passkey_val"."/announce") : strval($ann_url . "?$passkey_key=$passkey_val");

	// Replace original announce url with tracker default
	if ($bb_cfg['bt_replace_ann_url'] || !@$tor['announce'])
	{
		$tor['announce'] = $announce;
		unset($tor['announce-list']);
	}
		


	// Add publisher & topic url
	$publisher_name = $bb_cfg['server_name'];
	$publisher_url  = make_url(TOPIC_URL . $topic_id);

	$tor['publisher'] = strval($publisher_name);
	unset($tor['publisher.utf-8']);

	$tor['publisher-url'] = strval($publisher_url);
	unset($tor['publisher-url.utf-8']);

	$tor['comment'] = strval($publisher_url);
	unset($tor['comment.utf-8']);

	// Send torrent
	$output   = bencode($tor);
	$dl_fname = ($bb_cfg['torrent_name_style'] ? '['.$bb_cfg['server_name'].'].t' . $topic_id . '.torrent' : clean_filename(basename($attachment['real_filename'])));

	if (!empty($_COOKIE['explain']))
	{
		$out = "attach path: $filename<br /><br />";
		$tor['info']['pieces'] = '[...] '. strlen($tor['info']['pieces']) .' bytes';
		$out .= print_r($tor, true);
		bb_die("<pre>$out</pre>");
	}

	header("Content-Type: application/x-bittorrent; name=\"$dl_fname\"");
	header("Content-Disposition: attachment; filename=\"$dl_fname\"");

	bb_exit($output);
}

function generate_passkey ($user_id, $force_generate = false)
{
	global $lang, $sql, $bb_cfg;

	$user_id = (int) $user_id;

	// Check if user can change passkey
	if (!$force_generate)
	{
		$sql = "SELECT user_opt FROM ". BB_USERS ." WHERE user_id = $user_id LIMIT 1";

		if (!$result = DB()->sql_query($sql))
		{
			message_die(GENERAL_ERROR, 'Could not query userdata for passkey', '', __LINE__, __FILE__, $sql);
		}
		if ($row = DB()->sql_fetchrow($result))
		{
			if (bf($row['user_opt'], 'user_opt', 'dis_passkey'))
			{
				message_die(GENERAL_MESSAGE, $lang['NOT_AUTHORISED']);
			}
		}
	}

	for ($i=0; $i < 20; $i++)
	{
		$passkey_val = make_rand_str(BT_AUTH_KEY_LENGTH);
		$old_pass = null;
		
		if($row = DB()->fetch_row("SELECT auth_key FROM ". BB_BT_USERS ." WHERE user_id = $user_id LIMIT 1"))
		{
			$old_pass = $row['auth_key'];
		}
		// Insert new row
		DB()->query("INSERT IGNORE INTO ". BB_BT_USERS ." (user_id, auth_key) VALUES ($user_id, '$passkey_val')");

		if (DB()->affected_rows() == 1)
		{
			return $passkey_val;
		}
		// Update
		DB()->query("UPDATE IGNORE ". BB_BT_USERS ." SET auth_key = '$passkey_val' WHERE user_id = $user_id LIMIT 1");

		if (DB()->affected_rows() == 1)
		{
			//Ocelot stuff
			if($bb_cfg['ocelot_enabled'])
			{
				update_tracker('change_passkey', array('oldpasskey' => $old_pass ,'newpasskey' => $passkey_val));
			}
			//Ocelot stuff
			return $passkey_val;
		}
	}
	return false;
}

function tracker_rm_torrent ($topic_id)
{
	return DB()->sql_query("DELETE FROM ". BB_BT_TRACKER ." WHERE topic_id = ". (int) $topic_id);
}

function tracker_rm_user ($user_id)
{
	return DB()->sql_query("DELETE FROM ". BB_BT_TRACKER ." WHERE user_id = ". (int) $user_id);
}

function get_registered_torrents ($id, $mode)
{
	$field = ($mode == 'topic') ? 'topic_id' : 'post_id';

	$sql = "SELECT topic_id FROM ". BB_BT_TORRENTS ." WHERE $field = $id LIMIT 1";

	if (!$result = DB()->sql_query($sql))
	{
		message_die(GENERAL_ERROR, 'Could not query torrent id', '', __LINE__, __FILE__, $sql);
	}

	if ($rowset = @DB()->sql_fetchrowset($result))
	{
		return $rowset;
	}
	else
	{
		return false;
	}
}

function torrent_error_exit ($message)
{
	global $reg_mode, $return_message, $lang;

	$msg = '';

	if (isset($reg_mode) && ($reg_mode == 'request' || $reg_mode == 'newtopic'))
	{
		if (isset($return_message))
		{
			$msg .= $return_message .'<br /><br /><hr /><br />';
		}
		$msg .= '<b>'. $lang['BT_REG_FAIL'] .'</b><br /><br />';
	}

	bb_die($msg . $message);
}

// bdecode: based on OpenTracker [http://whitsoftdev.com/opentracker]
function bdecode_file ($filename)
{
	$file_contents = file_get_contents($filename);
	return bdecode($file_contents);
}

function bdecode ($str)
{
	$pos = 0;
	return bdecode_r($str, $pos);
}

function bdecode_r ($str, &$pos)
{
	$strlen = strlen($str);

	if (($pos < 0) || ($pos >= $strlen))
	{
		return null;
	}
	else if ($str[$pos] == 'i')
	{
		$pos++;
		$numlen = strspn($str, '-0123456789', $pos);
		$spos = $pos;
		$pos += $numlen;

		if (($pos >= $strlen) || ($str[$pos] != 'e'))
		{
			return null;
		}
		else
		{
			$pos++;
			return floatval(substr($str, $spos, $numlen));
		}
	}
	else if ($str[$pos] == 'd')
	{
		$pos++;
		$ret = array();

		while ($pos < $strlen)
		{
			if ($str[$pos] == 'e')
			{
				$pos++;
				return $ret;
			}
			else
			{
				$key = bdecode_r($str, $pos);

				if ($key === null)
				{
					return null;
				}
				else
				{
					$val = bdecode_r($str, $pos);

					if ($val === null)
					{
						return null;
					}
					else if (!is_array($key))
					{
						$ret[$key] = $val;
					}
				}
			}
		}
		return null;
	}
	else if ($str[$pos] == 'l')
	{
		$pos++;
		$ret = array();

		while ($pos < $strlen)
		{
			if ($str[$pos] == 'e')
			{
				$pos++;
				return $ret;
			}
			else
			{
				$val = bdecode_r($str, $pos);

				if ($val === null)
				{
					return null;
				}
				else
				{
					$ret[] = $val;
				}
			}
		}
		return null;
	}
	else
	{
		$numlen = strspn($str, '0123456789', $pos);
		$spos = $pos;
		$pos += $numlen;

		if (($pos >= $strlen) || ($str[$pos] != ':'))
		{
			return null;
		}
		else
		{
			$vallen = intval(substr($str, $spos, $numlen));
			$pos++;
			$val = substr($str, $pos, $vallen);

			if (strlen($val) != $vallen)
			{
				return null;
			}
			else
			{
				$pos += $vallen;
				return $val;
			}
		}
	}
}


function update_tracker($Action, $Updates)
{
	global $bb_cfg;
	
	$Get = $bb_cfg['ocelot_secret']. "/update?action=$Action";
	
	foreach ($Updates as $Key => $Value)
	{
		$Get .= "&$Key=$Value";
	}

	$MaxAttempts = 3;
	$Err = false;	
	if (send_request($Get, $MaxAttempts, $Err) === false)
	{
		return false;
	}
	return true;
}

function send_request($Get, $MaxAttempts = 1, &$Err = false)
{
	global $bb_cfg;
	
	$Header = "GET /$Get HTTP/1.1\r\nConnection: Close\r\n\r\n";
	
	$Attempts = $Sleep = $Success = $Response = 0;
	$StartTime = microtime(true);
	
	while (!$Success && $Attempts++ < $MaxAttempts)
	{
		if ($Sleep)
		{
			sleep($Sleep);
		}
		// Send request
		$File = fsockopen($bb_cfg['ocelot_host'], 34000, $ErrorNum, $ErrorString);
		if ($File)
		{
			if (fwrite($File, $Header) === false)
			{
				$Err = "Failed to fwrite()";
				$Sleep = 3;
				continue;
			}
		}
		else
		{
			$Err = "Failed to fsockopen() - $ErrorNum - $ErrorString";
			$Sleep = 6;
			continue;
		}

		// Check for response.
		while (!feof($File))
		{
			$Response .= fread($File, 1024);
		}
		$DataStart = strpos($Response, "\r\n\r\n") + 4;
		$DataEnd = strrpos($Response, "\n");
		if ($DataEnd > $DataStart)
		{
			$Data = substr($Response, $DataStart, $DataEnd - $DataStart);
		}
		else
		{
			$Data = "";
		}
		$Status = substr($Response, $DataEnd + 1);
		if ($Status == "success")
		{
			$Success = true;
		}
	}
	return $Success;
}