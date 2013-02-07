<?php
/**
 * @package DHFTweetInserter
 * @version 0.1
 */
/*
Plugin Name: DHF Tweet Inserter
Plugin URI: http://wordpress.org/extend/plugins/
Description: This is plugin pulls in tweets from twitter with a specific hash tag and creates post for those tweets
Author: Chris Sullivan and Shawn Grimes
Version: 0.1
Author URI: http://www.stemengine.org
*/

register_activation_hook(__FILE__,'tweetInserter_activate');
add_action('insertTweetsEvent','insertTweets');

function tweetInserter_activate(){
	wp_schedule_event(time(),'hourly','insertTweetsEvent');
}


function insertTweets(){

	//Perform a query to get the max _twitterID value

	global $wpdb;


	$querystr="SELECT $wpdb->posts.* 
		FROM $wpdb->posts, $wpdb->postmeta
		WHERE $wpdb->posts.ID = $wpdb->postmeta.post_id 
		AND $wpdb->postmeta.meta_key = '_twitterID' 
		AND $wpdb->postmeta.meta_value > 0 
		ORDER BY $wpdb->postmeta.meta_value DESC
		LIMIT 1";
	
	//echo "<h1>Query String: ".$querystr."</h1>";
	$query = $wpdb->get_results($querystr, OBJECT);
	//echo "<h1>Query Result Count: ".count($query)."</h1>";

	if($query){
		global $post;
		foreach ($query as $post){
			//echo "There ". get_the_ID() . "</br>";
			$twitterID=get_post_meta(get_the_ID(), '_twitterID',true);
			//echo "Twitter ID: $twitterID</br>";
			if($max_twitterID<$twitterID){
				$max_twitterID = $twitterID;
			}
		}
	}

	//echo "<pre>Max twitter iD: $max_twitterID</pre>";

	$api_url = 'http://search.twitter.com/search.json';
	$completedURL=$api_url;
	if($max_twitterID){
		$completedURL="$api_url?q=%23MDLove%20OR%20%23LoveMD&rpp=100&since_id=$max_twitterID&include_entities=1&result_type='recent'";
		$raw_response = wp_remote_get($completedURL); //&since_id=max _twitterID value from query above
	}else{
		$completedURL="$api_url?q=%23MDLove%20OR%20%23LoveMD&rpp=100&include_entities=1&result_type='recent'";
		$raw_response = wp_remote_get($completedURL);
	}

	if ( is_wp_error($raw_response) ) {
		$output = "<p>Failed to update from Twitter!</p>\n";
		$output .= "<!--{$raw_response->errors['http_request_failed'][0]}-->\n";
		$output .= get_option('twitter_hash_tag_cache');
	} else {
		if ( function_exists('json_decode') ) {
			$response = get_object_vars(json_decode($raw_response['body']));
			for ( $i=0; $i < count($response['results']); $i++ ) {
				$response['results'][$i] = get_object_vars($response['results'][$i]);
			}
		} else {
				include(ABSPATH . WPINC . '/js/tinymce/plugins/spellchecker/classes/utils/JSON.php');
				$json = new Moxiecode_JSON();
				$response = @$json->decode($raw_response['body']);
		}
		
		if(!function_exists('_log')){
		  function _log( $message ) {
			if( WP_DEBUG === true ){
			  if( is_array( $message ) || is_object( $message ) ){
				error_log( print_r( $message, true ) );
			  } else {
				error_log( $message );
			  }
			}
		  }
		}

		_log("Twitter Search String: ".$completedURL);
		_log("Twitter Search Result Count: ".count($response['results']));
		
		foreach ( $response['results'] as $result ) {
			$text = $result['text'];
			$user = $result['from_user'];
			$image = $result['profile_image_url'];
			$user_url = "http://twitter.com/$user";
			$source_url = "$user_url/status/{$result['id']}";

			$text = preg_replace('|(https?://[^\ ]+)|', '<a href="$1">$1</a>', $text);
			$text = preg_replace('|@(\w+)|', '<a href="http://twitter.com/$1">@$1</a>', $text);
			$text = preg_replace('|#(\w+)|', '<a href="http://search.twitter.com/search?q=%23$1">#$1</a>', $text);

			// Create post object
			$new_post = array(
			  'post_title'    => 'Tweeted by: ' . $user,
			  'post_content'  => $text,
			  //'post_status'   => 'pending',
			  'post_status'   => 'publish',
			  'post_author'   => 3,
			  'post_category' => array(5)
			);
			
			// Insert the post into the database
			$newPostID=wp_insert_post( $new_post );
	
			add_post_meta($newPostID, '_twitterID', $result['id']);
			
			//added today
			//$images=$result['entities']['media'];
			//echo "<h1>Media Count: ".count($result['entities']['media'])."</h1>";
			_log("Media Count: ".count($result['entities']->media));
// 			echo "<pre>".var_dump($result)."</pre>";
			if($result['entities']->media){				
				// add the function above to catch the attachments creation
				add_action('add_attachment','new_attachment');
				foreach($result['entities']->media as $image){
// 					echo "<h1>Media URL: ".$image->media_url."</h1>";
					require_once(ABSPATH . "wp-admin" . '/includes/image.php');
				    require_once(ABSPATH . "wp-admin" . '/includes/file.php');
				    require_once(ABSPATH . "wp-admin" . '/includes/media.php');
					$image = media_sideload_image($image->media_url, $newPostID);
					
					_log("Image Side Loaded: $image");
					
					update_post_meta($post_id, '_thumbnail_id', $image_id);
					$updated_post = array(
					  'ID' => $newPostID,
					  'post_content'  => $text . "<p>".$image."</p>",
					);
					
					_log("Updating Post Content: ".$text . "<p>".$image."</p>");
					wp_update_post($updated_post);
				}
				// we have the Image now, and the function above will have fired too setting the thumbnail ID in the process, so lets remove the hook so we don't cause any more trouble 
				remove_action('add_attachment','new_attachment');
			}
		}
	}
}

