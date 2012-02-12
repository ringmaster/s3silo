<?php if ( !defined( 'HABARI_PATH' ) ) { die( 'No direct access' ); }

/**
 * Simple file access silo
 *
 * @todo Create some helper functions in a superclass to display panel controls more easily, so that you don't need to include 80 lines of code to build a simple upload form every time.
 */

include 's3silo.php';

class S3SiloPlugin extends Plugin implements MediaSilo
{
	protected $root = null;
	protected $url = null;

	const SILO_NAME = 'S3';

	const DERIV_DIR = '.deriv';

	/**
	 * Initialize some internal values when plugin initializes
	 */
	public function action_init()
	{
		$user_path = HABARI_PATH . '/' . Site::get_path( 'user', true );
		$this->root = $user_path . 'files'; //Options::get('simple_file_root');

		$this->url = Options::get('s3_url');
		$this->bucket = Options::get('s3_bucket');
		$this->key = Options::get('s3_key');
		$this->private_key = Options::get('s3_secret');
	}

	public function configure()
	{
		$ui = new FormUI( "membership" );
		$ui->append( 'text', 'url', 's3_url', _t( 'Base URL of the bucket (like "http://foo.com")', 's3siloplugin' ) );

		$ui->append( 'text', 'key', 's3_key', _t( 'Key', 's3siloplugin' ) );
		$key = $ui->append( 'password', 'secret', 's3_secret', _t( 'Secret Key', 's3siloplugin' ) );
		$key->add_validator( array( $this, 'key_validator' ) );

		$s3 = $this->gets3(true);
		$buckets = $s3->listBuckets();

		if($buckets !== false && $this->key != '' && $this->private_key != '') {
			$s3 = $this->gets3();
			$buckets = $s3->listBuckets();
			$ui->append( 'select', 'bucket', 's3_bucket', _t( 'The bucket address', 's3siloplugin' ), $buckets );
		}

		$ui->on_success( array( $this, 'updated_config' ) );
		$ui->append( 'submit', 'save', _t( 'Save', 's3siloplugin' ) );
		return $ui;
	}

	public function key_validator($value, $control, $form) {
		$this->key = $form->key->value;
		$this->private_key = $value;

		$s3 = $this->gets3(true);
		$buckets = $s3->listBuckets();

		if($buckets === false) {
			return array( _t( 'There were no buckets returned when using this key and sercret key', 'membership' ) );
		}
		return array();
	}

	public function updated_config( FormUI $ui )
	{
		Session::notice( _t( 'Settings saved.' , 's3siloplugin' ) );
		$ui->save();
		if(is_null(Options::get('s3_bucket'))) {
			Session::notice( _t( 'Please select a bucket from your S3 account.' , 's3siloplugin' ) );
		}

		Utils::redirect();
	}

	public function filter_activate_plugin( $ok, $file )
	{
		// Don't bother loading if the gd library isn't active
		if ( !function_exists( 'imagecreatefromjpeg' ) ) {
			EventLog::log( _t( "S3 Silo activation failed. PHP has not loaded the gd imaging library." ), 'warning', 'plugin' );
			Session::error( _t( "S3 Silo activation failed. PHP has not loaded the gd imaging library." ) );
			$ok = false;
		}
		return $ok;
	}

	public function action_plugin_activation( $file )
	{
		// Create required tokens
		ACL::create_token( 'upload_s3_media', _t( 'Upload files to s3 silos' ), 'Administration' );
		ACL::create_token( 'delete_s3_media', _t( 'Delete files from s3 silos' ), 'Administration' );
	}

	/**
	*
	* @param string $file. The name of the plugin file
	*
	* Delete the special silo permissions if they're no longer
	* being used.
	*/
	public function action_plugin_deactivation( $file ) {
		ACL::destroy_token( 'upload_s3_media' );
		ACL::destroy_token( 'delete_s3_media' );
	}

	public function gets3($reload = false) {
		static $s3 = null;

		if(!isset($s3) || $reload) {
			$s3 = new S3Silo($this->key, $this->private_key);
		}
		return $s3;
	}

