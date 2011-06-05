<?php
// YouTube Streamer internal functions.

function yts_get_channel( $channel_id, $format = ARRAY_A )
{
	global $wpdb;
	
	$sql = $wpdb->prepare("SELECT * FROM ".YTS_CHANNELS_NAME." WHERE channel_id='%s'", $channel_id);
	$channel = $wpdb->get_row($sql, $format);
	
	return $channel;
}

function yts_query_youtube( $search, $maxResults = 25, $startIndex = 1, $returnFormat = ARRAY_A )
{
	require_once("HttpSocketInterface.php");
	$host = "gdata.youtube.com";
	$uri = "http://gdata.youtube.com/feeds/api/videos?&q=".$search."&orderby=relevance&format=5&safeSearch=strict&v=2&start-index=$startIndex&max-results=$maxResults";
	
	//die($uri);
	
	$interface = new Http_Socket_Interface();
	$interface->connect($host);
	$interface->write("GET", $uri, "1.1", array("Accept"=>"text/*"));
	$response = $interface->read(true, true);
	
	$pattern = "/.*?<entry.*?<title.*?>(.*?)<.title>.*?<content.*? src=[\"'][^v]+v.([a-zA-Z0-9\-_]{11}).*?[\"'].>.*?<yt:duration seconds=[\"'](\d*?)[\"'].*?<.entry>/";
	// Get video ID matches and duration.
	if( preg_match_all($pattern, $response['body'], $matches) )
	{
		// [0] is whole match, [1] is title, [2] is id, [3] is duration.
		foreach( $matches[1] as $key => $match )
		{
			$result[$key]['title'] = $match;
			$result[$key]['id'] = $matches[2][$key];
			$result[$key]['dur'] = $matches[3][$key];
			//echo $key . ' ' . $result[$key]['dur'] . ' <br />';
		}
	}
	//print_r($result);
	//die();
	
	return $result;
}

function yts_requery_youtube( $channel_id, $maxResults = 25, $startIndex = 0 )
{
	$RESET_INDEX_DEFAULT = 100;
	
	$channel = yts_get_channel($channel_id);
	
	$rules_pattern = '/(.*?)=([^,&]*)[,&]?/';
	
	if( preg_match_all($rules_pattern, $channel['rules'], $matches) === 0 )
		return false;
	
	$rules = array_combine($matches[1], $matches[2]);
	
	$search = $rules['search'];
	$reset_index = (empty($rules['reset_index']) || $rules['reset_index'] == '_default') ? $RESET_INDEX_DEFAULT : $rules['reset_index'];
	
	$v_ids = '';
	$v_durs = '';
	
	if( $channel['type'] == 'yt_videos' ) {
		if( $startIndex === 0 ) // Default value, so we have to check.
			$startIndex = $group['query_index'] + count(explode(',', $group['child_ids']));
		
		if( $startIndex+$maxResults > $reset_index )
			$startIndex = 1;
		
		$result = yts_query_youtube($search, $maxResults, $startIndex);
		
		foreach( $result as $key => $match ) {
			if( $key > 0 ) {
				$v_ids .= ',';
				$v_durs .= ',';
			}
			
			$v_ids .= $match['id'];
			$v_durs .= $match['dur'];
		}
		
		yts_update_channel(array('channel_id'=>$channel_id, 'child_ids'=>$v_ids, 'child_times'=>$v_durs));
	}
	else if( $channel['type'] == 'group' ) {
		$groups = explode(',', $channel['child_ids']);
		
		foreach( $groups as $match ) {
			$group = yts_get_channel($match);
			
			// TODO: Add support for nested groups.
			if( $group['type'] != 'yt_videos' )
				continue;
			
			if( $startIndex === 0 )
				$startIndex = $group['query_index'] + count(explode(',', $group['child_ids']));
			
			if( preg_match_all($rules_pattern, $group['rules'], $matches) === 0 )
				continue;
			
			$rules = array_combine($matches[1], $matches[2]);
			
			$search = $rules['search'];
			$reset_index = (empty($rules['reset_index']) || $rules['reset_index'] == '_default') ? $RESET_INDEX_DEFAULT : $rules['reset_index'];
			
			if( $startIndex+$maxResults > $reset_index )
				$startIndex = 1;
			
			$yt_results = yts_query_youtube($search, $maxResults, $startIndex);
			
			if( empty($yt_results) ) {
				$startIndex = 1;
				$yt_results = yts_query_youtube($search, $maxResults, $startIndex);
			}
			
			foreach( $yt_results as $key => $match ) {
				if( $key > 0 ) {
					$v_ids .= ',';
					$v_durs .= ',';
				}
				
				$v_ids .= $match['id'];
				$v_durs .= $match['dur'];
			}
			
			//die($v_ids);
			$group['child_ids'] = $v_ids;
			$group['child_times'] = $v_durs;
			$group['query_index'] = $startIndex;
			yts_update_channel($group);
		}
	}
}

