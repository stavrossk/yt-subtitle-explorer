<?php
/**
 * YouTube Subtitle Explorer
 * 
 * @author  Jasper Palfree <jasper@wellcaffeinated.net>
 * @copyright 2012 Jasper Palfree
 * @license http://opensource.org/licenses/mit-license.php MIT License
 */

namespace YTSE\Captions;

use \Symfony\Component\HttpFoundation\File\UploadedFile;

class CaptionManager {

	private $base;
	private static $acceptedExts = 'txt sub srt sbv';
	private static $maxAcceptedSize = 1048576; // 1Mb
		
	/**
	 * Constructor
	 * @param string $base absolute base directory for storing caption files
	 */
	public function __construct($base){

		$this->base = $base;
	}

	/**
	 * Get path to caption file
	 * @param  string $videoId   the youtube video id
	 * @param  string $lang_code the language code
	 * @return string the absolute path
	 */
	public function getCaptionPath($videoId, $lang_code){

		return implode('/', array(
			$this->base,
			$videoId,
			$lang_code,
		));
	}

	/**
	 * Get contents of caption file
	 * @param  string $path relative path to caption file
	 * @return string|false
	 */
	public function getCaptionContents($path){

		$filename = $this->base . '/' . $path;

		if (!is_file($filename)) return false;

		try{

			return file_get_contents($filename);

		} catch (\Exception $e) {}

		return false;
	}

	/**
	 * Remove a caption file
	 * @param  string $path relative path to caption file
	 * @return boolean success value
	 */
	public function deleteCaption($path){

		$filename = $this->base . '/' . $path;

		if (!is_file($filename)) return false;

		unlink($filename);

		$dir = dirname($filename);
		@rmdir($dir); // remove if empty

		return true;
	}

	/**
	 * Get caption submission list
	 * @return array submission data
	 */
	public function getSubmissions(){

		return $this->generateIndex();
	}

	/**
	 * Generate index of caption submissions
	 * @return array submission data
	 */
	protected function generateIndex(){

		$ret = array();

		if (!is_dir($this->base)) {

			return $ret;
		}

		$d = dir($this->base);

		while (false !== ($entry = $d->read())) {

			if (preg_match('/^\./', $entry)) continue; // begins with .

			$vid = array(
				'videoId' => $entry,
				'captions' => $this->generateCaptionsForVideo($entry),
			);

			if (!empty($vid['captions'])){

				$ret[] = $vid;
			}
		}

		$d->close();

		return $ret;
	}

	/**
	 * Get caption record for specific video
	 * @param  string $videoId youtube id for video
	 * @return array caption data
	 */
	protected function generateCaptionsForVideo($videoId){

		$dirname = $this->base . '/' . $videoId;
		$langs = array();

		if (!is_dir($dirname)) {
			return $langs;
		}

		$d = dir($dirname);

		while (false !== ($lang_code = $d->read())) {

			if (preg_match('/^\./', $lang_code)) continue; // begins with .

			$caps = $this->generateCaptionsForVideoAndLang($videoId, $lang_code);

			if (!empty($caps)){

				$langs[ $lang_code ] = $caps;
			}
		}

		$d->close();

		return $langs;
	}

	/**
	 * Get caption info based on caption path
	 * @param  string $path relative path to caption file
	 * @return array caption info
	 */
	public function extractCaptionInfo($path){

		$path = preg_replace('/^\//', '', $path); // remove leading slash
		$dirs = explode('/', $path);

		if (count($dirs) !== 3) return false;

		$filename = $dirs[2];
		$count = preg_match('/^([^%]+)%([0-9]+)\.(\w+)$/', $filename, $matches);

		if (!$count) return false;

		return array(
			'videoId' => $dirs[0],
			'lang_code' => $dirs[1],
			'path' => $path,
			'filename' => $filename,
			'user' => $matches[1],
			'timestamp' => $matches[2],
			'ext' => $matches[3],
		);
	}

	/**
	 * Get caption submission data for specific video and language
	 * @param  string $videoId   the youtube id for the video
	 * @param  string $lang_code the language code of language
	 * @return array caption data
	 */
	protected function generateCaptionsForVideoAndLang($videoId, $lang_code){

		$dirname = $this->base . '/' . $videoId . '/' . $lang_code;
		$caps = array();

		if (!is_dir($dirname)) {
			return $caps;
		}

		$d = dir($dirname);

		while (false !== ($entry = $d->read())) {

			if (preg_match('/^\./', $entry)) continue; // begins with .

			$info = $this->extractCaptionInfo(str_replace($this->base, '', $dirname) . '/' . $entry);

			if (!$info) continue;

			$caps[] = $info;
		}

		$d->close();

		return $caps;
	}

	/**
	 * Save a caption submission from a form upload
	 * @param  UploadedFile $file      the uploaded file
	 * @param  string       $videoId   the youtube id of the video
	 * @param  string       $lang_code the language code of the submission
	 * @param  string       $username  the username of user who submitted it
	 * @return void
	 */
	public function saveCaption(UploadedFile $file, $videoId, $lang_code, $username){

		preg_match('/\.([a-zA-Z]*)$/', $file->getClientOriginalName(), $matches);
		$format = isset($matches[1])? $matches[1] : 'txt';
		
		$dir = $this->getCaptionPath($videoId, $lang_code);
		$name = $this->getNewCaptionFilename($username, $format);

		if ( !$this->isSafeExtension($format) ){

			// if someone tries to upload a .php file, for example... stop them.
			throw new InvalidFileFormatException("Invalid File Format");
			return;
		}

		if ( $file->getSize() > CaptionManager::$maxAcceptedSize ){

			throw new \Exception("File too big.");
			return;
		}

		if (!is_dir($dir)){

			mkdir($dir, 0777, true);
		}

		$file->move($dir, $name);
	}

	/**
	 * Determine if the extension of caption submission is acceptable
	 * @param  string  $format extension to check
	 * @return boolean true if safe/accepted extension
	 */
	protected function isSafeExtension($format){

		return in_array($format, explode(' ', CaptionManager::$acceptedExts));
	}

	/**
	 * Generate a caption filename
	 * @param  string $username username of submitter
	 * @param  string $format   the format of the caption
	 * @return string the filename
	 */
	protected function getNewCaptionFilename($username, $format){

		$ts = time();

		return "{$username}%{$ts}.{$format}";
	}
}