package  {
	
	import flash.display.MovieClip;
	import flash.display.DisplayObject;
	import flash.display.Loader;
	import flash.events.Event;
	import flash.events.HTTPStatusEvent;
	import flash.events.IOErrorEvent;
	import flash.net.URLRequest;
	import flash.net.URLLoader;
	import flash.net.URLVariables;
	import flash.system.Security;
	
	public class YouTubePlayer extends MovieClip {
		// Member variables.
		private var playerLoader:Loader;
		private var ytApiLoader:URLLoader;
		public var player:Object;
		private var errorResponders:Array;
		
		private var currVideoId:String;
		private var nextVideoIds:Array;
		private var isQualityPopulated:Boolean;
		private var isWidescreen:Boolean;
		private var autoplay:Boolean;
		
		private var timeOffset:Number;
		private var currentTime:Number;
		
		// Has the player already started for the first time?
		private var firstPlayed:Boolean = false;
		
		// CONSTANTS.
		private static const DEFAULT_VIDEO_ID:String = "0QRO3gKj3qw";
		private static const PLAYER_URL:String =
			"http://www.youtube.com/apiplayer?version=3";
		private static const SECURITY_DOMAIN:String = "http://www.youtube.com";
		private static const YOUTUBE_API_PREFIX:String =
			"http://gdata.youtube.com/feeds/api/videos/";
		private static const YOUTUBE_API_VERSION:String = "2";
		private static const YOUTUBE_API_FORMAT:String = "5";
		private static const WIDESCREEN_ASPECT_RATIO:String = "widescreen";
		private static const QUALITY_TO_PLAYER_WIDTH:Object = {
			small: 320,
			medium: 640,
			large: 854,
			hd720: 1280
		};
		private static const STATE_UNSTARTED:Number = -1;
		private static const STATE_ENDED:Number = 0;
		private static const STATE_PLAYING:Number = 1;
		private static const STATE_PAUSED:Number = 2;
		private static const STATE_BUFFERING:Number = 3;
		private static const STATE_CUED:Number = 5;
		private static const ERROR_NO_EMBED:Number = 150;

		public function YouTubePlayer() {
			// constructor code
			addEventListener(Event.ADDED_TO_STAGE, onAddedToStage);
			
			//playerLoader = new Loader();
			//playerLoader.contentLoaderInfo.addEventListener(Event.INIT, playerLoader_onInit, false, 0, true);
			//playerLoader.load(new URLRequest("http://www.youtube.com/apiplayer?version=3"));
		}
		
		private function onAddedToStage(event:Event):void {
			// Specifically allow the chromeless player .swf access to our .swf.
			Security.allowDomain(SECURITY_DOMAIN);
			
			setupUi();
			setupSettings();
			setupPlayerLoader();
			setupYouTubeApiLoader();
		}
		
		// Initialize functions.
		private function setupUi():void {
			nextVideoIds = new Array();
			currVideoId = DEFAULT_VIDEO_ID;
			errorResponders = new Array();
		}
		
		private function setupSettings():void {
			
		}
		
		private function setupPlayerLoader():void {
			playerLoader = new Loader();
			playerLoader.contentLoaderInfo.addEventListener(Event.INIT, playerLoader_onInit);
			playerLoader.contentLoaderInfo.addEventListener(HTTPStatusEvent.HTTP_STATUS, playerLoader_onHTTPStatus);
			playerLoader.load(new URLRequest(PLAYER_URL));
		}
		
		private function setupYouTubeApiLoader():void {
			ytApiLoader = new URLLoader();
			ytApiLoader.addEventListener(Event.COMPLETE, ytApiLoader_onComplete);
			ytApiLoader.addEventListener(IOErrorEvent.IO_ERROR, ytApiLoader_onError);
		}
		
		// playerLoader event handlers.
		private function playerLoader_onInit(event:Event):void {
			player = playerLoader.content;
			player.addEventListener("onReady", player_onReady, false, 0, true);
			player.addEventListener("onError", player_onError);
			player.addEventListener("onStateChange", player_onStateChange);
			player.addEventListener("onPlaybackQualityChange", 
				player_onVideoPlaybackQualityChange);
			addChild(DisplayObject(player));
			
			playerLoader.contentLoaderInfo.removeEventListener(Event.INIT, playerLoader_onInit);
			playerLoader = null;
		}
		
		private function playerLoader_onHTTPStatus(event:HTTPStatusEvent):void {
			if( event.status != 200 )
			{
				trace("Error connecting to YouTube API:", event);
			}
		}
		
		private function player_onReady(event:Event):void {
			player.removeEventListener("onReady", player_onReady);
			
			// Event.data contains the event parameter, which is the Player API ID 
			trace("player ready:", Object(event).data);
			
			// Once this event has been dispatched by the player, we can use
			// cueVideoById, loadVideoById, cueVideoByUrl and loadVideoByUrl
			// to load a particular YouTube video.
			//player.cueVideoById("0PE9XU6uKW0"); // Redef demo.
			
			// Set appropriate player dimensions for your application.
			player.setSize(640, 480);
			//player.cueVideoById("D2gqThOfHu4"); // Poison sockets.
		}
		
		private function player_onError(event:Event):void {
			// Event.data contains the event parameter, which is the error code
			trace("player error:", Object(event).data);
			
			if( Object(event).data == ERROR_NO_EMBED ) {
				// Send error message to server with video id.
				for each( var func:Function in errorResponders ) {
					func(currVideoId);
				}
				
				cueNextVideo(true);
			}
		}
		
		private function player_onStateChange(event:Event):void {
			// Event.data contains the event parameter, which is the new player state
			trace("player state:", Object(event).data);
			
			if( Object(event).data == STATE_ENDED && nextVideoIds.length > 0) {
				trace("playerHead:", player.getCurrentTime());
				cueNextVideo(true);
			}
			
			if( Object(event).data == STATE_BUFFERING && !firstPlayed )
				playVideo();
		}
		
		private function player_onVideoPlaybackQualityChange(event:Event):void {
			// Event.data contains the event parameter, which is the new video quality
			trace("video quality:", Object(event).data);
			resizePlayer(Object(event).data);
		}
		
		private function resizePlayer(qualityLevel:String):void {
			var newWidth:Number = QUALITY_TO_PLAYER_WIDTH[qualityLevel] || 640;
			var newHeight:Number;
			
			if (isWidescreen) {
				// Widescreen videos (usually) fit into a 16:9 player.
				newHeight = newWidth * 9 / 16;
			} else {
				// Non-widescreen videos fit into a 4:3 player.
				newHeight = newWidth * 3 / 4;
			}
			
			trace("isWidescreen is", isWidescreen, ". Size:", newWidth, newHeight);
			player.setSize(newWidth, newHeight);
			
			player.visible = true;
		}
		
		// ytApiLoader event handlers.
		private function ytApiLoader_onComplete(event:Event):void {
			var atomData:String = ytApiLoader.data;
			
			// Parse the YouTube API XML response and get the value of the
			// aspectRatio element.
			var atomXml:XML = new XML(atomData);
			var aspectRatios:XMLList = atomXml..*::aspectRatio;
			
			isWidescreen = aspectRatios.toString() == WIDESCREEN_ASPECT_RATIO;
			
			isQualityPopulated = false;
			// Cue up the video once we know whether it's widescreen.
			// Alternatively, you could start playing instead of cueing with
			// player.loadVideoById(videoIdTextInput.text);
			player.cueVideoById(currVideoId);
			
			if( autoplay )
				player.playVideo();
		}
		
		private function ytApiLoader_onError(event:IOErrorEvent):void {
			trace("Error making YouTube API request:", event);
		}
		
		private function cueNextVideo(playVideo:Boolean = false):void {
			player.destroy();
			cueVideoById(nextVideoIds.shift());
			
			trace("cueNextVideo");
			
			if( playVideo )
				autoplay = true;
		}
		
		// Exposed functions.
		public function cueVideoById(videoId:String):void {
			currVideoId = videoId;
			
			var request:URLRequest = new URLRequest(YOUTUBE_API_PREFIX + videoId);
			
			var urlVariables:URLVariables = new URLVariables();
			urlVariables.v = YOUTUBE_API_VERSION;
			urlVariables.format = YOUTUBE_API_FORMAT;
			request.data = urlVariables;
			
			try {
				ytApiLoader.load(request);
			} catch (error:SecurityError) {
				trace("A SecurityError occurred while loading", request.url);
			}
		}
		
		public function cueVideosById(vid:*) {
			if( vid is Array )
				nextVideoIds = vid;
			else if( vid is String )
				nextVideoIds = vid.split(",");
			else {
				nextVideoIds = [];
				return;
			}
			
			if( player.getPlayerState() == STATE_UNSTARTED || (!firstPlayed && currVideoId != nextVideoIds[0]) ) {
				cueNextVideo();
			}
		}
		
		public function seekTo(seconds:Number, allowSeekAhead:Boolean = false):void {
			player.seekTo(seconds, allowSeekAhead);
			
			/*var varName:String;
			for( varName in player ) {
				trace("property", varName, ":", player[varName]);
			}*/
		}
		
		public function playVideo():void {
			var now:Date = new Date();
			timeOffset += (Math.round(now.getTime()/1000)-currentTime);
			player.seekTo(timeOffset, true);
			
			firstPlayed = true;
		}
		
		public function pauseVideo():void {
			player.pauseVideo();
		}
		
		public function setCurrentTime(newTime:Number):void {
			var now:Date = new Date();
			currentTime = Math.round(now.getTime()/1000);
			
			timeOffset = newTime;
		}
		
		public function getCurrentTime():Number {
			return player.getCurrentTime();
		}
		
		public function addErrorResponder(callback:Function):void {
			errorResponders.push(callback);
		}
		
		public function removeErrorResponder(callback:Function):void {
			var index = errorResponders.indexOf(callback);
			if( index != -1 )
				errorResponders.splice(index, index);
		}
	}
	
}