// $channel must be an ARRAY_A for now.
// This will either insert a new channel or update an existing one.
// TODO: Make recursive (i.e. update child groups).
function yts_update_channel( $channel )
{
	global $wpdb;
	
	if( $channel['type'] == 'group' ) {
		$group_sql = $wpdb->prepare("INSERT ".YTS_CHANNELS_NAME." SET
				channel_id='%s',
				type='group',
				child_ids='%s',
				query_time=%f,
				rules='%s'
				ON DUPLICATE KEY UPDATE
				type='group',
				child_ids='%s',
				child_times='%s',
				query_time=%f,
				rules='%s'",
				$channel['channel_id'], $channel['child_ids'], $channel['query_time'], $channel['rules'],
				$channel['child_ids'], $channel['child_times'], $channel['query_time'], $channel['rules']);
		
		$wpdb->query($group_sql);
	}
	else if( $channel['type'] == 'yt_videos' ) {
		$yt_videos_sql = $wpdb->prepare("INSERT ".YTS_CHANNELS_NAME." SET
				channel_id='%s',
				type='yt_videos',
				child_ids='%s',
				child_times='%s',
				query_time=%d,
				query_index=%d,
				rules='%s'
				ON DUPLICATE KEY UPDATE
				type='yt_videos',
				child_ids='%s',
				child_times='%s',
				query_time=%d,
				query_index=%d,
				rules='%s'",
				$channel['channel_id'], $channel['child_ids'], $channel['child_times'], time(), $channel['query_index'], $channel['rules'],
				$channel['child_ids'], $channel['child_times'], time(), $channel['query_index'], $channel['rules']);
		
		//die($yt_videos_sql);
		
		$wpdb->query($yt_videos_sql);
	}
}

// Returns associative array of video IDs and durations.
// TODO: Make recursive (i.e. don't skip children of type group).
function yts_get_channel_videos( $channel_id, $returnFormat = ARRAY_A )
{
	$ids = array();
	$durs = array();
	
	$channel = yts_get_channel($channel_id);
	
	if( $channel['type'] == 'group' ) {
		$groups = explode(',', $channel['child_ids']);
		
		foreach( $groups as $key => $match ) {
			$group = yts_get_channel($match);
			
			if( $group['type'] == 'yt_videos' && strlen($group['child_ids']) > 0 ) {
				$ids = array_merge($ids, explode(',', $group['child_ids']));
				$durs = array_merge($durs, explode(',', $group['child_times']));
			}
		}
	}
	else if( $channel['type'] == 'yt_videos' && strlen($group['child_ids']) > 0 ) {
		$ids = array_merge($ids, explode(',', $group['child_ids']));
		$durs = array_merge($durs, explode(',', $group['child_times']));
	}
	
	return array('ids'=>$ids, 'durs'=>$durs);
}

?>