	/**
	 * Return basic information about this silo
	 *   name- The name of the silo, used as the root directory for media in this silo
	 **/
	public function silo_info()
	{
		$s3 = $this->gets3();
		$contents = $s3->listBuckets();
		var_dump($contents);
		return array(
			'name' => self::SILO_NAME,
			'icon' => URL::get_from_filesystem( __FILE__ ) . '/icon.png'
		);
	}

	/**
	 * Return directory contents for the silo path
	 * @param string $path The path to retrieve the contents of
	 * @return array An array of MediaAssets describing the contents of the directory
	 **/
	public function silo_dir( $path )
	{
		if ( !isset( $this->root ) ) {
			return array();
		}

		$path = preg_replace( '%\.{2,}%', '.', $path );
		$path = $path . ($path == '' ? '' : '/');
		$results = array();

		$s3 = $this->gets3();
		$contents = $s3->getBucketContents($this->bucket, $path);

		foreach ( $contents as $item => $data ) {
			$file = basename( $item );
			$props = array(
				'title' => basename( $item ),
			);
			if($data['Size'] == 0 && substr($item, -1) == '/') {
				if($item != $path) {
					$results[] = new MediaAsset(
						self::SILO_NAME . '/' . $path . basename( $item ),
						true,
						$props
					);
				}
			}
			else {
				if(strpos(str_replace($path, '', $item), '/') === false) {
					$results[] = $this->silo_get( $path . basename( $item ) );
				}
			}
		}
		//var_dump($path, $contents, $results);
		//print_r($results);
		return $results;
	}


	/**
	 * Get the file from the specified path
	 * @param string $path The path of the file to retrieve
	 * @param array $qualities Qualities that specify the version of the file to retrieve.
	 * @return MediaAsset The requested asset
	 **/
	public function silo_get( $path, $qualities = null )
	{
		if ( ! isset( $this->root ) ) {
			return false;
		}

		$path = preg_replace( '%\.{2,}%', '.', $path );

		$dir = dirname($path);
		if($dir == '.') {
			$dir = '';
		}
		$file = basename( $path );

		$props = array(
			'title' => basename( $path ),
		);

		$s3 = $this->gets3();

		$thumbnail_suffix = HabariSilo::DERIV_DIR . '/' . $file . '.thumbnail.jpg';
		$thumbnail_url = $this->url . '/' . dirname( $path ) . ( dirname( $path ) == '' ? '' : '/' ) . $thumbnail_suffix;
		$mimetype = preg_replace(' %[^a-z_0-9]%', '_', Utils::mimetype( $path ) );
		$mtime = '';

		if ( !$this->create_thumbnail( $path ) ) {
			// there is no thumbnail so use icon based on mimetype.
			$icon_path = Plugins::filter( 'habarisilo_icon_base_path', dirname( $this->get_file() ) . '/icons' );
			$icon_url = Plugins::filter( 'habarisilo_icon_base_url', $this->get_url() . '/icons' );

			if ( ( $icons = Utils::glob( $icon_path . '/*.{png,jpg,gif,svg}', GLOB_BRACE ) ) && $mimetype ) {
				$icon_keys = array_map( create_function( '$a', 'return pathinfo($a, PATHINFO_FILENAME);' ), $icons );
				$icons = array_combine( $icon_keys, $icons );
				$icon_filter = create_function( '$a, $b', "\$mime = '$mimetype';".'return (((strpos($mime, $a)===0) ? (strlen($a) / strlen($mime)) : 0) >= (((strpos($mime, $b)===0)) ? (strlen($b) / strlen($mime)) : 0)) ? $a : $b;' );
				$icon_key = array_reduce( $icon_keys, $icon_filter );
				if ($icon_key) {
					$icon = basename( $icons[$icon_key] );
					$thumbnail_url = $icon_url .'/'. $icon;
				}
				else {
					// couldn't find an icon so use default
					$thumbnail_url = $icon_url .'/default.png';
				}
			}
		}

		// If the asset is an image, obtain the image dimensions
/*		if ( in_array( $mimetype, array( 'image_jpeg', 'image_png', 'image_gif' ) ) ) {
			list( $props['width'], $props['height'] ) = getimagesize( $realfile );
			$mtime = '?' . filemtime( $realfile );
		}*/
		$props = array_merge(
			$props,
			array(
				'url' => $this->url . '/' . $dir . ( $dir == '' ? '' : '/' ) . $file,
				'thumbnail_url' => $thumbnail_url . $mtime,
				'filetype' => $mimetype,
			)
		);
		
		$asset = new MediaAsset( self::SILO_NAME . '/' . $path, false, $props );
		$asset->load($props['url']);
		return $asset;
	}


