<?php
/*
Plugin Name: UpdraftPlus - Backup/Restore
Plugin URI: http://wordpress.org/extend/plugins/updraftplus
Description: Uploads, themes, plugins, and your DB can be automatically backed up to Amazon S3, Google Drive, FTP, or emailed, on separate schedules.
Author: David Anderson.
Version: 0.9.2
Donate link: http://david.dw-perspective.org.uk/donate
Author URI: http://wordshell.net
*/ 

//TODO (some of these items mine, some from original Updraft awaiting review):
//Add DropBox support
//Struggles with large uploads - runs out of time before finishing. Break into chunks? Resume download on later run? (Add a new scheduled event to check on progress? Separate the upload from the creation?).
//improve error reporting.  s3 and dir backup have decent reporting now, but not sure i know what to do from here
//list backups that aren't tracked (helps with double backup problem)
//investigate $php_errormsg further
//pretty up return messages in admin area
//check s3/ftp download

//Rip out the "last backup" bit, and/or put in a display of the last log

/* More TODO:
DONE, TESTING: Are all directories in wp-content covered? No; only plugins, themes, content. We should check for others and allow the user the chance to choose which ones he wants
Use only one entry in WP options database
Encrypt filesystem, if memory allows (and have option for abort if not); split up into multiple zips when needed
// Does not delete old custom directories upon a restore?
*/

