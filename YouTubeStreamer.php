<?php
/*
Plugin Name: YouTube Streamer (Prototype)
Description: Streams a feed or video search from Youtube in a television-like format.
Version: 0.10
Author:  Jeffrey Johnson
License: GPL2

	Copyright 2010 Jeffrey Johnson  (email : ofShard@gmail.com)

	 This program is free software; you can redistribute it and/or modify
	 it under the terms of the GNU General Public License, version 2, as 
	 published by the Free Software Foundation.

	 This program is distributed in the hope that it will be useful,
	 but WITHOUT ANY WARRANTY; without even the implied warranty of
	 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	 GNU General Public License for more details.

	 You should have received a copy of the GNU General Public License
	 along with this program; if not, write to the Free Software
	 Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA

*/

global $yts_db_version;
$yts_db_version = "0.3";

global $yts_saving_post;
$yts_saving_post = false;

global $wpdb;
define('YTS_CHANNELS_NAME', $wpdb->prefix . 'yts_channels');

define('YTS_VIDEOS_NAME', $wpdb->prefix . 'yts_videos');

define('YTS_SEARCH_SIZE', 10);

require_once("functions.php");

register_activation_hook(__FILE__, 'yts_db_install');
function yts_db_install () {
	global $wpdb;
	global $yts_db_version;
	
	$channels_sql = "CREATE TABLE ".YTS_CHANNELS_NAME." (
		channel_id varchar(32) NOT NULL UNIQUE,
		type varchar(32) NOT NULL,
		child_ids varchar(1024),
		child_times varchar(1024) DEFAULT '0',
		rules varchar(1024),
		query_time decimal(14,3) unsigned,
		query_index smallint unsigned DEFAULT 1
		);";
	
	// channel_id :	unique identifier of channel/group.
	// type :			type of group.
	// 						group, yt_videos
	// child_ids :		comma-delimited list of children ids.
	// child_times :	comma-delimited list of the starting times of child_ids. Only necessary if you're not playing the default duration.
	// rules :			comma-delimited list of rules in $rule=$value format.
	// query_time :	when this channel/group was last refreshed, in seconds (plus fractions) since Epoch.
	// query_index :	for yt_videos, the index of the last YouTube query, for when the next query is made.
	
	$videos_sql = "CREATE TABLE ".YTS_VIDEOS_NAME." (
		video_id varchar(32) NOT NULL UNIQUE,
		type varchar(64) DEFAULT 'youtube',
		title varchar(128),
		duration smallint unsigned
		);";
	
	// video_id :	identifier of video. Could also be a source path, etc., depending on type.
	// type :		type of video.
	// 					youtube, url
	// title :		title of video.
	// duration :	length of video in seconds.
	
	if(	$wpdb->get_var("show tables like '".YTS_CHANNELS_NAME."'") != YTS_CHANNELS_NAME
			// || $wpdb->get_var("show tables like '".YTS_VIDEOS_NAME."'") != YTS_VIDEOS_NAME
			) {
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

		dbDelta($channels_sql);

		//dbDelta($videos_sql);
 
		add_option("yts_db_version", $yts_db_version);

	} else if( get_option("yts_db_version") != $yts_db_version ) {
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

		dbDelta($channels_sql);

		//dbDelta($videos_sql);
		
		update_option("yts_db_version", $yts_db_version);
	}
}

add_action('plugins_loaded', 'yts_loaded');
function yts_loaded()
{
	define('YTS_PLUGIN_FOLDER',str_replace('\\','/',dirname(__FILE__)));
	define('YTS_PLUGIN_PATH', plugin_dir_url(__FILE__));
	
	define('YTS_META_BOX_NAME','YouTube Streamer');
	
	add_action('admin_init', 'yts_init');
}

function yts_init()
{
	add_meta_box('yts_MetaBox', __(YTS_META_BOX_NAME, 'yts'), 'yts_createMetaBox', 'post', 'normal', 'high');
	add_meta_box('yts_MetaBox', __(YTS_META_BOX_NAME, 'yts'), 'yts_createMetaBox', 'page', 'normal', 'high');
	
	add_action('save_post', 'yts_savePost');
}

function yts_createMetaBox()
{
?><div class="yts_post_control">Hello</div>
<?php
}

