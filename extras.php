<?php

/*
// Thanks to a user contributed note on www.php.net/manual/
function xml_to_array( $asString )
{
	$parser = xml_parser_create();
	xml_parser_set_option( $parser, XML_OPTION_CASE_FOLDING, 0 );
	xml_parser_set_option( $parser, XML_OPTION_SKIP_WHITE, 1 );
	xml_parse_into_struct( $parser, $asString, $tags );
	xml_parser_free( $parser );

	$elements = array();
	$stack = array();
	foreach ( $tags as $tag )
	{
		$index = count( $elements );
		if ( $tag['type'] == "complete" || $tag['type'] == "open" )
		{
			$elements[$index] = array();
			$elements[$index]['name'] = $tag['tag'];
			$elements[$index]['attributes'] = empty($tag['attributes']) ? "" : $tag['attributes'];
			$elements[$index]['content']    = empty($tag['value']) ? "" : $tag['value'];
		  
			if ( $tag['type'] == "open" )
			{    # push
				$elements[$index]['children'] = array();
				$stack[count($stack)] = &$elements;
				$elements = &$elements[$index]['children'];
			}
		}

		if ( $tag['type'] == "close" )
		{    # pop
			$elements = &$stack[count($stack) - 1];
			unset($stack[count($stack) - 1]);
		}
	}
	return $elements[0];
}

function xml_array_getTagsByName($parentNode, $name, $sameDepthAndParent = false, $returnFirst = false, $returnAsString = false)
{
	$result = '';
	$results = array();
	$stack = array($parentNode['children']);
	$index = array(0); // Start at 1 for conciseness of for loop.
	
	for( $ele = $stack[0][0]; ; $index[count($stack)-1]++, $ele = $stack[count($stack)-1][$index[count($stack)-1]] )
	{
		;
		
		if( !is_array($ele) )
		{
			$result .= ' up ';
			
			array_pop($stack);
			array_pop($index);
			
			if( !isset($stack[count($stack)-1]) || !isset($index[count($stack)-1]) )
			{
				$result .= ' break';
				break;
			}
			
			continue;
		}
		
		$result .= $ele['name'];
		
		if( strtolower($ele['name']) == strtolower($name) )
		{
			$result .= "\nfound ";
			
			if( $returnFirst )
				return ($returnAsString) ? $result : $ele;
			
			$results[] = $ele;
			
			if( $sameDepthAndParent ) // Don't check children.
			{
				$index[count($stack)-1]++;
				
				for( $ele = $stack[count($stack)-1][$index[count($stack)-1]]; is_array($ele);
						$index[count($stack)-1]++, $ele = $stack[count($stack)-1][$index[count($stack)-1]] )
				{
					$result .= $ele['name'];
					$result .= "\nfound ";
					
					if( $ele['name'] == $name )
						$results[] = $ele;
				}
				
				break; // We found everything with this parent, so we're done.
			}
		}
		
		if( is_array($ele['children']) )
		{
			$stack[] = $ele['children'];
			$index[] = 0;
			
			$result .= " down\n";
		}
	}
	
	if( $returnAsString )
		return $result;
	
	return $results;
} */