/*  Portions copyright 2010 Paul Kehrer
Portions copyright 2011-12 David Anderson

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/
// TODO: Note this might *lower* the limit - should check first.

@set_time_limit(900); //15 minutes max. i'm not sure how long a really big site could take to back up?

$updraft = new UpdraftPlus();

if(!$updraft->memory_check(192)) {
# TODO: Better solution is to split the backup set into manageable chunks based on this limit
	@ini_set('memory_limit', '192M'); //up the memory limit for large backup files... should split the backup set into manageable chunks based on the limit
}

define('UPDRAFT_DEFAULT_OTHERS_EXCLUDE','upgrade,cache,updraft,index.php');

class UpdraftPlus {

	var $version = '0.9.2';

	var $dbhandle;
	var $errors = array();
	var $nonce;
	var $logfile_name = "";
	var $logfile_handle = false;
	var $backup_time;
	
	function __construct() {
		// Initialisation actions
		# Create admin page
		add_action('admin_menu', array($this,'add_admin_pages'));
		add_action('admin_init', array($this,'admin_init'));
		add_action('updraft_backup', array($this,'backup_files'));
		add_action('updraft_backup_database', array($this,'backup_database'));
		# backup_all is used by the manual "Backup Now" button
		add_action('updraft_backup_all', array($this,'backup_all'));
		# this is our runs-after-backup event, whose purpose is to see if it succeeded or failed, and resume/mom-up etc.
		add_action('updraft_backup_resume', array($this,'backup_resume'));
		add_action('wp_ajax_updraft_download_backup', array($this, 'updraft_download_backup'));
		# http://codex.wordpress.org/Plugin_API/Filter_Reference/cron_schedules
		add_filter('cron_schedules', array($this,'modify_cron_schedules'));
		add_filter('plugin_action_links', array($this, 'plugin_action_links'), 10, 2);
		add_action('init', array($this, 'googledrive_backup_auth'));
	}

	// Handle Google OAuth 2.0
	function googledrive_backup_auth() {
		if ( is_admin() && isset( $_GET['page'] ) && $_GET['page'] == 'updraftplus' && isset( $_GET['action'] ) && $_GET['action'] == 'auth' ) {
			if ( isset( $_GET['state'] ) ) {
				if ( $_GET['state'] == 'token' )
					$this->auth_token();
				elseif ( $_GET['state'] == 'revoke' )
					$this->auth_revoke();
			} elseif (isset($_GET['updraftplus_googleauth'])) {
				$this->auth_request();
			}
		}
	}

	/**
	* Acquire single-use authorization code from Google OAuth 2.0
	*/
	function auth_request() {
		$params = array(
			'response_type' => 'code',
			'client_id' => get_option('updraft_googledrive_clientid'),
			'redirect_uri' => admin_url('options-general.php?page=updraftplus&action=auth'),
			'scope' => 'https://www.googleapis.com/auth/drive.file https://docs.google.com/feeds/ https://docs.googleusercontent.com/ https://spreadsheets.google.com/feeds/',
			'state' => 'token',
			'access_type' => 'offline',
			'approval_prompt' => 'auto'
		);
		header('Location: https://accounts.google.com/o/oauth2/auth?'.http_build_query($params));
	}

	/**
	* Get a Google account access token using the refresh token
	*/
	function access_token( $token, $client_id, $client_secret ) {
		$context = array(
			'http' => array(
				'method'  => 'POST',
				'header'  => 'Content-type: application/x-www-form-urlencoded',
				'content' => http_build_query( array(
					'refresh_token' => $token,
					'client_id' => $client_id,
					'client_secret' => $client_secret,
					'grant_type' => 'refresh_token'
				) )
			)
		);
		$this->log("Google Drive: requesting access token: client_id=$client_id");
		$result = @file_get_contents('https://accounts.google.com/o/oauth2/token', false, stream_context_create($context));
		if($result) {
			$result = json_decode( $result, true );
			if ( isset( $result['access_token'] ) ) {
				$this->log("Google Drive: successfully obtained access token");
				return $result['access_token'];
			} else {
				$this->log("Google Drive error when requesting access token: response does not contain access_token");
				return false;
			}
		} else {
			$this->log("Google Drive error when requesting access token: no response");
			return false;
		}
	}

	/**
	* Function to upload a file to Google Drive
	*
	* @param  string  $file   Path to the file that is to be uploaded
	* @param  string  $title  Title to be given to the file
	* @param  string  $parent ID of the folder in which to upload the file
	* @param  string  $token  Access token from Google Account
	* @return boolean         Returns TRUE on success, FALSE on failure
	*/
	function googledrive_upload_file( $file, $title, $parent = '', $token) {

		$size = filesize( $file );

		$content = '<?xml version=\'1.0\' encoding=\'UTF-8\'?>
	<entry xmlns="http://www.w3.org/2005/Atom" xmlns:docs="http://schemas.google.com/docs/2007">
	<category scheme="http://schemas.google.com/g/2005#kind" term="http://schemas.google.com/docs/2007#file"/>
	<title>' . $title . '</title>
	</entry>';
		
		$header = array(
			'Authorization: Bearer ' . $token,
			'Content-Length: ' . strlen( $content ),
			'Content-Type: application/atom+xml',
			'X-Upload-Content-Type: application/octet-stream',
			'X-Upload-Content-Length: ' . $size,
			'GData-Version: 3.0'
		);

		$context = array(
			'http' => array(
				'ignore_errors' => true,
				'follow_location' => false,
				'method'  => 'POST',
				'header'  => join( "\r\n", $header ),
				'content' => $content
			)
		);

		$url = $this->get_resumable_create_media_link( $token, $parent );
		if ( $url ) {
			$url .= '?convert=false'; // needed to upload a file
			$this->log("Google Drive: resumable create media link: ".$url);
		} else {
			$this->log('Could not retrieve resumable create media link.', __FILE__, __LINE__ );
			return false;
		}

		$result = @file_get_contents( $url, false, stream_context_create( $context ) );

		if ( $result !== FALSE ) {
			if ( strpos( $response = array_shift( $http_response_header ), '200' ) ) {
				$response_header = array();
				foreach ( $http_response_header as $header_line ) {
					list( $key, $value ) = explode( ':', $header_line, 2 );
					$response_header[trim( $key )] = trim( $value );
					#$this->log("Google Drive: header: ".trim($key).": ".trim($value));
				}
				if ( isset( $response_header['Location'] ) ) {
					$next_location = $response_header['Location'];
					$pointer = 0;
					# 1Mb
					$max_chunk_size = 524288*2;
					while ( $pointer < $size - 1 ) {
						$this->log(basename($file).": Google Drive upload: pointer=$pointer (size=$size)");
						$chunk = file_get_contents( $file, false, NULL, $pointer, $max_chunk_size );
						$next_location = $this->upload_chunk( $next_location, $chunk, $pointer, $size, $token );
						if( $next_location === false ) {
							$this->log("Google Drive Upload: next_location is false (pointer: $pointer; chunk length: ".strlen($chunk).")");
							return false;
						}
						$pointer += strlen( $chunk );
						// if object it means we have our simpleXMLElement response
						if ( is_object( $next_location ) ) {
							// return resource Id
							$this->log("Google Drive Upload: Success");
							# Google Drive returns 501 not implemented for me for some reason instead of expected result...
							#return substr( $next_location->children( "http://schemas.google.com/g/2005" )->resourceId, 5 );
							return true;
						}
						
					}
				} 
			}
			else {
				$this->log( 'Bad response: ' . $response . ' Response header: ' . var_export( $response_header, true ) . ' Response body: ' . $result . ' Request URL: ' . $url, __FILE__, __LINE__ );
				return false;
			}
		}
		else {
			$this->log( 'Unable to request file from ' . $url, __FILE__, __LINE__ );
		}

		return true;

	}

	/**
	* Get the resumable-create-media link needed to upload files
	*
	* @param  string $token  The Google Account access token
	* @param  string $parent The Id of the folder where the upload is to be made. Default is empty string.
	* @return string|boolean Returns a link on success, FALSE on failure. 
	*/
	function get_resumable_create_media_link( $token, $parent = '' ) {
		$header = array(
			'Authorization: Bearer ' . $token,
			'GData-Version: 3.0'
		);
		$context = array(
			'http' => array(
				'ignore_errors' => true,
				'method' => 'GET',
				'header' => join( "\r\n", $header )
			)
		);
		$url = 'https://docs.google.com/feeds/default/private/full';

		if ( $parent ) {
			$url .= '/' . $parent;
		}

		$result = @file_get_contents( $url, false, stream_context_create( $context ) );

		if ( $result !== false ) {
			$xml = simplexml_load_string( $result );
			if ( $xml === false ) {
				$this->log( 'Could not create SimpleXMLElement from ' . $result, __FILE__, __LINE__ );
					return false;
			}
			else {
				foreach ( $xml->link as $link ) {
					if ( $link['rel'] == 'http://schemas.google.com/g/2005#resumable-create-media' ) { return $link['href']; }
				}
			}
		}
		return false;
	}


	/**
	* Handles the upload to Google Drive of a single chunk of a file
	*
	* @param  string  $location URL where the chunk needs to be uploaded
	* @param  string  $chunk	Part of the file to upload
	* @param  integer $pointer  The byte number marking the beginning of the chunk in file
	* @param  integer $size	 The size of the file the chunk is part of, in bytes
	* @param  string  $token	Google Account access token
	* @return string|boolean	The funcion returns the location where the next chunk needs to be uploaded, TRUE if the last chunk was uploaded or FALSE on failure
	*/
	function upload_chunk( $location, $chunk, $pointer, $size, $token ) {
		$chunk_size = strlen( $chunk );
		$bytes = (string)$pointer . '-' . (string)($pointer + $chunk_size - 1) . '/' . (string)$size;
		$this->log("Google Drive chunk: location=$location, length=$chunk_size, range=$bytes");
		$header = array(
			'Authorization: Bearer ' . $token,
			'Content-Length: ' . $chunk_size,
			'Content-Type: application/octet-stream',
			'Content-Range: bytes ' . $bytes,
			'GData-Version: 3.0'
		);
		$context = array(
			'http' => array(
				'ignore_errors' => true,
				'follow_location' => false,
				'method' => 'PUT',
				'header' => join( "\r\n", $header ),
				'content' => $chunk
			)
		);

		$result = @file_get_contents( $location, false, stream_context_create( $context ) );

		if ( isset( $http_response_header ) ) {
			$response = array_shift( $http_response_header );
			$headers = array();
			foreach ( $http_response_header as $header_line ) {
				list( $key, $value ) = explode( ':', $header_line, 2 );
				$headers[trim( $key )] = trim( $value );
			}

			if ( strpos( $response, '308' ) ) {
				if ( isset( $headers['Location'] ) ) {
					$this->log('Google Drive: 308 response: '.$headers['Location']);
					return $headers['Location'];
				}
				else {
					$this->log('Google Drive 308 response: no location header: '.$location);
					return $location;
				}
			}
			elseif ( strpos( $response, '201' ) ) {
				#$this->log("Google Drive response: ".$result);
				$xml = simplexml_load_string( $result );
				if ( $xml === false ) {
					$this->log('ERROR: Could not create SimpleXMLElement from ' . $result, __FILE__, __LINE__ );
					return false;
				}
				else {
					return $xml;
				}
			}
			else {
				$this->log('ERROR: Bad response: ' . $response, __FILE__, __LINE__ );
				return false;
			}
		}
		else {
			$this->log('ERROR: Received no response from ' . $location . ' while trying to upload bytes ' . $bytes );
			return false;
		}
	}

	function googledrive_delete_file( $file, $token) {
		$this->log("Delete from Google Drive: $file: not yet implemented");
		# TODO - somehow, turn this into a Gdata resource ID, then despatch it to googledrive_delete_file_byid
		return;
	}

	/**
	* Deletes a file from Google Drive
	*
	* @param  string $id	Gdata resource Id of the file to be deleted
	* @param  string $token Google Account access token
	* @return boolean	   Returns TRUE on success, FALSE on failure
	*/
	function googledrive_delete_file_byid( $id, $token ) {
		$header = array(
			'If-Match: *',
			'Authorization: Bearer ' . $token,
			'GData-Version: 3.0'
		);
		$context = array(
			'http' => array(
				'method' => 'DELETE',
				'header' => join( "\r\n", $header )
			)
		);
		stream_context_set_default( $context );
		$headers = get_headers( 'https://docs.google.com/feeds/default/private/full/' . $id . '?delete=true',1 );

		if ( strpos( $headers[0], '200' ) ) { return true; }
		return false;
	}

	/**
	* Get a Google account refresh token using the code received from auth_request
	*/
	function auth_token() {
		if( isset( $_GET['code'] ) ) {
			$context = array(
				'http' => array(
					'timeout' => 30,
					'method'  => 'POST',
					'header'  => 'Content-type: application/x-www-form-urlencoded',
					'content' => http_build_query( array(
						'code' => $_GET['code'],
						'client_id' => get_option('updraft_googledrive_clientid'),
						'client_secret' => get_option('updraft_googledrive_secret'),
						'redirect_uri' => admin_url('options-general.php?page=updraftplus&action=auth'),
						'grant_type' => 'authorization_code'
					) )
				)
			);
			$result = @file_get_contents('https://accounts.google.com/o/oauth2/token', false, stream_context_create($context));
			# Oddly, sometimes fails and then trying again works...
			/*
			if (!$result) { sleep(1); $result = @file_get_contents('https://accounts.google.com/o/oauth2/token', false, stream_context_create($context));}
			if (!$result) { sleep(1); $result = @file_get_contents('https://accounts.google.com/o/oauth2/token', false, stream_context_create($context));}
			*/
			if($result) {
				$result = json_decode( $result, true );
				if ( isset( $result['refresh_token'] ) ) {
					update_option('updraft_googledrive_token',$result['refresh_token']); // Save token
					header('Location: '.admin_url('options-general.php?page=updraftplus&message=' . __( 'Authorization was successful.', 'updraftplus' ) ) );
				}
				else {
					header('Location: '.admin_url('options-general.php?page=updraftplus&error=' . __( 'No refresh token was received!', 'updraftplus' ) ) );
				}
			} else {
				header('Location: '.admin_url('options-general.php?page=updraftplus&error=' . __( 'Bad response!', 'backup' ) ) );
			}
		}
		else {
			header('Location: '.admin_url('options-general.php?page=updraftplus&error=' . __( 'Authorisation failed!', 'backup' ) ) );
		}
	}

	/**
	* Revoke a Google account refresh token
	*/
	function auth_revoke() {
		@file_get_contents( 'https://accounts.google.com/o/oauth2/revoke?token=' . get_option('updraft_googledrive_token') );
		update_option('updraft_googledrive_token','');
		header( 'Location: '.admin_url( 'options-general.php?page=updraftplus&message=' . __( 'Authorization revoked.', 'backup' ) ) );
	}

	# Adds the settings link under the plugin on the plugin screen.
	function plugin_action_links($links, $file) {
		if ($file == plugin_basename(__FILE__)){
			$settings_link = '<a href="'.site_url().'/wp-admin/options-general.php?page=updraftplus">'.__("Settings", "UpdraftPlus").'</a>';
			array_unshift($links, $settings_link);
			$settings_link = '<a href="http://david.dw-perspective.org.uk/donate">'.__("Donate","UpdraftPlus").'</a>';
			array_unshift($links, $settings_link);
		}
		return $links;
	}

	function backup_time_nonce() {
		$this->backup_time = time();
		$this->nonce = substr(md5(time().rand()),20);
	}

	# Logs the given line, adding date stamp and newline
	function log($line) {
		if ($this->logfile_handle) fwrite($this->logfile_handle,date('r')." ".$line."\n");
	}
	
	function backup_resume($resumption_no) {
		// This is scheduled for 5 minutes after a backup job starts
		$bnonce = get_transient('updraftplus_backup_job_nonce');
		if (!$bnonce) return;
		$this->nonce = $bnonce;
		$this->logfile_open($bnonce);
		$this->log("Resume backup ($resumption_no): begin run (will check for any remaining jobs)");
		$btime = get_transient('updraftplus_backup_job_time');
		if (!$btime) {
			$this->log("Did not find stored time setting - aborting");
			return;
		}
		$this->log("Resuming backup: resumption=$resumption_no, nonce=$bnonce, begun at=$btime");
		// Schedule again, to run in 5 minutes again, in case we again fail
		$resume_delay = 300;
		// A different argument than before is needed otherwise the event is ignored
		$next_resumption = $resumption_no+1;
		if ($next_resumption < 10) {
			wp_schedule_single_event(time()+$resume_delay, 'updraft_backup_resume' ,array($next_resumption));
		} else {
			$this->log("This is our tenth attempt - will not try again");
		}
		$this->backup_time = $btime;

		// Returns an array, most recent first, of backup sets
		$backup_history = $this->get_backup_history();
		if (!isset($backup_history[$btime])) $this->log("Error: Could not find a record in the database of a backup with this timestamp");

		$our_files=$backup_history[$btime];
		$undone_files = array();
		foreach ($our_files as $key => $file) {
			$hash=md5($file);
			$fullpath = trailingslashit(get_option('updraft_dir')).$file;
			if (get_transient('updraft_'.$hash) === "yes") {
				$this->log("$file: $key: This file has been successfully uploaded in the last 3 hours");
			} elseif (is_file($fullpath)) {
				$this->log("$file: $key: This file has NOT been successfully uploaded in the last 3 hours: will retry");
				$undone_files[$key] = $file;
			} else {
				$this-log("$file: Note: This file was not marked as successfully uploaded, but does not exist on the local filesystem");
				$this->uploaded_file($file);
			}
		}

		if (count($undone_files) == 0) {
			$this->log("There were no files that needed uploading; backup job is finished");
			return;
		}

		$this->log("Requesting backup of the files that were not successfully uploaded");
		$this->cloud_backup($undone_files);
		$this->cloud_backup_finish($undone_files);

		$this->log("Resume backup ($resumption_no): finish run");

		$this->backup_finish($next_resumption);

	}

	function backup_all() {
		$this->backup(true,true);
	}
	
	function backup_files() {
		# Note that the "false" for database gets over-ridden automatically if they turn out to have the same schedules
		$this->backup(true,false);
	}
	
	function backup_database() {
		# Note that nothing will happen if the file backup had the same schedule
		$this->backup(false,true);
	}

	function logfile_open($nonce) {
		//set log file name and open log file
		$updraft_dir = $this->backups_dir_location();
		$this->logfile_name =  $updraft_dir. "/log.$nonce.txt";
		// Use append mode in case it already exists
		$this->logfile_handle = fopen($this->logfile_name, 'a');
	}

	//scheduled wp-cron events can have a race condition here if page loads are coming fast enough, but there's nothing we can do about it. TODO: I reckon there is. Store a transient based on the backup schedule. Then as the backup proceeds, check for its existence; if it has changed, then another task has begun, so abort.
	function backup($backup_files, $backup_database) {

		//generate backup information
		$this->backup_time_nonce();
		// If we don't finish in 3 hours, then we won't finish
		// This transient indicates the identity of the current backup job (which can be used to find the files and logfile)
		set_transient("updraftplus_backup_job_nonce",$this->nonce,3600*3);
		set_transient("updraftplus_backup_job_time",$this->backup_time,3600*3);
		$this->logfile_open($this->nonce);

		// Schedule the even to run later, which checks on success and can resume the backup
		// We save the time to a variable because it is needed for un-scheduling
// 		$resume_delay = (get_option('updraft_debug_mode')) ? 60 : 300;
		$resume_delay = 300;
		wp_schedule_single_event(time()+$resume_delay, 'updraft_backup_resume', array(1));
		$this->log("In case we run out of time, scheduled a resumption at: $resume_delay seconds from now");

		// Log some information that may be helpful
		global $wp_version;
		$this->log("PHP version: ".phpversion()." WordPress version: ".$wp_version." Updraft version: ".$this->version." Backup files: $backup_files (schedule: ".get_option('updraft_interval','unset').") Backup DB: $backup_database (schedule: ".get_option('updraft_interval_database','unset').")");

		# If the files and database schedules are the same, and if this the file one, then we rope in database too.
		# On the other hand, if the schedules were the same and this was the database run, then there is nothing to do.
		if (get_option('updraft_interval') == get_option('updraft_interval_database') || get_option('updraft_interval_database','xyz') == 'xyz' ) {
			$backup_database = ($backup_files == true) ? true : false;
		}

		$this->log("Processed schedules. Tasks now: Backup files: $backup_files Backup DB: $backup_database");

		# Possibly now nothing is to be done, except to close the log file
		if ($backup_files || $backup_database) {

			$backup_contains = "";

			$backup_array = array();

			//backup directories and return a numerically indexed array of file paths to the backup files
			if ($backup_files) {
				$this->log("Beginning backup of directories");
				$backup_array = $this->backup_dirs();
				$backup_contains = "Files only (no database)";
			}
			
			//backup DB and return string of file path
			if ($backup_database) {
				$this->log("Beginning backup of database");
				$db_backup = $this->backup_db();
				//add db path to rest of files
				if(is_array($backup_array)) { $backup_array['db'] = $db_backup; }
				$backup_contains = ($backup_files) ? "Files and database" : "Database only (no files)";
			}

			set_transient("updraftplus_backupcontains", $backup_contains, 3600*3);

			//save this to our history so we can track backups for the retain feature
			$this->log("Saving backup history");
			// This is done before cloud despatch, because we want a record of what *should* be in the backup. Whether it actually makes it there or not is not yet known.
			$this->save_backup_history($backup_array);

			//cloud operations (S3,Google Drive,FTP,email,nothing)
			//this also calls the retain (prune) feature at the end (done in this method to reuse existing cloud connections)
			if(is_array($backup_array) && count($backup_array) >0) {
				$this->log("Beginning dispatch of backup to remote");
				$this->cloud_backup($backup_array);
			}

			//save the last backup info, including errors, if any
			$this->log("Saving last backup information into WordPress db");
			$this->save_last_backup($backup_array);

			// Delete local files, send the email
			$this->cloud_backup_finish($backup_array);

		}

		// Close log file; delete and also delete transients if not in debug mode
		$this->backup_finish(1);

	}

	function backup_finish($cancel_event) {

		// In fact, leaving the hook to run (if debug is set) is harmless, as the resume job should only do tasks that were left unfinished, which at this stage is none.
		if (empty($this->errors)) {
			$this->log("There were no errors in the uploads, so the 'resume' event is being unscheduled");
			wp_clear_scheduled_hook('updraft_backup_resume', array($cancel_event));
			delete_transient("updraftplus_backup_job_nonce");
			delete_transient("updraftplus_backup_job_time");
		} else {
			$this->log("There were errors in the uploads, so the 'resume' event is remaining unscheduled");
		}

		@fclose($this->logfile_handle);

		if (!get_option('updraft_debug_mode')) @unlink($this->logfile_name);

	}

	function cloud_backup_finish($backup_array) {

		//delete local files if the pref is set
		foreach($backup_array as $file) { $this->delete_local($file); }

		// Send the results email if requested
		if(get_option('updraft_email') != "" && get_option('updraft_service') != 'email') $this->send_results_email();

	}


	function send_results_email() {

		$sendmail_to = get_option('updraft_email');

		$this->log("Sending email report to: ".$sendmail_to);

		$append_log = (get_option('updraft_debug_mode') && $this->logfile_name != "") ? "\r\nLog contents:\r\n".file_get_contents($this->logfile_name) : "" ;

		wp_mail($sendmail_to,'Backed up: '.get_bloginfo('name').' (UpdraftPlus) '.date('Y-m-d H:i',time()),'Site: '.site_url()."\r\nUpdraftPlus WordPress backup is complete.\r\nBackup contains: ".get_transient("updraftplus_backupcontains")."\r\n\r\n".$this->wordshell_random_advert(0)."\r\n".$append_log);

	}

	function save_last_backup($backup_array) {
		$success = (empty($this->errors))?1:0;

		$last_backup = array('backup_time'=>$this->backup_time, 'backup_array'=>$backup_array, 'success'=>$success, 'errors'=>$this->errors);

		update_option('updraft_last_backup', $last_backup);
	}

	// This should be called whenever a file is successfully uploaded
	function uploaded_file($file) {
		# We take an MD5 hash because set_transient wants a name of 45 characters or less
		$hash = md5($file);
		set_transient("updraft_".$hash, "yes", 3600*3);
	}

	function cloud_backup($backup_array) {
		switch(get_option('updraft_service')) {
			case 's3':
				@set_time_limit(900);
				$this->log("Cloud backup: S3");
				if (count($backup_array) >0) $this->s3_backup($backup_array);
			break;
			case 'googledrive':
				@set_time_limit(900);
				$this->log("Cloud backup: Google Drive");
				if (count($backup_array) >0) $this->googledrive_backup($backup_array);
			break;
			case 'ftp':
				@set_time_limit(900);
				$this->log("Cloud backup: FTP");
				if (count($backup_array) >0) $this->ftp_backup($backup_array);
			break;
			case 'email':
				@set_time_limit(900);
				$this->log("Cloud backup: Email");
				//files can easily get way too big for this...
				foreach($backup_array as $type=>$file) {
					$fullpath = trailingslashit(get_option('updraft_dir')).$file;
					wp_mail(get_option('updraft_email'),"WordPress Backup ".date('Y-m-d H:i',$this->backup_time),"Backup is of the $type.  Be wary; email backups may fail because of file size limitations on mail servers.",null,array($fullpath));
					$this->uploaded_file($file);
				}
				//we don't break here so it goes and executes all the default behavior below as well.  this gives us retain behavior for email
			default:
				$this->prune_retained_backups("local");
			break;
		}
	}

	// Carries out retain behaviour. Pass in a valid S3 or FTP object and path if relevant.
	function prune_retained_backups($updraft_service,$remote_object,$remote_path) {
		$this->log("Retain: beginning examination of existing backup sets");
		$updraft_retain = get_option('updraft_retain');
		// Number of backups to retain
		$retain = (isset($updraft_retain))?get_option('updraft_retain'):1;
		$this->log("Retain: user setting: number to retain = $retain");
		// Returns an array, most recent first, of backup sets
		$backup_history = $this->get_backup_history();
		$db_backups_found = 0;
		$file_backups_found = 0;
		$this->log("Number of backup sets in history: ".count($backup_history));
		foreach ($backup_history as $backup_datestamp => $backup_to_examine) {
			// $backup_to_examine is an array of file names, keyed on db/plugins/themes/uploads
			// The new backup_history array is saved afterwards, so remember to unset the ones that are to be deleted
			$this->log("Examining backup set with datestamp: $backup_datestamp");
			if (isset($backup_to_examine['db'])) {
				$db_backups_found++;
				$this->log("$backup_datestamp: this set includes a database (".$backup_to_examine['db']."); db count is now $db_backups_found");
				if ($db_backups_found > $retain) {
					$this->log("$backup_datestamp: over retain limit; will delete this database");
					$file = $backup_to_examine['db'];
					$this->log("$backup_datestamp: Delete this file: $file");
					if ($file != '') {
						$fullpath = trailingslashit(get_option('updraft_dir')).$file;
						@unlink($fullpath); //delete it if it's locally available
						if ($updraft_service == "s3") {
							if (preg_match("#^([^/]+)/(.*)$#",$remote_path,$bmatches)) {
								$s3_bucket=$bmatches[1];
								$s3_uri = $bmatches[2]."/".$file;
							} else {
								$s3_bucket = $remote_path;
								$s3_uri = $file;
							}
							$this->log("$backup_datestamp: Delete remote: bucket=$s3_bucket, URI=$s3_uri");
							# Here we brought in the function deleteObject in order to get more direct access to any error
							$rest = new S3Request('DELETE', $s3_bucket, $s3_uri);
							$rest = $rest->getResponse();
							if ($rest->error === false && $rest->code !== 204) {
								$this->log("S3 Error: Expected HTTP response 204; got: ".$rest->code);
								$this->error("S3 Error: Unexpected HTTP response code ".$rest->code." (expected 204)");
							} elseif ($rest->error !== false) {
								$this->log("S3 Error: ".$rest->error['code'].": ".$rest->error['message']);
								$this->error("S3 delete error: ".$rest->error['code'].": ".$rest->error['message']);
							}
						} elseif ($updraft_service == "ftp") {
							$this->log("$backup_datestamp: Delete remote ftp: $remote_path/$file");
							@$remote_object->delete($remote_path.$file);
						} elseif ($updraft_service == "googledrive") {
							$this->log("$backup_datestamp: Delete remote file from Google Drive: $remote_path/$file");
							$this->googledrive_delete_file($remote_path.'/'.$file,$remote_object);
						}
					}
					unset($backup_to_examine['db']);
				}
			}
			if (isset($backup_to_examine['plugins']) || isset($backup_to_examine['themes']) || isset($backup_to_examine['uploads']) || isset($backup_to_examine['others'])) {
				$file_backups_found++;
				$this->log("$backup_datestamp: this set includes files; fileset count is now $file_backups_found");
				if ($file_backups_found > $retain) {
					$this->log("$backup_datestamp: over retain limit; will delete this file set");
					$file = isset($backup_to_examine['plugins']) ? $backup_to_examine['plugins'] : "";
					$file2 = isset($backup_to_examine['themes']) ? $backup_to_examine['themes'] : "";
					$file3 = isset($backup_to_examine['uploads']) ? $backup_to_examine['uploads'] : "";
					$file4 = isset($backup_to_examine['others']) ? $backup_to_examine['others'] : "";
					foreach (array($file,$file2,$file3,$file4) as $dofile) {
						if ($dofile) {
							$this->log("$backup_datestamp: Delete this file: $dofile");
							$fullpath = trailingslashit(get_option('updraft_dir')).$dofile;
							@unlink($fullpath); //delete it if it's locally available
							if ($updraft_service == "s3") {
								if (preg_match("#^([^/]+)/(.*)$#",$remote_path,$bmatches)) {
									$s3_bucket=$bmatches[1];
									$s3_uri = $bmatches[2]."/".$dofile;
								} else {
									$s3_bucket = $remote_path;
									$s3_uri = $dofile;
								}
								$this->log("$backup_datestamp: Delete remote: bucket=$s3_bucket, URI=$s3_uri");
								# Here we brought in the function deleteObject in order to get more direct access to any error
								$rest = new S3Request('DELETE', $s3_bucket, $s3_uri);
								$rest = $rest->getResponse();
								if ($rest->error === false && $rest->code !== 204) {
									$this->log("S3 Error: Expected HTTP response 204; got: ".$rest->code);
									$this->error("S3 Error: Unexpected HTTP response code ".$rest->code." (expected 204)");
								} elseif ($rest->error !== false) {
									$this->log("S3 Error: ".$rest->error['code'].": ".$rest->error['message']);
									$this->error("S3 delete error: ".$rest->error['code'].": ".$rest->error['message']);
								}
							} elseif ($updraft_service == "ftp") {
								$this->log("$backup_datestamp: Delete remote ftp: $remote_path/$dofile");
								@$remote_object->delete($remote_path.$dofile);
							} elseif ($updraft_service == "googledrive") {
								$this->log("$backup_datestamp: Delete remote file from Google Drive: $remote_path/$dofile");
								$this->googledrive_delete_file($remote_path.'/'.$dofile,$remote_object);
							}
						}
					}
					unset($backup_to_examine['plugins']);
					unset($backup_to_examine['themes']);
					unset($backup_to_examine['uploads']);
					unset($backup_to_examine['others']);
				}
			}
			// Delete backup set completely if empty, o/w just remove DB
			if (count($backup_to_examine)==0) {
				$this->log("$backup_datestamp: this backup set is now empty; will remove from history");
				unset($backup_history[$backup_datestamp]);
			} else {
				$this->log("$backup_datestamp: this backup set remains non-empty; will retain in history");
				$backup_history[$backup_datestamp] = $backup_to_examine;
			}
		}
		$this->log("Retain: saving new backup history (sets now: ".count($backup_history).") and finishing retain operation");
		update_option('updraft_backup_history',$backup_history);
	}
	
	function s3_backup($backup_array) {
		if(!class_exists('S3')) require_once(dirname(__FILE__).'/includes/S3.php');
		$s3 = new S3(get_option('updraft_s3_login'), get_option('updraft_s3_pass'));
		$bucket_name = untrailingslashit(get_option('updraft_s3_remote_path'));
		$bucket_path = "";
		$orig_bucket_name = $bucket_name;
		if (preg_match("#^([^/]+)/(.*)$#",$bucket_name,$bmatches)) {
			$bucket_name = $bmatches[1];
			$bucket_path = $bmatches[2]."/";
		}
		if (@$s3->putBucket($bucket_name, S3::ACL_PRIVATE)) {
			foreach($backup_array as $file) {
				$fullpath = trailingslashit(get_option('updraft_dir')).$file;
				$this->log("S3 upload: $fullpath -> s3://$bucket_name/$bucket_path$file");
				if (!$s3->putObjectFile($fullpath, $bucket_name, $bucket_path.$file)) {
					$this->log("S3 upload: failed");
					$this->error("S3 Error: Failed to upload $fullpath. Error was ".$php_errormsg);
				} else {
					$this->log("S3 upload: success");
					$this->uploaded_file($file);
				}
			}
			$this->prune_retained_backups('s3',$s3,$orig_bucket_name);
		} else {
			$this->log("S3 Error: Failed to create bucket $bucket_name. Error was ".$php_errormsg);
			$this->error("S3 Error: Failed to create bucket $bucket_name. Error was ".$php_errormsg);
		}
	}
	
	function googledrive_backup($backup_array) {
		if ( $access = $this->access_token( get_option('updraft_googledrive_token'), get_option('updraft_googledrive_clientid'), get_option('updraft_googledrive_secret') ) ) {
			foreach ($backup_array as $file) {
				$file_path = trailingslashit(get_option('updraft_dir')).$file;
				$file_name = basename($file_path);
				$this->log("$file_name: Attempting to upload to Google Drive");
				$timer_start = microtime( true );
				if ( $id = $this->googledrive_upload_file( $file_path, $file_name, get_option('updraft_googledrive_remotepath'), $access ) ) {
					$this->log('OK: Archive ' . $file_name . ' uploaded to Google Drive in ' . ( round(microtime( true ) - $timer_start,2) ) . ' seconds' );
					$this->uploaded_file($file);
				} else {
					$this->error("$file_name: Failed to upload to Google Drive" );
					$this->log("ERROR: $file_name: Failed to upload to Google Drive" );
				}
			}
			$this->prune_retained_backups("googledrive",$access,get_option('updraft_googledrive_remotepath'));
		} else {
			$this->log('ERROR: Did not receive an access token from Google', __FILE__, __LINE__ );
		}
	}
	
	function ftp_backup($backup_array) {
		if( !class_exists('ftp_wrapper')) {
			require_once(dirname(__FILE__).'/includes/ftp.class.php');
		}
		//handle SSL and errors at some point TODO
		$ftp = new ftp_wrapper(get_option('updraft_server_address'),get_option('updraft_ftp_login'),get_option('updraft_ftp_pass'));
		$ftp->passive = true;
		$ftp->connect();
		//$ftp->make_dir(); we may need to recursively create dirs? TODO
		
		$ftp_remote_path = trailingslashit(get_option('updraft_ftp_remote_path'));
		foreach($backup_array as $file) {
			$fullpath = trailingslashit(get_option('updraft_dir')).$file;
			if ($ftp->put($fullpath,$ftp_remote_path.$file,FTP_BINARY)) {
				$this->log("ERROR: $file_name: Successfully uploaded via FTP");
				$this->uploaded_file($file);
			} else {
				$this->error("$file_name: Failed to upload to FTP" );
				$this->log("ERROR: $file_name: Failed to upload to FTP" );
			}
		}
		$this->prune_retained_backups("ftp",$ftp,$ftp_remote_path);
	}
	
	function delete_local($file) {
		if(get_option('updraft_delete_local')) {
			$this->log("Deleting local file: $file");
		//need error checking so we don't delete what isn't successfully uploaded?
			$fullpath = trailingslashit(get_option('updraft_dir')).$file;
			return unlink($fullpath);
		}
		return true;
	}
	
	function backup_dirs() {
		if(!$this->backup_time) $this->backup_time_nonce();
		$wp_themes_dir = WP_CONTENT_DIR.'/themes';
		$wp_upload_dir = wp_upload_dir();
		$wp_upload_dir = $wp_upload_dir['basedir'];
		$wp_plugins_dir = WP_PLUGIN_DIR;

		if(!class_exists('PclZip')) require_once(ABSPATH.'/wp-admin/includes/class-pclzip.php');

		$updraft_dir = $this->backups_dir_location();
		if(!is_writable($updraft_dir)) $this->error('Backup directory is not writable, or does not exist.','fatal');

		//get the blog name and rip out all non-alphanumeric chars other than _
		$blog_name = str_replace(' ','_',get_bloginfo());
		$blog_name = preg_replace('/[^A-Za-z0-9_]/','', $blog_name);
		if(!$blog_name) $blog_name = 'non_alpha_name';

		$backup_file_base = $updraft_dir.'/backup_'.date('Y-m-d-Hi',$this->backup_time).'_'.$blog_name.'_'.$this->nonce;

		$backup_array = array();

		# Plugins
		@set_time_limit(900);
		if (get_option('updraft_include_plugins', true)) {
			$this->log("Beginning backup of plugins");
			$full_path = $backup_file_base.'-plugins.zip';
			$plugins = new PclZip($full_path);
			# The paths in the zip should then begin with 'plugins', having removed WP_CONTENT_DIR from the front
			if (!$plugins->create($wp_plugins_dir,PCLZIP_OPT_REMOVE_PATH,WP_CONTENT_DIR)) {
				$this->error('Could not create plugins zip. Error was '.$php_errmsg,'fatal');
				$this->log('ERROR: PclZip failure: Could not create plugins zip');
			} else {
				$this->log("Created plugins zip - file size is ".filesize($full_path)." bytes");
			}
			$backup_array['plugins'] = basename($full_path);
		} else {
			$this->log("No backup of plugins: excluded by user's options");
		}
		
		# Themes
		@set_time_limit(900);
		if (get_option('updraft_include_themes', true)) {
			$this->log("Beginning backup of themes");
			$full_path = $backup_file_base.'-themes.zip';
			$themes = new PclZip($full_path);
			if (!$themes->create($wp_themes_dir,PCLZIP_OPT_REMOVE_PATH,WP_CONTENT_DIR)) {
				$this->error('Could not create themes zip. Error was '.$php_errmsg,'fatal');
				$this->log('ERROR: PclZip failure: Could not create themes zip');
			} else {
				$this->log("Created themes zip - file size is ".filesize($full_path)." bytes");
			}
			$backup_array['themes'] = basename($full_path);
		} else {
			$this->log("No backup of themes: excluded by user's options");
		}

		# Uploads
		@set_time_limit(900);
		if (get_option('updraft_include_uploads', true)) {
			$this->log("Beginning backup of uploads");
			$full_path = $backup_file_base.'-uploads.zip';
			$uploads = new PclZip($full_path);
			if (!$uploads->create($wp_upload_dir,PCLZIP_OPT_REMOVE_PATH,WP_CONTENT_DIR)) {
				$this->error('Could not create uploads zip. Error was '.$php_errmsg,'fatal');
				$this->log('ERROR: PclZip failure: Could not create uploads zip');
			} else {
				$this->log("Created uploads zip - file size is ".filesize($full_path)." bytes");
			}
			$backup_array['uploads'] = basename($full_path);
		} else {
			$this->log("No backup of uploads: excluded by user's options");
		}

		# Others
		@set_time_limit(900);
		if (get_option('updraft_include_others', true)) {
			$this->log("Beginning backup of other directories found in the content directory");
			$full_path=$backup_file_base.'-others.zip';
			$others = new PclZip($full_path);
			// http://www.phpconcept.net/pclzip/user-guide/53
			/* First parameter to create is:
				An array of filenames or dirnames,
				or
				A string containing the filename or a dirname,
				or
				A string containing a list of filename or dirname separated by a comma.
			*/
			// First, see what we can find. We always want to exclude these:
			$wp_themes_dir = WP_CONTENT_DIR.'/themes';
			$wp_upload_dir = wp_upload_dir();
			$wp_upload_dir = $wp_upload_dir['basedir'];
			$wp_plugins_dir = WP_PLUGIN_DIR;
			$updraft_dir = untrailingslashit(get_option('updraft_dir'));

			# Initialise
			$other_dirlist = array(); 
			
			$others_skip = preg_split("/,/",get_option('updraft_include_others_exclude',UPDRAFT_DEFAULT_OTHERS_EXCLUDE));
			# Make the values into the keys
			$others_skip = array_flip($others_skip);

			$this->log('Looking for candidates to back up in: '.WP_CONTENT_DIR);
			if ($handle = opendir(WP_CONTENT_DIR)) {
				while (false !== ($entry = readdir($handle))) {
					$candidate = WP_CONTENT_DIR.'/'.$entry;
					if ($entry == "." || $entry == "..") { ; }
					elseif ($candidate == $updraft_dir) { $this->log("$entry: skipping: this is the updraft directory"); }
					elseif ($candidate == $wp_themes_dir) { $this->log("$entry: skipping: this is the themes directory"); }
					elseif ($candidate == $wp_upload_dir) { $this->log("$entry: skipping: this is the uploads directory"); }
					elseif ($candidate == $wp_plugins_dir) { $this->log("$entry: skipping: this is the plugins directory"); }
					elseif (isset($others_skip[$entry])) { $this->log("$entry: skipping: excluded by options"); }
					else { $this->log("$entry: adding to list"); array_push($other_dirlist,$candidate); }
				}
			} else {
				$this->log('ERROR: Could not read the content directory: '.WP_CONTENT_DIR);
			}

			if (count($other_dirlist)>0) {
				if (!$others->create($other_dirlist,PCLZIP_OPT_REMOVE_PATH,WP_CONTENT_DIR)) {
					$this->error('Could not create other zip. Error was '.$php_errmsg,'fatal');
					$this->log('ERROR: PclZip failure: Could not create other zip');
				} else {
					$this->log("Created other directories zip - file size is ".filesize($full_path)." bytes");
				}
				$backup_array['others'] = basename($full_path);
			} else {
				$this->log("No backup of other directories: there was nothing found to back up");
			}
		} else {
			$this->log("No backup of other directories: excluded by user's options");
		}
		return $backup_array;
	}

	function save_backup_history($backup_array) {
		//TODO: this stores full paths right now.  should probably concatenate with ABSPATH to make it easier to move sites
		if(is_array($backup_array)) {
			$backup_history = get_option('updraft_backup_history');
			$backup_history = (is_array($backup_history)) ? $backup_history : array();
			$backup_history[$this->backup_time] = $backup_array;
			update_option('updraft_backup_history',$backup_history);
		} else {
			$this->error('Could not save backup history because we have no backup array.  Backup probably failed.');
		}
	}
	
	function get_backup_history() {
		//$backup_history = get_option('updraft_backup_history');
		//by doing a raw DB query to get the most up-to-date data from this option we slightly narrow the window for the multiple-cron race condition
		global $wpdb;
		$backup_history = @unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value from $wpdb->options WHERE option_name='updraft_backup_history'")));
		if(is_array($backup_history)) {
			krsort($backup_history); //reverse sort so earliest backup is last on the array.  this way we can array_pop
		} else {
			$backup_history = array();
		}
		return $backup_history;
	}
	
	
	/*START OF WB-DB-BACKUP BLOCK*/

	function backup_db() {

		$total_tables = 0;

		global $table_prefix, $wpdb;
		if(!$this->backup_time) {
			$this->backup_time_nonce();
		}

		$all_tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
		$all_tables = array_map(create_function('$a', 'return $a[0];'), $all_tables);
		
		$updraft_dir = $this->backups_dir_location();
		//get the blog name and rip out all non-alphanumeric chars other than _
		$blog_name = str_replace(' ','_',get_bloginfo());
		$blog_name = preg_replace('/[^A-Za-z0-9_]/','', $blog_name);
		if(!$blog_name) {
			$blog_name = 'non_alpha_name';
		}

		$backup_file_base = $updraft_dir.'/backup_'.date('Y-m-d-Hi',$this->backup_time).'_'.$blog_name.'_'.$this->nonce;
		if (is_writable($updraft_dir)) {
			if (function_exists('gzopen')) {
				$this->dbhandle = @gzopen($backup_file_base.'-db.gz','w');
			} else {
				$this->dbhandle = @fopen($backup_file_base.'-db.gz', 'w');
			}
			if(!$this->dbhandle) {
				//$this->error(__('Could not open the backup file for writing!','wp-db-backup'));
			}
		} else {
			//$this->error(__('The backup directory is not writable!','wp-db-backup'));
		}
		
		//Begin new backup of MySql
		$this->stow("# " . __('WordPress MySQL database backup','wp-db-backup') . "\n");
		$this->stow("#\n");
		$this->stow("# " . sprintf(__('Generated: %s','wp-db-backup'),date("l j. F Y H:i T")) . "\n");
		$this->stow("# " . sprintf(__('Hostname: %s','wp-db-backup'),DB_HOST) . "\n");
		$this->stow("# " . sprintf(__('Database: %s','wp-db-backup'),$this->backquote(DB_NAME)) . "\n");
		$this->stow("# --------------------------------------------------------\n");
		

		if (defined("DB_CHARSET")) {
			$this->stow("/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n");
			$this->stow("/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n");
			$this->stow("/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n");
			$this->stow("/*!40101 SET NAMES " . DB_CHARSET . " */;\n");
		}
		$this->stow("/*!40101 SET foreign_key_checks = 0 */;\n");

		foreach ($all_tables as $table) {
			$total_tables++;
			// Increase script execution time-limit to 15 min for every table.
			if ( !ini_get('safe_mode') || strtolower(ini_get('safe_mode')) == "off") @set_time_limit(15*60);
			# === is needed, otherwise 'false' matches (i.e. prefix does not match)
			if ( strpos($table, $table_prefix) === 0 ) {
				// Create the SQL statements
				$this->stow("# --------------------------------------------------------\n");
				$this->stow("# " . sprintf(__('Table: %s','wp-db-backup'),$this->backquote($table)) . "\n");
				$this->stow("# --------------------------------------------------------\n");
				$this->backup_table($table);
			} else {
				$this->stow("# --------------------------------------------------------\n");
				$this->stow("# " . sprintf(__('Skipping non-WP table: %s','wp-db-backup'),$this->backquote($table)) . "\n");
				$this->stow("# --------------------------------------------------------\n");				
			}
		}

			if (defined("DB_CHARSET")) {
				$this->stow("/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n");
				$this->stow("/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n");
				$this->stow("/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n");
			}

		$this->close($this->dbhandle);

		if (count($this->errors)) {
			return false;
		} else {
			# Encrypt, if requested
			$encryption = get_option('updraft_encryptionphrase');
			if (strlen($encryption) > 0) {
				$this->log("Database: applying encryption");
				$encryption_error = 0;
				require_once(dirname(__FILE__).'/includes/Rijndael.php');
				$rijndael = new Crypt_Rijndael();
				$rijndael->setKey($encryption);
				$in_handle = @fopen($backup_file_base.'-db.gz','r');
				$buffer = "";
				while (!feof ($in_handle)) {
					$buffer .= fread($in_handle, 16384);
				}
				fclose ($in_handle);
				$out_handle = @fopen($backup_file_base.'-db.gz.crypt','w');
				if (!fwrite($out_handle, $rijndael->encrypt($buffer))) {$encryption_error = 1;}
				fclose ($out_handle);
				if (0 == $encryption_error) {
					# Delete unencrypted file
					@unlink($backup_file_base.'-db.gz');
					return basename($backup_file_base.'-db.gz.crypt');
				} else {
					$this->error("Encryption error occurred when encrypting database. Aborted.");
				}
			} else {
				return basename($backup_file_base.'-db.gz');
			}
		}
		$this->log("Total database tables backed up: $total_tables");
		
	} //wp_db_backup

	/**
	 * Taken partially from phpMyAdmin and partially from
	 * Alain Wolf, Zurich - Switzerland
	 * Website: http://restkultur.ch/personal/wolf/scripts/db_backup/
	 * Modified by Scott Merrill (http://www.skippy.net/) 
	 * to use the WordPress $wpdb object
	 * @param string $table
	 * @param string $segment
	 * @return void
	 */
	function backup_table($table, $segment = 'none') {
		global $wpdb;

		$total_rows = 0;

		$table_structure = $wpdb->get_results("DESCRIBE $table");
		if (! $table_structure) {
			//$this->error(__('Error getting table details','wp-db-backup') . ": $table");
			return false;
		}
	
		if(($segment == 'none') || ($segment == 0)) {
			// Add SQL statement to drop existing table
			$this->stow("\n\n");
			$this->stow("#\n");
			$this->stow("# " . sprintf(__('Delete any existing table %s','wp-db-backup'),$this->backquote($table)) . "\n");
			$this->stow("#\n");
			$this->stow("\n");
			$this->stow("DROP TABLE IF EXISTS " . $this->backquote($table) . ";\n");
			
			// Table structure
			// Comment in SQL-file
			$this->stow("\n\n");
			$this->stow("#\n");
			$this->stow("# " . sprintf(__('Table structure of table %s','wp-db-backup'),$this->backquote($table)) . "\n");
			$this->stow("#\n");
			$this->stow("\n");
			
			$create_table = $wpdb->get_results("SHOW CREATE TABLE $table", ARRAY_N);
			if (false === $create_table) {
				$err_msg = sprintf(__('Error with SHOW CREATE TABLE for %s.','wp-db-backup'), $table);
				//$this->error($err_msg);
				$this->stow("#\n# $err_msg\n#\n");
			}
			$this->stow($create_table[0][1] . ' ;');
			
			if (false === $table_structure) {
				$err_msg = sprintf(__('Error getting table structure of %s','wp-db-backup'), $table);
				//$this->error($err_msg);
				$this->stow("#\n# $err_msg\n#\n");
			}
		
			// Comment in SQL-file
			$this->stow("\n\n");
			$this->stow("#\n");
			$this->stow('# ' . sprintf(__('Data contents of table %s','wp-db-backup'),$this->backquote($table)) . "\n");
			$this->stow("#\n");
		}
		
		if(($segment == 'none') || ($segment >= 0)) {
			$defs = array();
			$ints = array();
			foreach ($table_structure as $struct) {
				if ( (0 === strpos($struct->Type, 'tinyint')) ||
					(0 === strpos(strtolower($struct->Type), 'smallint')) ||
					(0 === strpos(strtolower($struct->Type), 'mediumint')) ||
					(0 === strpos(strtolower($struct->Type), 'int')) ||
					(0 === strpos(strtolower($struct->Type), 'bigint')) ) {
						$defs[strtolower($struct->Field)] = ( null === $struct->Default ) ? 'NULL' : $struct->Default;
						$ints[strtolower($struct->Field)] = "1";
				}
			}
			
			
			// Batch by $row_inc
			if ( ! defined('ROWS_PER_SEGMENT') ) {
				define('ROWS_PER_SEGMENT', 100);
			}
			
			if($segment == 'none') {
				$row_start = 0;
				$row_inc = ROWS_PER_SEGMENT;
			} else {
				$row_start = $segment * ROWS_PER_SEGMENT;
				$row_inc = ROWS_PER_SEGMENT;
			}
			do {	
				// don't include extra stuff, if so requested
				$excs = array('revisions' => 0, 'spam' => 1); //TODO, FIX THIS
				$where = '';
				if ( is_array($excs['spam'] ) && in_array($table, $excs['spam']) ) {
					$where = ' WHERE comment_approved != "spam"';
				} elseif ( is_array($excs['revisions'] ) && in_array($table, $excs['revisions']) ) {
					$where = ' WHERE post_type != "revision"';
				}
				
				if ( !ini_get('safe_mode') || strtolower(ini_get('safe_mode')) == "off") @set_time_limit(15*60);
				$table_data = $wpdb->get_results("SELECT * FROM $table $where LIMIT {$row_start}, {$row_inc}", ARRAY_A);
				$entries = 'INSERT INTO ' . $this->backquote($table) . ' VALUES (';	
				//    \x08\\x09, not required
				$search = array("\x00", "\x0a", "\x0d", "\x1a");
				$replace = array('\0', '\n', '\r', '\Z');
				if($table_data) {
					foreach ($table_data as $row) {
						$total_rows++;
						$values = array();
						foreach ($row as $key => $value) {
							if ($ints[strtolower($key)]) {
								// make sure there are no blank spots in the insert syntax,
								// yet try to avoid quotation marks around integers
								$value = ( null === $value || '' === $value) ? $defs[strtolower($key)] : $value;
								$values[] = ( '' === $value ) ? "''" : $value;
							} else {
								$values[] = "'" . str_replace($search, $replace, $this->sql_addslashes($value)) . "'";
							}
						}
						$this->stow(" \n" . $entries . implode(', ', $values) . ');');
					}
					$row_start += $row_inc;
				}
			} while((count($table_data) > 0) and ($segment=='none'));
		}
		
		if(($segment == 'none') || ($segment < 0)) {
			// Create footer/closing comment in SQL-file
			$this->stow("\n");
			$this->stow("#\n");
			$this->stow("# " . sprintf(__('End of data contents of table %s','wp-db-backup'),$this->backquote($table)) . "\n");
			$this->stow("# --------------------------------------------------------\n");
			$this->stow("\n");
		}
 		$this->log("Table $table: Total rows added: $total_rows");

	} // end backup_table()


	function stow($query_line) {
		if (function_exists('gzopen')) {
			if(! @gzwrite($this->dbhandle, $query_line)) {
				//$this->error(__('There was an error writing a line to the backup script:','wp-db-backup') . '  ' . $query_line . '  ' . $php_errormsg);
			}
		} else {
			if(false === @fwrite($this->dbhandle, $query_line)) {
				//$this->error(__('There was an error writing a line to the backup script:','wp-db-backup') . '  ' . $query_line . '  ' . $php_errormsg);
			}
		}
	}


	function close($handle) {
		if (function_exists('gzopen')) {
			gzclose($handle);
		} else {
			fclose($handle);
		}
	}

	/**
	 * Logs any error messages
	 * @param array $args
	 * @return bool
	 */
	function error($error,$severity='') {
		$this->errors[] = array('error'=>$error,'severity'=>$severity);
		if ($severity == 'fatal') {
			//do something...
		}
		return true;
	}

	/**
	 * Add backquotes to tables and db-names in
	 * SQL queries. Taken from phpMyAdmin.
	 */
	function backquote($a_name) {
		if (!empty($a_name) && $a_name != '*') {
			if (is_array($a_name)) {
				$result = array();
				reset($a_name);
				while(list($key, $val) = each($a_name)) 
					$result[$key] = '`' . $val . '`';
				return $result;
			} else {
				return '`' . $a_name . '`';
			}
		} else {
			return $a_name;
		}
	}

	/**
	 * Better addslashes for SQL queries.
	 * Taken from phpMyAdmin.
	 */
	function sql_addslashes($a_string = '', $is_like = false) {
		if ($is_like) $a_string = str_replace('\\', '\\\\\\\\', $a_string);
		else $a_string = str_replace('\\', '\\\\', $a_string);
		return str_replace('\'', '\\\'', $a_string);
	} 

	/*END OF WP-DB-BACKUP BLOCK */

	/*
	this function is both the backup scheduler and ostensibly a filter callback for saving the option.
	it is called in the register_setting for the updraft_interval, which means when the admin settings 
	are saved it is called.  it returns the actual result from wp_filter_nohtml_kses (a sanitization filter) 
	so the option can be properly saved.
	*/
	function schedule_backup($interval) {
		//clear schedule and add new so we don't stack up scheduled backups
		wp_clear_scheduled_hook('updraft_backup');
		switch($interval) {
			case 'daily':
			case 'weekly':
			case 'monthly':
				wp_schedule_event(time()+30, $interval, 'updraft_backup');
			break;
		}
		return wp_filter_nohtml_kses($interval);
	}

	function schedule_backup_database($interval) {
		//clear schedule and add new so we don't stack up scheduled backups
		wp_clear_scheduled_hook('updraft_backup_database');
		switch($interval) {
			case 'daily':
			case 'weekly':
			case 'monthly':
				wp_schedule_event(time()+30, $interval, 'updraft_backup_database');
			break;
		}
		return wp_filter_nohtml_kses($interval);
	}

	//wp-cron only has hourly, daily and twicedaily, so we need to add weekly and monthly. 
	function modify_cron_schedules($schedules) {
		$schedules['weekly'] = array(
			'interval' => 604800,
			'display' => 'Once Weekly'
		);
		$schedules['monthly'] = array(
			'interval' => 2592000,
			'display' => 'Once Monthly'
		);
		return $schedules;
	}
	
	function backups_dir_location() {
		$updraft_dir = untrailingslashit(get_option('updraft_dir'));
		$default_backup_dir = WP_CONTENT_DIR.'/updraft';
		//if the option isn't set, default it to /backups inside the upload dir
		$updraft_dir = ($updraft_dir)?$updraft_dir:$default_backup_dir;
		//check for the existence of the dir and an enumeration preventer.
		if(!is_dir($updraft_dir) || !is_file($updraft_dir.'/index.html') || !is_file($updraft_dir.'/.htaccess')) {
			@mkdir($updraft_dir,0777,true); //recursively create the dir with 0777 permissions. 0777 is default for php creation.  not ideal, but I'll get back to this
			@file_put_contents($updraft_dir.'/index.html','Nothing to see here.');
			@file_put_contents($updraft_dir.'/.htaccess','deny from all');
		}
		return $updraft_dir;
	}
	
	function updraft_download_backup() {
		$type = $_POST['type'];
		$timestamp = (int)$_POST['timestamp'];
		$backup_history = $this->get_backup_history();
		$file = $backup_history[$timestamp][$type];
		$fullpath = trailingslashit(get_option('updraft_dir')).$file;
		if(!is_readable($fullpath)) {
			//if the file doesn't exist and they're using one of the cloud options, fetch it down from the cloud.
			$this->download_backup($file);
		}
		if(@is_readable($fullpath) && is_file($fullpath)) {
			$len = filesize($fullpath);

			$filearr = explode('.',$file);
			//we've only got zip and gz...for now
			$file_ext = array_pop($filearr);
			if($file_ext == 'zip') {
				header('Content-type: application/zip');
			} else {
				// This catches both when what was popped was 'crypt' (*-db.gz.crypt) and when it was 'gz' (unencrypted)
				header('Content-type: application/x-gzip');
			}
			header("Cache-Control: no-cache, must-revalidate"); // HTTP/1.1
			header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Date in the past
			header("Content-Length: $len;");
			if ($file_ext == 'crypt') {
				header("Content-Disposition: attachment; filename=\"".substr($file,0,-6)."\";");
			} else {
				header("Content-Disposition: attachment; filename=\"$file\";");
			}
			ob_end_flush();
			if ($file_ext == 'crypt') {
				$encryption = get_option('updraft_encryptionphrase');
				if ($encryption == "") {
					$this->error('Decryption of database failed: the database file is encrypted, but you have no encryption key entered.');
				} else {
					require_once(dirname(__FILE__).'/includes/Rijndael.php');
					$rijndael = new Crypt_Rijndael();
					$rijndael->setKey($encryption);
					$in_handle = fopen($fullpath,'r');
					$ciphertext = "";
					while (!feof ($in_handle)) {
						$ciphertext .= fread($in_handle, 16384);
					}
					fclose ($in_handle);
					print $rijndael->decrypt($ciphertext);
				}
			} else {
				readfile($fullpath);
			}
			$this->delete_local($file);
			exit; //we exit immediately because otherwise admin-ajax appends an additional zero to the end for some reason I don't understand. seriously, why die('0')?
		} else {
			echo 'Download failed.  File '.$fullpath.' did not exist or was unreadable.  If you delete local backups then S3  or Google Drive or FTP retrieval may have failed. (Note that Google Drive downloading is not yet supported - you need to download manually if you use Google Drive).';
		}
	}
	
	function download_backup($file) {
		switch(get_option('updraft_service')) {
			case 'googledrive':
				$this->download_googledrive_backup($file);
			break;
			case 's3':
				$this->download_s3_backup($file);
			break;
			case 'ftp':
				$this->download_ftp_backup($file);
			break;
			default:
				$this->error('Automatic backup restoration is only available via S3, FTP, and local. Email and downloaded backup restoration must be performed manually.');
		}
	}

	function download_googledrive_backup($file) {
		$this->error("Google Drive error: we do not yet support downloading existing backups from Google Drive - you need to restore the backup manually");
	}

	function download_s3_backup($file) {
		if(!class_exists('S3')) {
			require_once(dirname(__FILE__).'/includes/S3.php');
		}
		$s3 = new S3(get_option('updraft_s3_login'), get_option('updraft_s3_pass'));
		$bucket_name = untrailingslashit(get_option('updraft_s3_remote_path'));
		$bucket_path = "";
		if (preg_match("#^([^/]+)/(.*)$#",$bucket_name,$bmatches)) {
			$bucket_name = $bmatches[1];
			$bucket_path = $bmatches[2]."/";
		}
		if (@$s3->putBucket($bucket_name, S3::ACL_PRIVATE)) {
			$fullpath = trailingslashit(get_option('updraft_dir')).$file;
			if (!$s3->getObject($bucket_name, $bucket_path.$file, $fullpath)) {
				$this->error("S3 Error: Failed to download $fullpath. Error was ".$php_errormsg);
			}
		} else {
			$this->error("S3 Error: Failed to create bucket $bucket_name. Error was ".$php_errormsg);
		}
	}
	
	function download_ftp_backup($file) {
		if( !class_exists('ftp_wrapper')) {
			require_once(dirname(__FILE__).'/includes/ftp.class.php');
		}
		//handle SSL and errors at some point TODO
		$ftp = new ftp_wrapper(get_option('updraft_server_address'),get_option('updraft_ftp_login'),get_option('updraft_ftp_pass'));
		$ftp->passive = true;
		$ftp->connect();
		//$ftp->make_dir(); we may need to recursively create dirs? TODO
		
		$ftp_remote_path = trailingslashit(get_option('updraft_ftp_remote_path'));
		$fullpath = trailingslashit(get_option('updraft_dir')).$file;
		$ftp->get($fullpath,$ftp_remote_path.$file,FTP_BINARY);
	}
	
	function restore_backup($timestamp) {
		global $wp_filesystem;
		$backup_history = get_option('updraft_backup_history');
		if(!is_array($backup_history[$timestamp])) {
			echo '<p>This backup does not exist in the backup history - restoration aborted. Timestamp: '.$timestamp.'</p><br/>';
			return false;
		}

		$credentials = request_filesystem_credentials("options-general.php?page=updraftplus&action=updraft_restore&backup_timestamp=$timestamp"); 
		WP_Filesystem($credentials);
		if ( $wp_filesystem->errors->get_error_code() ) { 
			foreach ( $wp_filesystem->errors->get_error_messages() as $message )
				show_message($message); 
			exit; 
		}
		
		//if we make it this far then WP_Filesystem has been instantiated and is functional (tested with ftpext, what about suPHP and other situations where direct may work?)
		echo '<span style="font-weight:bold">Restoration Progress </span><div id="updraft-restore-progress">';

		$updraft_dir = trailingslashit(get_option('updraft_dir'));
		foreach($backup_history[$timestamp] as $type=>$file) {
			$fullpath = $updraft_dir.$file;
			if(!is_readable($fullpath) && $type != 'db') {
				$this->download_backup($file);
			}
			# Types: uploads, themes, plugins, others, db
			if(is_readable($fullpath) && $type != 'db') {
				if(!class_exists('WP_Upgrader')) {
					require_once( ABSPATH . 'wp-admin/includes/class-wp-upgrader.php' );
				}
				require_once('includes/updraft-restorer.php');
				$restorer = new Updraft_Restorer();
				$val = $restorer->restore_backup($fullpath,$type);
				if(is_wp_error($val)) {
					print_r($val);
					echo '</div>'; //close the updraft_restore_progress div even if we error
					return false;
				}
			}
		}
		echo '</div>'; //close the updraft_restore_progress div
		# The 'off' check is for badly configured setups - http://wordpress.org/support/topic/plugin-wp-super-cache-warning-php-safe-mode-enabled-but-safe-mode-is-off
		if(ini_get('safe_mode') && strtolower(ini_get('safe_mode')) != "off") {
			echo "<p>DB could not be restored because safe_mode is active on your server.  You will need to manually restore the file via phpMyAdmin or another method.</p><br/>";
			return false;
		}
		return true;
	}

	//deletes the -old directories that are created when a backup is restored.
	function delete_old_dirs() {
		global $wp_filesystem;
		$credentials = request_filesystem_credentials("options-general.php?page=updraftplus&action=updraft_delete_old_dirs"); 
		WP_Filesystem($credentials);
		if ( $wp_filesystem->errors->get_error_code() ) { 
			foreach ( $wp_filesystem->errors->get_error_messages() as $message )
				show_message($message); 
			exit; 
		}
		
		$to_delete = array('themes-old','plugins-old','uploads-old','others-old');

		foreach($to_delete as $name) {
			//recursively delete
			if(!$wp_filesystem->delete(WP_CONTENT_DIR.'/'.$name, true)) {
				return false;
			}
		}
		return true;
	}
	
	//scans the content dir to see if any -old dirs are present
	function scan_old_dirs() {
		$dirArr = scandir(WP_CONTENT_DIR);
		foreach($dirArr as $dir) {
			if(strpos($dir,'-old') !== false) {
				return true;
			}
		}
		return false;
	}
	
	
	function retain_range($input) {
		$input = (int)$input;
		if($input > 0 && $input < 3650) {
			return $input;
		} else {
			return 1;
		}
	}
	
	function create_backup_dir() {
		global $wp_filesystem;
		$credentials = request_filesystem_credentials("options-general.php?page=updraftplus&action=updraft_create_backup_dir"); 
		WP_Filesystem($credentials);
		if ( $wp_filesystem->errors->get_error_code() ) { 
			foreach ( $wp_filesystem->errors->get_error_messages() as $message )
				show_message($message); 
			exit; 
		}

		$updraft_dir = untrailingslashit(get_option('updraft_dir'));
		$default_backup_dir = WP_CONTENT_DIR.'/updraft';
		$updraft_dir = ($updraft_dir)?$updraft_dir:$default_backup_dir;

		//chmod the backup dir to 0777. ideally we'd rather chgrp it but i'm not sure if it's possible to detect the group apache is running under (or what if it's not apache...)
		if(!$wp_filesystem->mkdir($updraft_dir, 0777)) {
			return false;
		}
		return true;
	}
	

	function memory_check_current() {
		# Returns in megabytes
		$memory_limit = ini_get('memory_limit');
		$memory_unit = $memory_limit[strlen($memory_limit)-1];
		$memory_limit = substr($memory_limit,0,strlen($memory_limit)-1);
		switch($memory_unit) {
			case 'K':
				$memory_limit = $memory_limit/1024;
			break;
			case 'G':
				$memory_limit = $memory_limit*1024;
			break;
			case 'M':
				//assumed size, no change needed
			break;
		}
		return $memory_limit;
	}

	function memory_check($memory) {
		$memory_limit = $this->memory_check_current();
		return ($memory_limit >= $memory)?true:false;
	}

	function execution_time_check($time) {
		return (ini_get('max_execution_time') >= $time)?true:false;
	}

	function admin_init() {
		if(get_option('updraft_debug_mode')) {
			ini_set('display_errors',1);
			error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
			ini_set('track_errors',1);
		}
		wp_enqueue_script('jquery');
		register_setting( 'updraft-options-group', 'updraft_interval', array($this,'schedule_backup') );
		register_setting( 'updraft-options-group', 'updraft_interval_database', array($this,'schedule_backup_database') );
		register_setting( 'updraft-options-group', 'updraft_retain', array($this,'retain_range') );
		register_setting( 'updraft-options-group', 'updraft_encryptionphrase', 'wp_filter_nohtml_kses' );
		register_setting( 'updraft-options-group', 'updraft_service', 'wp_filter_nohtml_kses' );
		register_setting( 'updraft-options-group', 'updraft_s3_login', 'wp_filter_nohtml_kses' );
		register_setting( 'updraft-options-group', 'updraft_s3_pass', 'wp_filter_nohtml_kses' );
		register_setting( 'updraft-options-group', 'updraft_s3_remote_path', 'wp_filter_nohtml_kses' );
		register_setting( 'updraft-options-group', 'updraft_googledrive_clientid', 'wp_filter_nohtml_kses' );
		register_setting( 'updraft-options-group', 'updraft_googledrive_secret', 'wp_filter_nohtml_kses' );
		register_setting( 'updraft-options-group', 'updraft_googledrive_remotepath', 'wp_filter_nohtml_kses' );
		register_setting( 'updraft-options-group', 'updraft_ftp_login', 'wp_filter_nohtml_kses' );
		register_setting( 'updraft-options-group', 'updraft_ftp_pass', 'wp_filter_nohtml_kses' );
		register_setting( 'updraft-options-group', 'updraft_dir', 'wp_filter_nohtml_kses' );
		register_setting( 'updraft-options-group', 'updraft_email', 'wp_filter_nohtml_kses' );
		register_setting( 'updraft-options-group', 'updraft_ftp_remote_path', 'wp_filter_nohtml_kses' );
		register_setting( 'updraft-options-group', 'updraft_server_address', 'wp_filter_nohtml_kses' );
		register_setting( 'updraft-options-group', 'updraft_delete_local', 'absint' );
		register_setting( 'updraft-options-group', 'updraft_debug_mode', 'absint' );
		register_setting( 'updraft-options-group', 'updraft_include_plugins', 'absint' );
		register_setting( 'updraft-options-group', 'updraft_include_themes', 'absint' );
		register_setting( 'updraft-options-group', 'updraft_include_uploads', 'absint' );
		register_setting( 'updraft-options-group', 'updraft_include_others', 'absint' );
		register_setting( 'updraft-options-group', 'updraft_include_others_exclude', 'wp_filter_nohtml_kses' );
	
		/* I see no need for this check; people can only download backups/logs if they can guess a nonce formed from a random number and if .htaccess files have no effect. The database will be encrypted. Very unlikely.
		if (current_user_can('manage_options')) {
			$updraft_dir = $this->backups_dir_location();
			if(strpos($updraft_dir,WP_CONTENT_DIR) !== false) {
				$relative_dir = str_replace(WP_CONTENT_DIR,'',$updraft_dir);
				$possible_updraft_url = WP_CONTENT_URL.$relative_dir;
				$resp = wp_remote_request($possible_updraft_url, array('timeout' => 15));
				if ( is_wp_error($resp) ) {
					add_action('admin_notices', array($this,'show_admin_warning_accessible_unknownresult') );
				} else {
					if(strpos($resp['response']['code'],'403') === false) {
						add_action('admin_notices', array($this,'show_admin_warning_accessible') );
					}
				}
			}
		}
		*/
		if (current_user_can('manage_options') && get_option('updraft_service') == "googledrive" && get_option('updraft_googledrive_clientid') != "" && get_option('updraft_googledrive_token','xyz') == 'xyz') {
			add_action('admin_notices', array($this,'show_admin_warning_googledrive') );
		}
	}

	function add_admin_pages() {
		add_submenu_page('options-general.php', "UpdraftPlus", "UpdraftPlus", "manage_options", "updraftplus",
		array($this,"settings_output"));
	}

	function wordshell_random_advert($urls) {
		$url_start = ($urls) ? '<a href="http://wordshell.net">' : "";
		$url_end = ($urls) ? '</a>' : " (www.wordshell.net)";
		if (rand(0,1) == 0) {
			return "Like automating WordPress operations? Use the CLI? ${url_start}You will love WordShell${url_end} - saves time and money fast.";
		} else {
			return "${url_start}Check out WordShell${url_end} - manage WordPress from the command line - huge time-saver";
		}
	}

	function settings_output() {

		$ws_advert = $this->wordshell_random_advert(1);
		echo <<<ENDHERE
<div class="updated fade" style="font-size:140%; padding:14px;">${ws_advert}</div>
ENDHERE;

		/*
		we use request here because the initial restore is triggered by a POSTed form. we then may need to obtain credentials 
		for the WP_Filesystem. to do this WP outputs a form that we can't insert variables into (apparently). So the values are 
		passed back in as GET parameters. REQUEST covers both GET and POST so this weird logic works.
		*/
		if(isset($_REQUEST['action']) && $_REQUEST['action'] == 'updraft_restore' && isset($_REQUEST['backup_timestamp'])) {
			$backup_success = $this->restore_backup($_REQUEST['backup_timestamp']);
			if(empty($this->errors) && $backup_success == true) {
				echo '<p>Restore successful!</p><br/>';
				echo '<b>Actions:</b> <a href="options-general.php?page=updraftplus&updraft_restore_success=true">Return to Updraft Configuration</a>.';
				return;
			} else {
				echo '<p>Restore failed...</p><br/>';
				echo '<b>Actions:</b> <a href="options-general.php?page=updraftplus">Return to Updraft Configuration</a>.';
				return;
			}
			//uncomment the below once i figure out how i want the flow of a restoration to work.
			//echo '<b>Actions:</b> <a href="options-general.php?page=updraftplus">Return to Updraft Configuration</a>.';
		}
		$deleted_old_dirs = false;
		if(isset($_REQUEST['action']) && $_REQUEST['action'] == 'updraft_delete_old_dirs') {
			if($this->delete_old_dirs()) {
				$deleted_old_dirs = true;
			} else {
				echo '<p>Old directory removal failed for some reason. You may want to do this manually.</p><br/>';
			}
			echo '<p>Old directories successfully removed.</p><br/>';
			echo '<b>Actions:</b> <a href="options-general.php?page=updraftplus">Return to Updraft Configuration</a>.';
			return;
		}
		
		if(isset($_GET['error'])) {
			echo "<p><strong>ERROR:</strong> ".htmlspecialchars($_GET['error'])."</p>";
		}
		if(isset($_GET['message'])) {
			echo "<p><strong>Note:</strong> ".htmlspecialchars($_GET['message'])."</p>";
		}

		if(isset($_GET['action']) && $_GET['action'] == 'updraft_create_backup_dir') {
			if(!$this->create_backup_dir()) {
				echo '<p>Backup directory could not be created...</p><br/>';
			}
			echo '<p>Backup directory successfully created.</p><br/>';
			echo '<b>Actions:</b> <a href="options-general.php?page=updraftplus">Return to Updraft Configuration</a>.';
			return;
		}
		
		if(isset($_POST['action']) && $_POST['action'] == 'updraft_backup') {
			if (wp_schedule_single_event(time()+5, 'updraft_backup_all') === false) {
				echo "<!-- updraft schedule: failed -->";
			} else {
				echo "<!-- updraft schedule: ok -->";
			}
		}
		if(isset($_POST['action']) && $_POST['action'] == 'updraft_backup_debug_all') {
			$this->backup(true,true);
		}
		if(isset($_POST['action']) && $_POST['action'] == 'updraft_backup_debug_db') {
			$this->backup_db();
		}

		?>
		<div class="wrap">
			<h2>UpdraftPlus - Backup/Restore</h2>

			Version: <b><?php echo $this->version; ?></b><br />
			Maintained by <b>David Anderson</b> (<a href="http://david.dw-perspective.org.uk">Homepage</a> | <a href="http://wordshell.net">WordShell - WordPress command line</a> | <a href="http://david.dw-perspective.org.uk/donate">Donate</a> | <a href="http://wordpress.org/extend/plugins/updraftplus/faq/">FAQs</a> | <a href="http://profiles.wordpress.org/davidanderson/">My other WordPress plugins</a>)
			<br />
			Based on Updraft by <b>Paul Kehrer</b> (<a href="http://langui.sh" target="_blank">Blog</a> | <a href="http://twitter.com/reaperhulk" target="_blank">Twitter</a> )
			<br />
			<?php
			if(isset($_GET['updraft_restore_success'])) {
				echo "<div style=\"color:blue\">Your backup has been restored.  Your old themes, uploads, and plugins directories have been retained with \"-old\" appended to their name.  Remove them when you are satisfied that the backup worked properly.  At this time Updraft does not automatically restore your DB.  You will need to use an external tool like phpMyAdmin to perform that task.</div>";
			}
			if($deleted_old_dirs) {
				echo "<div style=\"color:blue\">Old directories successfully deleted.</div>";
			}
			if(!$this->memory_check(96)) {?>
				<div style="color:orange">Your PHP memory limit is too low.  Updraft attempted to raise it but was unsuccessful.  This plugin may not work properly with a memory limit of less than 96 Mb (though on the other hand, it has been used successfully with a 32Mb limit - your mileage may vary, but don't blame us!). Current limit is: <?php echo $this->memory_check_current(); ?> Mb</div>
			<?php
			}
			if(!$this->execution_time_check(300)) {?>
				<div style="color:orange">Your PHP max_execution_time is less than 300 seconds. This probably means you're running in safe_mode. Either disable safe_mode or modify your php.ini to set max_execution_time to a higher number. If you do not, there is a chance Updraft will be unable to complete a backup. Present limit is: <?php echo ini_get('max_execution_time'); ?> seconds.</div>
			<?php
			}

			if($this->scan_old_dirs()) {?>
				<div style="color:orange">You have old directories from a previous backup.  Click to delete them after you have verified that the restoration worked.</div>
				<form method="post" action="<?php echo remove_query_arg(array('updraft_restore_success','action')) ?>">
					<input type="hidden" name="action" value="updraft_delete_old_dirs" />
					<input type="submit" class="button-primary" value="Delete Old Dirs" onclick="return(confirm('Are you sure you want to delete the old directories?  This cannot be undone.'))" />
				</form>
			<?php
			}
			if(!empty($this->errors)) {
				foreach($this->errors as $error) {
					//ignoring severity here right now
					echo '<div style="color:red">'.$error['error'].'</div>';
				}
			}
			?>
			<table class="form-table" style="float:left;width:475px">
				<tr>
					<?php
					$next_scheduled_backup = wp_next_scheduled('updraft_backup');
					$next_scheduled_backup = ($next_scheduled_backup) ? date('D, F j, Y H:i T',$next_scheduled_backup) : 'No backups are scheduled at this time.';
					$next_scheduled_backup_database = wp_next_scheduled('updraft_backup_database');
					if (get_option('updraft_interval_database',get_option('updraft_interval')) == get_option('updraft_interval')) {
						$next_scheduled_backup_database = "Will take place at the same time as the files backup.";
					} else {
						$next_scheduled_backup_database = ($next_scheduled_backup_database) ? date('D, F j, Y H:i T',$next_scheduled_backup_database) : 'No backups are scheduled at this time.';
					}
					$current_time = date('D, F j, Y H:i T',time());
					$updraft_last_backup = get_option('updraft_last_backup');
					if($updraft_last_backup) {
						if($updraft_last_backup['success']) {
							$last_backup = date('D, F j, Y H:i T',$updraft_last_backup['backup_time']);
							$last_backup_color = 'green';
						} else {
							$last_backup = print_r($updraft_last_backup['errors'],true);
							$last_backup_color = 'red';
						}
					} else {
						$last_backup = 'No backup has been completed.';
						$last_backup_color = 'blue';
					}

					$updraft_dir = $this->backups_dir_location();
					if(is_writable($updraft_dir)) {
						$dir_info = '<span style="color:green">Backup directory specified is writable, which is good.</span>';
						$backup_disabled = "";
					} else {
						$backup_disabled = 'disabled="disabled"';
						$dir_info = '<span style="color:red">Backup directory specified is <b>not</b> writable, or does not exist. <span style="font-size:110%;font-weight:bold"><a href="options-general.php?page=updraftplus&action=updraft_create_backup_dir">Click here</a></span> to attempt to create the directory and set the permissions.  If that is unsuccessful check the permissions on your server or change it to another directory that is writable by your web server process.</span>';
					}
					?>
					<th>Now:</th>
					<td style="color:blue"><?php echo $current_time?></td>
				</tr>
				<tr>
					<th>Next Scheduled Files Backup:</th>
					<td style="color:blue"><?php echo $next_scheduled_backup?></td>
				</tr>
				<tr>
					<th>Next Scheduled DB Backup:</th>
					<td style="color:blue"><?php echo $next_scheduled_backup_database?></td>
				</tr>
				<tr>
					<th>Last Backup:</th>
					<td style="color:<?php echo $last_backup_color ?>"><?php echo $last_backup?></td>
				</tr>
			</table>
			<div style="float:left;width:200px">
				<form method="post" action="">
					<input type="hidden" name="action" value="updraft_backup" />
					<p><input type="submit" <?php echo $backup_disabled ?> class="button-primary" value="Backup Now!" style="padding-top:7px;padding-bottom:7px;font-size:24px !important" onclick="return(confirm('This will schedule a one time backup.  To trigger the backup immediately you may need to load a page on your site.'))" /></p>
				</form>
				<div style="position:relative">
					<div style="position:absolute;top:0;left:0">
						<?php
						$backup_history = get_option('updraft_backup_history');
						$backup_history = (is_array($backup_history))?$backup_history:array();
						$restore_disabled = (count($backup_history) == 0) ? 'disabled="disabled"' : "";
						?>
						<input type="button" class="button-primary" <?php echo $restore_disabled ?> value="Restore" style="padding-top:7px;padding-bottom:7px;font-size:24px !important" onclick="jQuery('#backup-restore').fadeIn('slow');jQuery(this).parent().fadeOut('slow')" />
					</div>
					<div style="display:none;position:absolute;top:0;left:0" id="backup-restore">
						<form method="post" action="">
							<b>Choose: </b>
							<select name="backup_timestamp" style="display:inline">
								<?php
								foreach($backup_history as $key=>$value) {
									echo "<option value='$key'>".date('Y-m-d G:i',$key)."</option>\n";
								}
								?>
							</select>

							<input type="hidden" name="action" value="updraft_restore" />
							<input type="submit" <?php echo $restore_disabled ?> class="button-primary" value="Restore Now!" style="padding-top:7px;margin-top:5px;padding-bottom:7px;font-size:24px !important" onclick="return(confirm('Restoring from backup will replace this site\'s themes, plugins, uploads and other content directories (according to what is contained in the backup set which you select). Database restoration cannot be done through this process - you must download the database and import yourself (e.g. through PHPMyAdmin). Do you wish to continue with the restoration process?'))" />
						</form>
					</div>
				</div>
			</div>
			<br style="clear:both" />
			<table class="form-table">
				<tr>
					<th>Download Backups</th>
					<td><a href="#" title="Click to see available backups" onclick="jQuery('.download-backups').toggle();return false;"><?php echo count($backup_history)?> available</a></td>
				</tr>
				<tr>
					<td></td><td class="download-backups" style="display:none">
						<em>Click on a button to download the corresponding file to your computer. If you are using Opera, you should turn Turbo mode off.</em>
						<table>
							<?php
							foreach($backup_history as $key=>$value) {
							?>
							<tr>
								<td><b><?php echo date('Y-m-d G:i',$key)?></b></td>
								<td>
							<?php if (isset($value['db'])) { ?>
									<form action="admin-ajax.php" method="post">
										<input type="hidden" name="action" value="updraft_download_backup" />
										<input type="hidden" name="type" value="db" />
										<input type="hidden" name="timestamp" value="<?php echo $key?>" />
										<input type="submit" value="Database" />
									</form>
							<?php } else { echo "(No database in backup)"; } ?>
								</td>
								<td>
							<?php if (isset($value['plugins'])) { ?>
									<form action="admin-ajax.php" method="post">
										<input type="hidden" name="action" value="updraft_download_backup" />
										<input type="hidden" name="type" value="plugins" />
										<input type="hidden" name="timestamp" value="<?php echo $key?>" />
										<input type="submit" value="Plugins" />
									</form>
							<?php } else { echo "(No plugins in backup)"; } ?>
								</td>
								<td>
							<?php if (isset($value['themes'])) { ?>
									<form action="admin-ajax.php" method="post">
										<input type="hidden" name="action" value="updraft_download_backup" />
										<input type="hidden" name="type" value="themes" />
										<input type="hidden" name="timestamp" value="<?php echo $key?>" />
										<input type="submit" value="Themes" />
									</form>
							<?php } else { echo "(No themes in backup)"; } ?>
								</td>
								<td>
							<?php if (isset($value['uploads'])) { ?>
									<form action="admin-ajax.php" method="post">
										<input type="hidden" name="action" value="updraft_download_backup" />
										<input type="hidden" name="type" value="uploads" />
										<input type="hidden" name="timestamp" value="<?php echo $key?>" />
										<input type="submit" value="Uploads" />
									</form>
							<?php } else { echo "(No uploads in backup)"; } ?>
								</td>
								<td>
							<?php if (isset($value['others'])) { ?>
									<form action="admin-ajax.php" method="post">
										<input type="hidden" name="action" value="updraft_download_backup" />
										<input type="hidden" name="type" value="others" />
										<input type="hidden" name="timestamp" value="<?php echo $key?>" />
										<input type="submit" value="Others" />
									</form>
							<?php } else { echo "(No others in backup)"; } ?>
								</td>
							</tr>
							<?php }?>
						</table>
					</td>
				</tr>
			</table>
			<form method="post" action="options.php">
			<?php settings_fields('updraft-options-group'); ?>
			<table class="form-table">
				<tr>
					<th>Backup Directory:</th>
					<td><input type="text" name="updraft_dir" style="width:525px" value="<?php echo $updraft_dir ?>" /></td>
				</tr>
				<tr>
					<td></td><td><?php echo $dir_info ?> This is where Updraft Backup/Restore will write the zip files it creates initially.  This directory must be writable by your web server.  Typically you'll want to have it inside your wp-content folder (this is the default).  <b>Do not</b> place it inside your uploads dir, as that will cause recursion issues (backups of backups of backups of...).</td>
				</tr>
				<tr>
					<th>File Backup Intervals:</th>
					<td><select name="updraft_interval">
						<?php
						$intervals = array ("manual", "daily", "weekly", "monthly");
						foreach ($intervals as $ival) {
							echo "<option value=\"$ival\" ";
							if ($ival == get_option('updraft_interval','manual')) { echo 'selected="selected"';}
							echo ">".ucfirst($ival)."</option>\n";
						}
						?>
						</select></td>
				</tr>
				<tr>
					<th>Database Backup Intervals:</th>
					<td><select name="updraft_interval_database">
						<?php
						$intervals = array ("manual", "daily", "weekly", "monthly");
						foreach ($intervals as $ival) {
							echo "<option value=\"$ival\" ";
							if ($ival == get_option('updraft_interval_database',get_option('updraft_interval'))) { echo 'selected="selected"';}
							echo ">".ucfirst($ival)."</option>\n";
						}
						?>
						</select></td>
				</tr>
				<tr class="backup-interval-description">
					<td></td><td>If you would like to automatically schedule backups, choose schedules from the dropdown above. Backups will occur at the interval specified starting just after the current time.  If you choose manual you must click the &quot;Backup Now!&quot; button whenever you wish a backup to occur. If the two schedules are the same, then the two backups will take place together.</td>
				</tr>
				<?php
					# The true (default value if non-existent) here has the effect of forcing a default of on.
					$include_themes = (get_option('updraft_include_themes',true)) ? 'checked="checked"' : "";
					$include_plugins = (get_option('updraft_include_plugins',true)) ? 'checked="checked"' : "";
					$include_uploads = (get_option('updraft_include_uploads',true)) ? 'checked="checked"' : "";
					$include_others = (get_option('updraft_include_others',true)) ? 'checked="checked"' : "";
					$include_others_exclude = get_option('updraft_include_others_exclude',UPDRAFT_DEFAULT_OTHERS_EXCLUDE);
				?>
				<tr>
					<th>Include in Files Backup:</th>
					<td>
					<input type="checkbox" name="updraft_include_plugins" value="1" <?php echo $include_plugins; ?> /> Plugins<br />
					<input type="checkbox" name="updraft_include_themes" value="1" <?php echo $include_themes; ?> /> Themes<br />
					<input type="checkbox" name="updraft_include_uploads" value="1" <?php echo $include_uploads; ?> /> Uploads<br />
					<input type="checkbox" name="updraft_include_others" value="1" <?php echo $include_others; ?> /> Any other directories found inside wp-content - but exclude these directories: <input type="text" name="updraft_include_others_exclude" size="32" value="<?php echo htmlspecialchars($include_others_exclude); ?>"/><br />
					Include all of these, unless you are backing them up separately. Note that presently UpdraftPlus backs up these directories only - which is usually everything (except for WordPress core itself which you can download afresh from WordPress.org). But if you have made customised modifications outside of these directories, you need to back them up another way.<br />(<a href="http://wordshell.net">Use WordShell</a> for automatic backup, version control and patching).<br /></td>
					</td>
				</tr>
				<tr>
					<th>Retain Backups:</th>
					<?php
					$updraft_retain = get_option('updraft_retain');
					$retain = ((int)$updraft_retain > 0)?get_option('updraft_retain'):1;
					?>
					<td><input type="text" name="updraft_retain" value="<?php echo $retain ?>" style="width:50px" /></td>
				</tr>
				<tr class="backup-retain-description">
					<td></td><td>By default only the most recent backup is retained. If you'd like to preserve more, specify the number here. (This many of <strong>both</strong> files and database backups will be retained.)</td>
				</tr>
				<tr>
					<th>Database encryption phrase:</th>
					<?php
					$updraft_encryptionphrase = get_option('updraft_encryptionphrase');
					?>
					<td><input type="text" name="updraft_encryptionphrase" value="<?php echo $updraft_encryptionphrase ?>" style="width:132px" /></td>
				</tr>
				<tr class="backup-crypt-description">
					<td></td><td>If you enter a string here, it is used to encrypt backups (Rijndael). Do not lose it, or all your backups will be useless. Presently, only the database file is encrypted. This is also the key used to decrypt backups from this admin interface (so if you change it, then automatic decryption will not work until you change it back). You can also use the file example-decrypt.php from inside the UpdraftPlus plugin directory to decrypt manually.</td>
				</tr>

				<tr>
					<th>Remote backup:</th>
					<td><select name="updraft_service" id="updraft-service">
						<?php
						$delete_local = (get_option('updraft_delete_local')) ? 'checked="checked"' : "";
						$debug_mode = (get_option('updraft_debug_mode')) ? 'checked="checked"' : "";

						$display_none = 'style="display:none"';
						$s3 = ""; $ftp = ""; $email = ""; $googledrive="";
						$email_display="";
						$display_email_complete = "";
						$set = 'selected="selected"';
						switch(get_option('updraft_service')) {
							case 's3':
								$s3 = $set;
								$googledrive_display = $display_none;
								$ftp_display = $display_none;
							break;
							case 'googledrive':
								$googledrive = $set;
								$s3_display = $display_none;
								$ftp_display = $display_none;
							break;
							case 'ftp':
								$ftp = $set;
								$googledrive_display = $display_none;
								$s3_display = $display_none;
							break;
							case 'email':
								$email = $set;
								$ftp_display = $display_none;
								$s3_display = $display_none;
								$googledrive_display = $display_none;
								$display_email_complete = $display_none;
							break;
							default:
								$none = $set;
								$ftp_display = $display_none;
								$googledrive_display = $display_none;
								$s3_display = $display_none;
								$display_delete_local = $display_none;
							break;
						}
						?>
						<option value="none" <?php echo $none?>>None</option>
						<option value="s3" <?php echo $s3?>>Amazon S3</option>
						<option value="googledrive" <?php echo $googledrive?>>Google Drive (experimental, may work for you, may not)</option>
						<option value="ftp" <?php echo $ftp?>>FTP</option>
						<option value="email" <?php echo $email?>>E-mail</option>
						</select></td>
				</tr>
				<tr class="backup-service-description">
					<td></td><td>Choose your backup method. Be aware that mail servers tend to have strict file size limitations; typically around 10-20Mb; backups larger than this may not arrive. Select none if you do not wish to send your backups anywhere <b>(not recommended)</b>.</td>
				
				</tr>

				<!-- Amazon S3 -->
				<tr class="s3" <?php echo $s3_display?>>
					<th>S3 access key:</th>
					<td><input type="text" autocomplete="off" style="width:292px" name="updraft_s3_login" value="<?php echo get_option('updraft_s3_login') ?>" /></td>
				</tr>
				<tr class="s3" <?php echo $s3_display?>>
					<th>S3 secret key:</th>
					<td><input type="password" autocomplete="off" style="width:292px" name="updraft_s3_pass" value="<?php echo get_option('updraft_s3_pass'); ?>" /></td>
				</tr>
				<tr class="s3" <?php echo $s3_display?>>
					<th>S3 location:</th>
					<td>s3://<input type="text" style="width:292px" name="updraft_s3_remote_path" value="<?php echo get_option('updraft_s3_remote_path'); ?>" /></td>
				</tr>
				<tr class="s3" <?php echo $s3_display?>>
				<th></th>
				<td><p>Get your access key and secret key from your AWS page, then pick a (globally unique) bucket name (letters and numbers) (and optionally a path) to use for storage.</p></td>
				</tr>

				<!-- Google Drive -->
				<tr class="googledrive" <?php echo $googledrive_display?>>
					<th>Google Drive Client ID:</th>
					<td><input type="text" autocomplete="off" style="width:332px" name="updraft_googledrive_clientid" value="<?php echo get_option('updraft_googledrive_clientid') ?>" /></td>
				</tr>
				<tr class="googledrive" <?php echo $googledrive_display?>>
					<th>Google Drive Client Secret:</th>
					<td><input type="password" autocomplete="off" style="width:332px" name="updraft_googledrive_secret" value="<?php echo get_option('updraft_googledrive_secret'); ?>" /></td>
				</tr>
				<tr class="googledrive" <?php echo $googledrive_display?>>
					<th>Google Drive Folder ID:</th>
					<td><input type="text" style="width:332px" name="updraft_googledrive_remotepath" value="<?php echo get_option('updraft_googledrive_remotepath'); ?>" /> <em>(Leave empty to use your root folder)</em></td>
				</tr>
				<tr class="googledrive" <?php echo $googledrive_display?>>
					<th>Authenticate with Google:</th>
					<td><p><a href="?page=updraftplus&action=auth&updraftplus_googleauth=doit"><strong>After</strong> you have saved your settings (by clicking &quot;Save Changes&quot; below), then come back here once and click this link to complete authentication with Google.</a>

					<?php
						if (get_option('updraft_googledrive_token','xyz') != 'xyz') {
							echo " (You appear to be already authenticated)";
						}
					?>
				</p>
				<p>To get a folder's ID navigate to that folder in Google Drive and copy the ID from your browser's address bar. It is the part that comes after <kbd>#folders/.</kbd></p>
				<p><strong>N.B. : If you choose Google Drive, then no backups will be deleted - all will be retained. Patches welcome!</strong></p>
				</td>
				</tr>
				<tr class="googledrive" <?php echo $googledrive_display?>>
				<th></th>
				<td><p>Create a Client ID in the API Access section of your <a href="https://code.google.com/apis/console/">Google API Console</a>. Select 'Web Application' as the application type.</p><p>You must add <kbd><?php echo admin_url('options-general.php?page=updraftplus&action=auth'); ?></kbd> as the authorised redirect URI when asked.</p>
				<?php
					if (!class_exists('SimpleXMLElement')) { echo "<p><b>WARNING:</b> You do not have SimpleXMLElement installed. Google Drive backups will <b>not</b> work until you do.</p>"; }
				?>
				</td>
				</tr>

				<tr class="ftp" <?php echo $ftp_display?>>
					<th><a href="#" title="Click for help!" onclick="jQuery('.ftp-description').toggle();return false;">FTP Server:</a></th>
					<td><input type="text" style="width:260px" name="updraft_server_address" value="<?php echo get_option('updraft_server_address'); ?>" /></td>
				</tr>
				<tr class="ftp" <?php echo $ftp_display?>>
					<th><a href="#" title="Click for help!" onclick="jQuery('.ftp-description').toggle();return false;">FTP Login:</a></th>
					<td><input type="text" autocomplete="off" name="updraft_ftp_login" value="<?php echo get_option('updraft_ftp_login') ?>" /></td>
				</tr>
				<tr class="ftp" <?php echo $ftp_display?>>
					<th><a href="#" title="Click for help!" onclick="jQuery('.ftp-description').toggle();return false;">FTP Password:</a></th>
					<td><input type="password" autocomplete="off" style="width:260px" name="updraft_ftp_pass" value="<?php echo get_option('updraft_ftp_pass'); ?>" /></td>
				</tr>
				<tr class="ftp" <?php echo $ftp_display?>>
					<th><a href="#" title="Click for help!" onclick="jQuery('.ftp-description').toggle();return false;">Remote Path:</a></th>
					<td><input type="text" style="width:260px" name="updraft_ftp_remote_path" value="<?php echo get_option('updraft_ftp_remote_path'); ?>" /></td>
				</tr>
				<tr class="ftp-description" style="display:none">
					<td colspan="2">An FTP remote path will look like '/home/backup/some/folder'</td>
				</tr>
				<tr class="email" <?php echo $email_display?>>
					<th>Email:</th>
					<td><input type="text" style="width:260px" name="updraft_email" value="<?php echo get_option('updraft_email'); ?>" /> <br />Enter an address here to have a report sent (and the whole backup, if you choose) to it.</td>
				</tr>
				<tr class="deletelocal s3 ftp email" <?php echo $display_delete_local?>>
					<th>Delete local backup:</th>
					<td><input type="checkbox" name="updraft_delete_local" value="1" <?php echo $delete_local; ?> /> <br />Check this to delete the local backup file (only sensible if you have enabled a remote backup, otherwise you will have no backup remaining).</td>
				</tr>
				<tr>
					<th>Debug mode:</th>
					<td><input type="checkbox" name="updraft_debug_mode" value="1" <?php echo $debug_mode; ?> /> <br />Check this for more information, if something is going wrong. Will also drop a log file in your backup directory which you can examine.</td>
				</tr>
				<tr>
					<td>
						<input type="hidden" name="action" value="update" />
						<input type="submit" class="button-primary" value="Save Changes" />
					</td>
				</tr>
			</table>
			</form>
			<?php
			if(get_option('updraft_debug_mode')) {
			?>
			<div>
				<h3>Debug Information</h3>
				<?php
				$peak_memory_usage = memory_get_peak_usage(true)/1024/1024;
				$memory_usage = memory_get_usage(true)/1024/1024;
				echo 'Peak memory usage: '.$peak_memory_usage.' MB<br/>';
				echo 'Current memory usage: '.$memory_usage.' MB<br/>';
				echo 'PHP memory limit: '.ini_get('memory_limit').' <br/>';
				?>
				<form method="post" action="">
					<input type="hidden" name="action" value="updraft_backup_debug_all" />
					<p><input type="submit" class="button-primary" <?php echo $backup_disabled ?> value="Debug Backup" onclick="return(confirm('This will cause an immediate backup.  The page will stall loading until it finishes (ie, unscheduled).  Use this if you\'re trying to see peak memory usage.'))" /></p>
				</form>
				<form method="post" action="">
					<input type="hidden" name="action" value="updraft_backup_debug_db" />
					<p><input type="submit" class="button-primary" <?php echo $backup_disabled ?> value="Debug DB Backup" onclick="return(confirm('This will cause an immediate DB backup.  The page will stall loading until it finishes (ie, unscheduled). The backup will remain locally despite your prefs and will not go into the backup history or up into the cloud.'))" /></p>
				</form>
			</div>
			<?php } ?>
			<script type="text/javascript">
				jQuery(document).ready(function() {
					jQuery('#updraft-service').change(function() {
						switch(jQuery(this).val()) {
							case 'none':
								jQuery('.deletelocal,.s3,.ftp,.googledrive,.s3-description,.ftp-description').hide()
								jQuery('.email,.email-complete').show()
							break;
							case 's3':
								jQuery('.ftp,.ftp-description,.googledrive').hide()
								jQuery('.s3,.deletelocal,.email,.email-complete').show()
							break;
							case 'googledrive':
								jQuery('.ftp,.ftp-description,.s3').hide()
								jQuery('.googledrive,.deletelocal,.googledrive,.email,.email-complete').show()
							break;
							case 'ftp':
								jQuery('.googledrive,.s3,.s3-description').hide()
								jQuery('.ftp,.deletelocal,.email,.email-complete').show()
							break;
							case 'email':
								jQuery('.s3,.ftp,.s3-description,.googledrive,.ftp-description,.email-complete').hide()
								jQuery('.email,.deletelocal').show()
							break;
						}
					})
				})
				jQuery(window).load(function() {
					//this is for hiding the restore progress at the top after it is done
					setTimeout('jQuery("#updraft-restore-progress").toggle(1000)',3000)
					jQuery('#updraft-restore-progress-toggle').click(function() {
						jQuery('#updraft-restore-progress').toggle(500)
					})
				})
			</script>
			<?php
	}
	
	/*array2json provided by bin-co.com under BSD license*/
	function array2json($arr) { 
		if(function_exists('json_encode')) return stripslashes(json_encode($arr)); //Latest versions of PHP already have this functionality. 
		$parts = array(); 
		$is_list = false; 

		//Find out if the given array is a numerical array 
		$keys = array_keys($arr); 
		$max_length = count($arr)-1; 
		if(($keys[0] == 0) and ($keys[$max_length] == $max_length)) {//See if the first key is 0 and last key is length - 1 
			$is_list = true; 
			for($i=0; $i<count($keys); $i++) { //See if each key correspondes to its position 
				if($i != $keys[$i]) { //A key fails at position check. 
					$is_list = false; //It is an associative array. 
					break; 
				} 
			} 
		} 

		foreach($arr as $key=>$value) { 
			if(is_array($value)) { //Custom handling for arrays 
				if($is_list) $parts[] = $this->array2json($value); /* :RECURSION: */ 
				else $parts[] = '"' . $key . '":' . $this->array2json($value); /* :RECURSION: */ 
			} else { 
				$str = ''; 
				if(!$is_list) $str = '"' . $key . '":'; 

				//Custom handling for multiple data types 
				if(is_numeric($value)) $str .= $value; //Numbers 
				elseif($value === false) $str .= 'false'; //The booleans 
				elseif($value === true) $str .= 'true'; 
				else $str .= '"' . addslashes($value) . '"'; //All other things 
				// :TODO: Is there any more datatype we should be in the lookout for? (Object?) 

				$parts[] = $str; 
			} 
		} 
		$json = implode(',',$parts); 

		if($is_list) return '[' . $json . ']';//Return numerical JSON 
		return '{' . $json . '}';//Return associative JSON 
	}

	function show_admin_warning($message) {
		echo '<div id="updraftmessage" class="updated fade">';
		echo "<p>$message</p></div>";
	}
	function show_admin_warning_accessible() {
		$this->show_admin_warning("UpdraftPlus backup directory specified is accessible via the web.  This is a potential security problem (people may be able to download your backups - which is undesirable if your database is not encrypted and if you have non-public assets amongst the files). If using Apache, enable .htaccess support to allow web access to be denied; otherwise, you should deny access manually.");
	}
	function show_admin_warning_googledrive() {
		$this->show_admin_warning('UpdraftPlus notice: <a href="?page=updraftplus&action=auth&updraftplus_googleauth=doit">Click here to authenticate your Google Drive account (you will not be able to back up to Google Drive without it).</a>');
	}
	function show_admin_warning_accessible_unknownresult() {
		$this->show_admin_warning("UpdraftPlus tried to check if the backup directory is accessible via web, but the result was unknown.");
	}


}

?>