function yts_savePost($post_id)
{
	global $wpdb, $yts_saving_post;
	
	if( $yts_saving_post )
		return;
	
	$yts_saving_post = true;
	
	$post = get_post($post_id, ARRAY_A);
	$content = $post['post_content'];
	
	$_postid = ''.$_POST['ID'];
	//$post_id = $_POST['ID'];
	
	//die('test: '.$_postid.' ');
	
	$pattern = '/(.?)\[(yts)\b(.*?)(?:(\/))?\](?:(.+?)\[\/\2\])?(.?)/i';
	
	$default_id_count = 0;
	
	$sql = '';
	
	if( preg_match_all($pattern, $content, $m) ) {
		foreach($m as $key => $match) {
			// allow [[foo]] syntax for escaping a tag
			if ($m[1][$key] == '[' && $m[6][$key] == ']') {
				//return substr($m[0][$key], 1, -1);
				continue;
			}
			
			$tag = $m[2][$key];
			$atts = shortcode_parse_atts($m[3][$key]);

			/*
			if ( isset($m[5][$key]) ) {
				// enclosing tag - extra parameter
				return $m[1][$key] . yts_shortcode($atts, $m[5][$key], $m[2][$key]) . $m[6][$key];
			} else {
				// self-closing tag
				return $m[1][$key] . yts_shortcode($atts, NULL, $m[2][$key]) . $m[6][$key];
			} */
			
			extract( shortcode_atts( array(
				'search' => '_default',
				'id' => '_default',
				'reset_index' => '_default'
				), $atts ) );
			
			if( $search == '_default' )
				return;
			
			$rules = "search=$search,reset_index=$reset_index";
			
			if( $id == '_default' ) {
				$default_id_count++;
				$id = ($default_id_count == 1) ? $_postid : ($_postid.'_'.$default_id_count);
			}
			
			// If rules have changed or the channel doesn't exist, update/create it.
			
			if( $wpdb->get_var("show tables like '".YTS_CHANNELS_NAME."'") != YTS_CHANNELS_NAME )
				return; // Table does not exist.
			
			$channel = yts_get_channel($id);
			
			if( !isset($channel) || $channel['rules'] != $rules ) {
				// Get video data from YouTube.
				$yt_results = yts_query_youtube($search, YTS_SEARCH_SIZE);
				
				$v_ids = '';
				$v_durs = '';
				
				foreach( $yt_results as $key => $match ) {
					if( $key > 0 ) {
						$v_ids .= ',';
						$v_durs .= ',';
					}
					
					$v_ids .= $match['id'];
					$v_durs .= $match['dur'];
				}
				
				$id_y1 = $id . "_y1";
				
				$videos = array('channel_id'=>$id_y1, 'child_ids'=>$v_ids, 'child_times'=>$v_durs, 'rules'=>$rules, 'type'=>'yt_videos');
				
				yts_update_channel(array('channel_id'=>$id, 'child_ids'=>$id_y1, 'query_time'=>time(), 'rules'=>$rules, 'type'=>'group'));
				yts_update_channel(array('channel_id'=>$id_y1, 'child_ids'=>$v_ids, 'child_times'=>$v_durs, 'rules'=>$rules, 'type'=>'yt_videos'));
			} // end if
		} // end foreach
	} // end if
}

add_action( 'wp_print_scripts', 'yts_loadScripts' );
function yts_loadScripts()
{
	wp_enqueue_script('swfobject');
}

add_shortcode('yts', 'yts_shortcode');
function yts_shortcode($atts, $content = null)
{
	global $post;
	
	extract( shortcode_atts( array(
		'search' => '_default',
		'id' => '_default',
		), $atts ) );
	
	if( $search == '_default' )
		return '';
	
	if( $id == '_default' )
		$id = $post->ID;
	
	//$yt_results = yts_query_youtube($search, YTS_SEARCH_SIZE);
	$result = '';
	
	//foreach( $yt_results as $key => $match ) {
		//$result .= "title: {$match['title']} id: {$match['id']} dur: {$match['dur']} <br/>";
	//}
	
	$swf_url = plugins_url('flash/YouTubePlayer.swf', __FILE__);
	$result .= '<script type="text/javascript">
			var flashvars = {channel_id:"'.$id.'", ajax_url:"'.admin_url('admin-ajax.php').'"};
			var params = {};
			var attributes = {};
			swfobject.embedSWF("'.$swf_url.'", "myAlternativeContent", "550", "400", "9.0.0", false, flashvars, params, attributes);
		</script>
		<div id="myAlternativeContent">
			<a href="http://www.adobe.com/go/getflashplayer">
				<img src="http://www.adobe.com/images/shared/download_buttons/get_flash_player.gif" alt="Get Adobe Flash player" />
			</a>
		</div>';
	
	return '<pre>'.$result.'</pre>';
}

