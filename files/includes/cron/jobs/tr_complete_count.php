<?php

if (!defined('BB_ROOT')) die(basename(__FILE__));
// Update TORRENT "completed" counters
DB()->query("
	UPDATE
		". BB_BT_TORRENTS ." tor,
		". BB_BT_TRACKER_SNAP. "      snap
	SET
		tor.complete_count = snap.complete
	WHERE
		tor.topic_id = snap.topic_id
");