	/**
	 * Create a thumbnail in the derivative directory
	 *
	 * @param string $src_filename The source filename
	 * @param integer $max_width The maximum width of the output image
	 * @param integer $max_height The maximum height of the output image
	 * @return boolean true if the thumbnail creation succeeded
	 */
	private function create_thumbnail( $src_filename, $max_width = Media::THUMBNAIL_WIDTH, $max_height = Media::THUMBNAIL_HEIGHT )
	{
		// Does derivative directory not exist?
		$thumbdir = dirname( $src_filename ) . '/' . HabariSilo::DERIV_DIR . '';

		// Define the thumbnail filename
		$dst_filename = $thumbdir . '/' . basename( $src_filename ) . ".thumbnail.jpg";

		// Does the destination thumbnail exist?
		$s3 = $this->gets3();
		$oi = $s3->getObjectInfo($this->bucket, $dst_filename);
		if(!$oi) { // If we have a thumbnail already, return true
			var_dump($dst_filename, $oi);
			return true;
		}

//		$fiveMBs = 5 * 1024 * 1024;
//		$fh = fopen("php://temp/maxmemory:{$fiveMBs}", 'r+');
		$fh = tmpfile();

		// Get the file from S3
		$s3->downloadFile($this->bucket, $src_filename, $fh);
		$finfo = stream_get_meta_data($fh);

		// Get information about the image
		list( $src_width, $src_height, $type, $attr )= getimagesize( $finfo['uri'] );

		// Do dumb things since PHP has no real stream handling functions
		$src_filename = $finfo['uri'];

		// Load the image based on filetype
		switch ( $type ) {
		case IMAGETYPE_JPEG:
			$src_img = imagecreatefromjpeg( $src_filename );
			break;
		case IMAGETYPE_PNG:
			$src_img = imagecreatefrompng( $src_filename );
			break;
		case IMAGETYPE_GIF:
			$src_img = imagecreatefromgif ( $src_filename );
			break;
		default:
			return false;
		}
		fclose($fh);

		// Did the image fail to load?
		if ( !$src_img ) {
			return false;
		}

		// Calculate the output size based on the original's aspect ratio
		$y_displacement = 0;
		if ( $src_width / $src_height > $max_width / $max_height ) {
			$thumb_w = $max_width;
			$thumb_h = $src_height * $max_width / $src_width;

		// thumbnail is not full height, position it down so that it will be padded on the
		// top and bottom with black
		$y_displacement = ( $max_height - $thumb_h ) / 2;
		}
		else {
			$thumb_w = $src_width * $max_height / $src_height;
			$thumb_h = $max_height;
		}

		// Create the output image and copy to source to it
		$dst_img = ImageCreateTrueColor( $thumb_w, $max_height );
		imagecopyresampled( $dst_img, $src_img, 0, $y_displacement, 0, 0, $thumb_w, $thumb_h, $src_width, $src_height );

		/* Sharpen before save?
		$sharpenMatrix= array( array(-1, -1, -1), array(-1, 16, -1), array(-1, -1, -1) );
		$divisor= 8;
		$offset= 0;
		imageconvolution( $dst_img, $sharpenMatrix, $divisor, $offset );
		//*/

		// Save the thumbnail as a JPEG
		$fh = tmpfile();
		$finfo = stream_get_meta_data($fh);

		imagejpeg( $dst_img, $finfo['uri'] );
		$s3->uploadFile($this->bucket, $dst_filename, $finfo['uri'], true);

		fclose($fh);

		// Clean up memory
		imagedestroy( $dst_img );
		imagedestroy( $src_img );

		return true;
	}

	/**
	 * Create a new asset instance for the specified path
	 * @param string $path The path of the new file to create
	 * @return MediaAsset The requested asset
	 **/
	public function silo_new( $path )
	{
	}

