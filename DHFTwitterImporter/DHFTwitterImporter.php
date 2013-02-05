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
		$completedURL="$api_url?q=%23MDLove%20OR%20%23LoveMD&rpp=100&since_id=$max_twitterID&include_entities=1";
		$raw_response = wp_remote_get($completedURL); //&since_id=max _twitterID value from query above
	}else{
		$completedURL="$api_url?q=%23MDLove%20OR%20%23LoveMD&rpp=100&include_entities=1";
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
		//echo "<H1>Twitter Search String: ".$completedURL."</H1>";
		//echo "<H1>Twitter Result Count: ".count($response['results'])."</H1>";
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
			  'post_status'   => 'publish',
			  'post_author'   => 3,
			  'post_category' => array(5)
			);
			
			// Insert the post into the database
			$newPostID=wp_insert_post( $new_post );
	
			add_post_meta($newPostID, '_twitterID', $result['id']);
			
			if($result['entities']->media){				
				add_action('add_attachment','new_attachment');
				foreach($result['entities']->media as $image){
					$image = media_sideload_image($image->media_url, $newPostID);
					update_post_meta($post_id, '_thumbnail_id', $image_id);
					$updated_post = array(
					  'ID' => $newPostID,
					  'post_content'  => $text . "<p>".$image."</p>",
					);
					wp_update_post($updated_post);
				}
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


register_activation_hook(__FILE__,'tweetInserter_activate');

function tweetInserter_activate(){
	wp_schedule_event(time(),'hourly','insertTweetsEvent');
}

add_action('insertTweetsEvent','insertTweets');

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
	
	$query = $wpdb->get_results($querystr, OBJECT);

	if($query){
		global $post;
		foreach ($query as $post){
			$delete_result=wp_delete_post(get_the_ID());
		}
	}
}

//add_action('admin_notices','clearTweetPost');
//remove_action('admin_notices','clearTweetPost');

// Now we set that function up to execute when the admin_notices action is called
//add_action( 'admin_notices', 'insertTweets' );

//Uncomment the line below to turn off the adds
//remove_action('admin_notices','insertTweets');