/*
function ytsShortcode($atts, $content = null)
{
	/*
	require_once("HttpSocketInterface.php");
	$full = "http://gdata.youtube.com/feeds/api/videos?&q=".$search."&orderby=relevance&format=5&safeSearch=strict&v=2&start-index=1&max-results=3";
	$host = "gdata.youtube.com";
	$path = "/feeds/api/videos";
	$query = "?&q=".$search."&orderby=relevance&format=5&safeSearch=strict&v=2&start-index=1&max-results=3";
	$remote = "tcp://".$host.":80";
	$result = '';
	
	$interface = new Http_Socket_Interface();
	$interface->connect($host);
	$interface->write("GET", 'http://'.$host.$path.$query, "1.1", array("Accept"=>"text/*"));
	$response = $interface->read(true, true);
	
	//$result .= $response['body'];
	
	$pattern = "/.*?<entry.*?<title.*?>(.*?)<.title>.*?<content.*? src=[\"'][^v]+v.([a-zA-Z0-9\-_]{11}).*?[\"'].>.*?<yt:duration seconds=[\"'](\d*?)[\"'].*?<.entry>/";
	// Get video ID matches and duration.
	if( preg_match_all($pattern, $response['body'], $matches) )
	{
		foreach( $matches[1] as $key => $match )
		{
			$result .= $match;
			$result .= ' id: ' . $matches[2][$key];
			$result .= ' dur: ' . $matches[3][$key] . '<br/>';
		}
	} //* /

	//$xml_array = xml_to_array($response['body']);
	//$xml_entries = xml_array_getTagsByName($xml_array, 'entry', true);
	
	//$firstContent = xml_array_getTagsByName($xml_entries[0], 'content', true, true);
	
	//if( preg_match("/^[^v]+v.([a-zA-Z0-9\-_]{11}).*/", $firstContent['attributes']['src'], $matches) );
	//$result .= $matches[1];
	
	//$result .= print_r($xml_entries, true);
	//$result .= print_r($xml_array, true);
	
	return $result;
	
	set_include_path('.:'.YTS_PLUGIN_FOLDER);
	require_once('Zend/Loader.php');
	Zend_Loader::loadClass('Zend_Gdata_YouTube');
	$yt = new Zend_Gdata_YouTube();
	$yt->setMajorProtocolVersion(2);
	$query = $yt->newVideoQuery();
	$query->setOrderBy('relevance');
	$query->setSafeSearch('none');
	$query->setVideoQuery('Madden11');
	
	$videoFeed = $yt->getVideoFeed($query->getQueryUrl(2));
	
	$result = $query->getQueryUrl(2);
	
	$count = 1;
	
	$content = $videoFeed[0]->getFlashPlayerUrl();
	$id = $videoFeed[0]->getVideoId();
	$link = 'default';
	
	extract( shortcode_atts( array(
		'url' => 'default',
		'src' => 'default'
		), $atts ) );
	
	if( (is_null($content) || $content == '') && $url == 'default' && $src == 'default' )
		return $result;
	else if( !is_null($content) && $content != '' )
		$link = $content;
	else if( $url != 'default' )
		$link = $url;
	else if( $src != 'default' )
		$link = $src;
	
	
	$link .= strpos($link,'?') ? '&' : '?';
	
	$result .= '<div id="ytplayeroutercontainer"><div id="ytplayerinnercontainer" style="position:relative; z-index:1;"><div id="ytapiplayer">
    You need Flash player 8+ and JavaScript enabled to view this video.
  </div></div><div style="background: transparent; width:425px; height:356px; position:relative; top:-356px; z-index:2;"
		><a id="ytPlayerOverlay" href="#" style="display:block; width:100%; height:100%;"></a></div></div>

<script type="text/javascript">

function onPlayerStateChange(newState)
{
}

var j = jQuery.noConflict();

function onYouTubePlayerReady(playerId)
{
	ytplayer = j("#ytPlayer")[0];
	ytoverlay = j("#ytPlayerOverlay");
	yt2 = document.id("ytPlayerOverlay");
	yt2.addEvent("click", function() {alert("moo");});
	
	ytplayer.cueVideoById("'.$id.'");
	
	ytoverlay.bind("click", function(e) {
			if( ytplayer.getPlayerState() != 1 )
				ytplayer.playVideo();
			else
				ytplayer.pauseVideo();
			
			e.preventDefault();
		});
	
	ytplayer.addEventListener("onStateChange", "onPlayerStateChange");
}

function loadPlayer()
{
	var params = { allowScriptAccess: "always", wmode: "transparent" };
	var atts = { id: "ytPlayer" };
	swfobject.embedSWF("http://www.youtube.com/apiplayer?&enablejsapi=1&playerapiid=player1", 
			"ytapiplayer", "425", "356", "8", null, null, params, atts);
}

loadPlayer();

</script>';
	
	return $result;
} */

?>