	/**
	 * Store the specified media at the specified path
	 * @param string $path The path of the file to retrieve
	 * @param MediaAsset $filedata The MediaAsset to store
	 * @return boolean True on success
	 **/
	public function silo_put( $path, $filedata )
	{
		$path = preg_replace('%\.{2,}%', '.', $path);
		$file = $this->root . '/' . $path;

		$result = $filedata->save( $file );
		if ( $result ) {
			$this->create_thumbnail( $file );
		}

		return $result;
	}

	/**
	 * Delete the file at the specified path
	 * @param string $path The path of the file to retrieve
	 **/
	public function silo_delete( $path )
	{
		$file = $this->root . '/' . $path;

		// Delete the file
		$result = unlink( $file );

		// If it's an image, remove the file in .deriv too
		$thumbdir = dirname( $file ) . '/' . HabariSilo::DERIV_DIR . '';
		$thumb = $thumbdir . '/' . basename( $file ) . ".thumbnail.jpg";

		if ( file_exists( $thumbdir ) && file_exists( $thumb ) ) {
			unlink( $thumb );
			// if this is the last thumb, delete the .deriv dir too
			if ( self::isEmptyDir( $thumbdir ) ) {
				rmdir( $thumbdir );
			}
		}
		return $result;
	}

	/**
	 * Retrieve a set of highlights from this silo
	 * This would include things like recently uploaded assets, or top downloads
	 * @return array An array of MediaAssets to highlihgt from this silo
	 **/
	public function silo_highlights()
	{
	}

	/**
	 * Retrieve the permissions for the current user to access the specified path
	 * @param string $path The path to retrieve permissions for
	 * @return array An array of permissions constants (MediaSilo::PERM_READ, MediaSilo::PERM_WRITE)
	 **/
	public function silo_permissions( $path )
	{
	}

	/**
	 * Produce a link for the media control bar that causes a specific path to be displayed
	 *
	 * @param string $path The path to display
	 * @param string $title The text to use for the link in the control bar
	 * @return string The link to create
	 */
	public function link_path( $path, $title = '' )
	{
		if ( $title == '' ) {
			$title = basename( $path );
		}
		return '<a href="#" onclick="habari.media.showdir(\''.$path.'\');return false;">' . $title . '</a>';
	}

	/**
	 * Produce a link for the media control bar that causes a specific panel to be displayed
	 *
	 * @param string $path The path to pass
	 * @param string $path The panel to display
	 * @param string $title The text to use for the link in the control bar
	 * @return string The link to create
	 */
	public function link_panel( $path, $panel, $title )
	{
		return '<a href="#" onclick="habari.media.showpanel(\''.$path.'\', \''.$panel.'\');return false;">' . $title . '</a>';
	}

	/**
	 * Provide controls for the media control bar
	 *
	 * @param array $controls Incoming controls from other plugins
	 * @param MediaSilo $silo An instance of a MediaSilo
	 * @param string $path The path to get controls for
	 * @param string $panelname The name of the requested panel, if none then emptystring
	 * @return array The altered $controls array with new (or removed) controls
	 *
	 * @todo This should really use FormUI, but FormUI needs a way to submit forms via ajax
	 */
	public function filter_media_controls( $controls, $silo, $path, $panelname )
	{
		$class = __CLASS__;
		if ( $silo instanceof $class ) {
			$controls[] = $this->link_path( self::SILO_NAME . '/' . $path, _t( 'Browse' ) );
			if ( User::identify()->can( 'upload_media' ) ) {
				$controls[] = $this->link_panel( self::SILO_NAME . '/' . $path, 'upload', _t( 'Upload' ) );
			}
			if ( User::identify()->can( 'create_directories' ) ) {
				$controls[] = $this->link_panel( self::SILO_NAME . '/' . $path, 'mkdir', _t( 'Create Directory' ) );
			}
			if ( User::identify()->can( 'delete_directories' ) && ( $path && self::isEmptyDir( $this->root . '/' . $path ) ) ) {
				$controls[] = $this->link_panel( self::SILO_NAME . '/' . $path, 'rmdir', _t( 'Delete Directory' ) );
			}
		}
		return $controls;
	}