add_action( 'wp_ajax_ytsajax', 'yts_ajax' );
add_action( 'wp_ajax_nopriv_ytsajax', 'yts_ajax' );
function yts_ajax()
{
	global $wpdb;
	
	$id = $_POST['channel_id'];
	if( empty($id) )
		die("error=wrong channel id");
	
	if( $_POST['yts_action'] == 'get_channel' ) {
		$v_ids = array();
		$v_durs = array();
		
		$channel = yts_get_channel($id);
		
		if( $channel['type'] == 'group' ) {
			$groups = split(',', $channel['child_ids']);
			
			$iter = 0;
			
			foreach( $groups as $match ) {
				//$videos_sql = $wpdb->prepare("SELECT * FROM ".YTS_CHANNELS_NAME." WHERE channel_id='%s'", $match);
				
				$group = yts_get_channel($match);//$wpdb->get_row($videos_sql, ARRAY_A);
				
				if( $group['type'] == 'yt_videos' && strlen($group['child_ids']) > 0 ) {
					if( $iter > 0 ) {
						//$v_ids .= ',';
						//$v_durs .= ',';
					}
					
					$v_ids = array_merge($v_ids, explode(',', $group['child_ids']));
					$v_durs = array_merge($v_durs, explode(',', $group['child_times']));
					
					// TODO: Add mechanism for groups starting before the last finishes playing.
					// e.g. The user sets search1 for 30 minutes, then search2 after that.
					
					// TODO: Add mechanism for alternating between two rules.
					// e.g. First video from search1, first from search2, second from search1, etc.
					
					$iter++;
				}
				
				// TODO: Add code for groups nested inside groups, for more complicated channels.
			}
		}
		else if( $channel['type'] == 'yt_videos' ) {
			// yt_videos isn't a proper channel and doesn't have a time-tracking mechanism, but we'll return what we have.
			
			echo 'child_ids='.$channel['child_ids'];
			die();
		}
		
		// Time-tracking code.
		$time_diff = microtime(true) - $channel['query_time'];
		$time = $channel_time = $channel['child_times'] + $time_diff;
		
		// Iterate through the video id:duration map, removing any videos off the front that have already played.
		// temp_arr will be an array containing the videos already finished, v_ids will have the videos yet to play through (starting with the currently playing video).
		$temp_arr = array();
		for( $iter = 0;
				$iter < count($v_durs)
				&& $time > $v_durs[$iter]
				&& !empty($v_ids);
				$temp_arr[] = array_shift($v_ids),
				$time-=$v_durs[$iter],
				$iter++ ) ;
		
		// Update times.
		$channel['child_times'] = $channel_time;
		$channel['query_time'] = microtime(true);
		
		// Requery YouTube if our video id list is low.
		if( count($v_ids) <= 1 ) {
			// TODO: Update when we're (almost) out of videos.
			yts_requery_youtube($id, YTS_SEARCH_SIZE+count($v_ids), $group['query_index']+count($temp_arr));
			$test = yts_get_channel($group['channel_id']);
			if( count($v_ids) == 0 ) // If there are no videos left reset the timer.
				$channel['child_times'] = '0';
			else {
				$channel['child_times'] -= array_sum(array_slice($v_durs, 0, $iter));
			}
			
			$child_ids = $test['child_ids'];
		}
		else
			$child_ids = implode(',', $v_ids);
		
		yts_update_channel($channel);
		
		echo "child_ids=".$child_ids;
		echo sprintf("&current_time=%.3f", $time);
		//echo "&durs=".join(',', $v_durs);
		//echo "&query_index=".$group['query_index'];
		//echo "&count=".count($v_ids);
	}
	else if( $_POST['yts_action'] == 'report_embed_error' ) {
		$error_id = $_POST['video_id'];
		
		$channel = yts_get_channel($id);
		
		if( empty( $channel ) )
			die();
		
		if( $channel['type'] != 'group' )
			die();
		
		$groups = split(',', $channel['child_ids']);
		
		$v_ids = array();
		$v_durs = array();
		
		foreach( $groups as $match ) {
			$group = yts_get_channel($match);
			
			if( $group['type'] == 'yt_videos' && strlen($group['child_ids']) > 0 ) {
				$v_ids = array_merge($v_ids, explode(',', $group['child_ids']));
				$v_durs = array_merge($v_durs, explode(',', $group['child_times']));
			}
			
			// TODO: Add code for groups nested inside groups, for more complicated channels.
		}
		
		for( $iter = 0, $dur = 0; $iter < count($v_ids) && $dur < 0+$channel['child_times']; $iter++ ) {
			$dur += 0+$v_durs[$iter];
			
			if( $v_ids[$iter] == $error_id ) {
				if( $dur > 0+$channel['child_times'] ) {
					$channel['child_times'] = "".$dur;
					yts_update_channel($channel);
				}
				break;
			}
		}
	}
	die();
}

?>