function new_attachment($att_id){
    // the post this was sideloaded into is the attachments parent!
    $p = get_post($att_id);
    update_post_meta($p->post_parent,'_thumbnail_id',$att_id);
}





register_deactivation_hook(__FILE__,'tweetInserter_deactivate');

function tweetInserter_deactivate(){
	wp_clear_scheduled_hook('insertTweetsEvent');
}

function clearTweetPost(){
	global $wpdb;

	$querystr="SELECT $wpdb->posts.* 
		FROM $wpdb->posts, $wpdb->postmeta
		WHERE $wpdb->posts.ID = $wpdb->postmeta.post_id 
		AND $wpdb->postmeta.meta_key = '_twitterID' 
		AND $wpdb->postmeta.meta_value > 0 
		ORDER BY $wpdb->postmeta.meta_value DESC
		";
	
	//echo "<h1>Query String: ".$querystr."</h1>";
	$query = $wpdb->get_results($querystr, OBJECT);
	echo "<h1>Removing ".count($query)." tweet posts.</h1>";

	if($query){
		global $post;
		foreach ($query as $post){
			$delete_result=wp_delete_post(get_the_ID());
			//echo "<h1>Delete Result: ".$delete_result."</h1>";
		}
	}
}

function checkNextRun(){
	//wp_schedule_single_event(time(), 'insertTweetsEvent');
	$nextRun=wp_next_scheduled( 'insertTweetsEvent' );
	if ( $nextRun ) {
		echo "<h1>Next Twitter Run: $nextRun</h1>";
	}else{
		echo "<h1>Twitter Import Not Scheduled!</h1>";
	}
}

add_action('admin_notices','checkNextRun');

//add_action('admin_notices','clearTweetPost');
//remove_action('admin_notices','clearTweetPost');

// Now we set that function up to execute when the admin_notices action is called
//add_action( 'admin_notices', 'insertTweets' );

//Uncomment the line below to turn off the adds
//remove_action('admin_notices','insertTweets');