	/**
	 * Provide requested media panels for this plugin
	 *
	 * Regarding Uploading:
	 * A panel is returned to the media bar that contains a form, an iframe, and a javascript function.
	 * The form allows the user to select a file, and is submitted back to the same URL that produced this panel in the first place.
	 * This has the result of submitting the uploaded file to here when the form is submitted.
	 * To prevent the panel form from reloading the whole publishing page, the form is submitted into the iframe.
	 * An onload event attached to the iframe calls the function.
	 * The function accesses the content of the iframe when it loads, which should contain the results of the request to obtain this panel, which are in JSON format.
	 * The JSON data is passed to the habari.media.jsonpanel() function in media.js to process the data and display the results, just like when displaying a panel normally.
	 *
	 * @param string $panel The HTML content of the panel to be output in the media bar
	 * @param MediaSilo $silo The silo for which the panel was requested
	 * @param string $path The path within the silo (silo root omitted) for which the panel was requested
	 * @param string $panelname The name of the requested panel
	 * @return string The modified $panel to contain the HTML output for the requested panel
	 *
	 * @todo Move the uploaded file from the temporary location to the location indicated by the path field.
	 */
	public function filter_media_panels( $panel, $silo, $path, $panelname)
	{
		$class = __CLASS__;
		if ( $silo instanceof $class ) {
			switch ( $panelname ) {
				case 'mkdir':

					$fullpath = self::SILO_NAME . '/' . $path;

					$form = new FormUI( 'habarisilomkdir' );
					$form->append( 'static', 'ParentDirectory', '<div style="margin: 10px auto;">' . _t( 'Parent Directory:' ) . " <strong>/{$path}</strong></div>" );

					// add the parent directory as a hidden input for later validation
					$form->append( 'hidden', 'path', 'null:unused' )->value = $path;
					$form->append( 'hidden', 'action', 'null:unused')->value = $panelname;
					$dir_text_control = $form->append( 'text', 'directory', 'null:unused', _t('What would you like to call the new directory?') );
					$dir_text_control->add_validator( array( $this, 'mkdir_validator' ) );
					$form->append( 'submit', 'submit', _t('Submit') );
					$form->media_panel($fullpath, $panelname, 'habari.media.forceReload();');
					$form->on_success( array( $this, 'dir_success' ) );
					$panel = $form->get(); /* form submission magicallly happens here */

					return $panel;

					break;
				case 'rmdir':
					$fullpath = self::SILO_NAME . '/' . $path;

					$form = new FormUI( 'habarisilormdir' );
					$form->append( 'static', 'RmDirectory', '<div style="margin: 10px auto;">' . _t( 'Directory:' ) . " <strong>/{$path}</strong></div>" );

					// add the parent directory as a hidden input for later validation
					$form->append( 'hidden', 'path', 'null:unused' )->value = $path;
					$form->append( 'hidden', 'action', 'null:unused')->value = $panelname;
					$dir_text_control = $form->append( 'static', 'directory', _t( 'Are you sure you want to delete this directory?' ) );
					$form->append( 'submit', 'submit', _t('Delete') );
					$form->media_panel( $fullpath, $panelname, 'habari.media.forceReload();' );
					$form->on_success( array( $this, 'dir_success' ) );
					$panel = $form->get(); /* form submission magicallly happens here */

					return $panel;

					break;
				case 'delete':
					$fullpath = self::SILO_NAME . '/' . $path;

					$form = new FormUI( 'habarisilodelete' );
					$form->append( 'static', 'RmFile', '<div style="margin: 10px auto;">' . _t( 'File:' ) . " <strong>/{$path}</strong></div>" );

					// add the parent directory as a hidden input for later validation
					$form->append( 'hidden', 'path', 'null:unused' )->value = $path;
					$dir_text_control = $form->append( 'static', 'directory', '<p>' . _t( 'Are you sure you want to delete this file?' ) . '</p>');
					$form->append( 'submit', 'submit', _t('Delete') );
					$form->media_panel( $fullpath, $panelname, 'habari.media.forceReload();' );
					$form->on_success( array( $this, 'do_delete' ) );
					$panel = $form->get();

					return $panel;
					break;
				case 'upload':
					if ( isset( $_FILES['file'] ) ) {
						if ( isset( $_POST['token'] ) && isset( $_POST['token_ts'] ) && self::verify_token( $_POST['token'], $_POST['token_ts'] ) ) {
							$size = Utils::human_size( $_FILES['file']['size'] );
							$panel .= '<div class="span-18" style="padding-top:30px;color: #e0e0e0;margin: 0px auto;"><p>' . _t( 'File: ' ) . $_FILES['file']['name'];
							$panel .= ( $_FILES['file']['size'] > 0 ) ? "({$size})" : '';
							$panel .= '</p>';

							$path = self::SILO_NAME . '/' . preg_replace( '%\.{2,}%', '.', $path ). '/' . $_FILES['file']['name'];
							$asset = new MediaAsset( $path, false );
							$asset->upload( $_FILES['file'] );

							if ( $asset->put() ) {
								$panel .= '<p>' . _t( 'File added successfully.' ) . '</p>';
							}
							else {
								$upload_errors = array(
										1 => _t( 'The uploaded file exceeds the upload_max_filesize directive in php.ini.' ),
										2 => _t( 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.' ),
										3 => _t( 'The uploaded file was only partially uploaded.' ),
										4 => _t( 'No file was uploaded.' ),
										6 => _t( 'Missing a temporary folder.' ),
										7 => _t( 'Failed to write file to disk.' ),
										8 => _t( 'A PHP extension stopped the file upload. PHP does not provide a way to ascertain which extension caused the file upload to stop; examining the list of loaded extensions with phpinfo() may help.' ),
									);

								$panel .= '<p>' . _t( 'File could not be added to the silo.' ) . '</p>';
								$panel .= '<p><strong>' . $upload_errors[ $_FILES['file']['error'] ] . '</strong></p>';
							}

							$panel .= '<p><a href="#" onclick="habari.media.forceReload();habari.media.showdir(\'' . dirname( $path ) . '\');">' . _t( 'Browse the current silo path.' ) . '</a></p></div>';
						} else {
							$panel .= '<p><strong>' ._t( 'Suspicious behaviour or too much time has elapsed.  Please upload your file again.' ) . '</strong></p>';
						}
					}
					else {
						$token_ts = time();
						$token = self::create_token( $token_ts );
						$fullpath = self::SILO_NAME . '/' . $path;
						$form_action = URL::get( 'admin_ajax', array( 'context' => 'media_upload' ) );
						$panel .= <<< UPLOAD_FORM
<form enctype="multipart/form-data" method="post" id="simple_upload" target="simple_upload_frame" action="{$form_action}" class="span-10" style="margin:0px auto;text-align: center">
	<p style="padding-top:30px;">%s <b style="font-weight:normal;color: #e0e0e0;font-size: 1.2em;">/{$path}</b></p>
	<p><input type="file" name="file"><input type="submit" name="upload" value="%s">
	<input type="hidden" name="path" value="{$fullpath}">
	<input type="hidden" name="panel" value="{$panelname}">
	<input type="hidden" name="token" value="{$token}">
	<input type="hidden" name="token_ts" value="{$token_ts}">
	</p>
</form>
<iframe id="simple_upload_frame" name="simple_upload_frame" style="width:1px;height:1px;" onload="simple_uploaded();"></iframe>
<script type="text/javascript">
var responsedata;
function simple_uploaded() {
	if (!$('#simple_upload_frame')[0].contentWindow) return;
	var response = $($('#simple_upload_frame')[0].contentWindow.document.body).text();
	if (response) {
		eval('responsedata = ' + response);
		window.setTimeout(simple_uploaded_complete, 500);
	}
}
function simple_uploaded_complete() {
	habari.media.jsonpanel(responsedata.data);
}
</script>
UPLOAD_FORM;

					$panel = sprintf( $panel, _t( "Upload to:" ), _t( "Upload" ) );
				}
			}
		}
		return $panel;
	}

	/**
	 * A validator for the mkdir form created with FormUI. Checks to see if the
	 * webserver can write to the parent directory and that the directory does
	 * not already exist.
	 * @param $dir The input from the form
	 * @param $control The FormControl object
	 * @param $form The FormUI object
	 */
	public function mkdir_validator( $dir, $control, $form )
	{
		if ( strpos( $dir, '*' ) !== false || preg_match( '%(?:^|/)\.%', $dir ) ) {
		    return array( _t( "The directory name contains invalid characters: %s.", array( $dir ) ) );
		}

		$path = preg_replace( '%\.{2,}%', '.', $form->path->value );
		$dir = $this->root . ( $path == '' ? '' : '/' ) . $path . '/'. $dir;

		if ( is_dir( $dir ) ) {
			return array( _t( "Directory: %s already exists.", array( $dir ) ) );
		}

		return array();
	}
	/**
	 * This function performs the mkdir and rmdir actions on submission of the form.
	 * It is called by FormUI's success() method.
	 * @param FormUI $form
	 */
	public function dir_success ( $form )
	{
		$dir = preg_replace( '%\.{2,}%', '.', $form->directory->value );
		$path = preg_replace( '%\.{2,}%', '.', $form->path->value );

		switch ( $form->action->value ) {
			case 'rmdir':
				$dir = $this->root . ( $path == '' ? '' : '/' ) . $path;
				rmdir( $dir );
				$msg = _t( 'Directory Deleted: %s', array( $path ) );
				break;
			case 'mkdir':
				$dir = $path . '/'. $dir . '/.placeholder';

				$s3 = $this->gets3();
				$fh = tmpfile();  // make a blank file
				$finfo = stream_get_meta_data($fh);
				$s3->uploadFile($this->bucket, $dir, $finfo['uri'], true);
				fclose($fh);

				$msg = _t ( 'Directory Created: %s', array( $path . '/' . $form->directory->value ) );
				break;
		}

		return '<div class="span-18"style="padding-top:30px;color: #e0e0e0;margin: 0px auto;"><p>' . $msg . '</p></div>';
	}

	/**
	 * This function takes the path passed from the form and passes it to silo_delete
	 * to delete the file and it's thumbnail if it's an image.
	 *
	 * @param FormUI $form
	 */
	public function do_delete ( $form )
	{
		$path = preg_replace( '%\.{2,}%', '.', $form->path->value );
		$result = $this->silo_delete($path);
		$panel = '<div class="span-18" style="padding-top:30px;color: #e0e0e0;margin: 0px auto;">';
		if ( $result ) {
			$panel .= '<p>' . _t( 'File deleted successfully.' ) . '</p>';
		} else {
			$panel .= '<p>' . _t( 'Failed to delete file.' ) . '</p>';
		}

		$panel .= '<p><a href="#" onclick="habari.media.forceReload();habari.media.showdir(\'' . self::SILO_NAME . '/' . dirname( $path ) . '\');">' . _t( 'Browse the current silo path.' ) . '</a></p></div>';

		return $panel;
	}

	/**
	 * This function is used to check if a directory is empty.
	 *
	 * @param string $dir
	 * @return boolean
	 */
	private static function isEmptyDir( $dir )
	{
		return ( ( $files = @scandir( $dir ) ) && count( $files ) <= 2 );
	}

	/**
	 * Create the upload token based on the time string submitted and the UID for this Habari installation.
	 *
	 * @param integer $timestamp
	 * @return string
	 */
	private static function create_token( $timestamp )
	{
		return substr( md5( $timestamp . Options::get( 'GUID' ) ), 0, 10 );
	}

	/**
	 * Verify that the token and timestamp passed are valid.
	 *
	 * @param string $token
	 * @param integer $timestamp
	 *
	 * @TODO By default this gives the user 5 mins to upload a file from the time
	 *       the form is display and the file uploaded.  This should be sufficient,
	 *       but do we a) need this timeout and b) should it be configurable?
	 */
	private static function verify_token( $token, $timestamp )
	{
		if ( $token == self::create_token( $timestamp ) ) {
			if ( ( time() > ( $timestamp ) ) && ( time() < ( $timestamp + 5*60 ) ) ) {
				return true;
			}
		} else {
			return false;
		}
	}

}

?>
