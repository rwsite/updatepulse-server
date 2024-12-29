<?php

class WshWordPressPackageParser_Extended extends WshWordPressPackageParser {
	/**
	 * @uses WshWordPressPackageParser_Extended::getExtraHeaders
	 * @see WshWordPressPackageParser
	 */
	public static function parsePackage($packageFilename, $applyMarkdown = false){

		if (!file_exists($packageFilename) || !is_readable($packageFilename)){
			return false;
		}

		//Open the .zip
		$zip = WshWpp_Archive::open($packageFilename);

		if ($zip === false){
			return false;
		}

		//Find and parse the plugin or theme file and (optionally) readme.txt.
		$header = null;
		$readme = null;
		$pluginFile = null;
		$stylesheet = null;
		$genericFile = null;
		$type = null;
		$extra = null;
		$entries = $zip->listEntries();
		$slug = str_replace('.zip', '', basename($packageFilename));

		for ($fileIndex = 0; ($fileIndex < count($entries)) && (empty($readme) || empty($header)); $fileIndex++){
			$info = $entries[$fileIndex];

			//Normalize filename: convert backslashes to slashes, remove leading slashes.
			$fileName = trim(str_replace('\\', '/', $info['name']), '/');
			$fileName = ltrim($fileName, '/');

			$fileNameParts = explode('.', $fileName);
			$extension = strtolower(end($fileNameParts));
			$depth = substr_count($fileName, '/');

			//Skip empty files, directories and everything that's more than 1 sub-directory deep.
			if (($depth > 1) || $info['isFolder']) {
				continue;
			}

			//readme.txt (for plugins)?
			if (empty($readme) && (strtolower(basename($fileName)) === 'readme.txt')){
				//Try to parse the readme.
				$readme = self::parseReadme($zip->getFileContents($info), $applyMarkdown);
			}

			$fileContents = null;

			//Theme stylesheet?
			if (empty($header) && (strtolower(basename($fileName)) === 'style.css')) {
				$fileContents = substr($zip->getFileContents($info), 0, 8*1024);
				$header = self::getThemeHeaders($fileContents);

				if (!empty($header)){
					$stylesheet = $fileName;
					$type = 'theme';
				}
			}

			//Main plugin file?
			if (empty($header) && ($extension === 'php')){
				$fileContents = substr($zip->getFileContents($info), 0, 8*1024);
				$pluginFile = $fileName;
				$header = self::getPluginHeaders($fileContents);
				$type = 'plugin';
			}

			//Generic info file?
			if (empty($header) && ($extension === 'json') && (basename($fileName) === 'wppus.json')){
				$fileContents = substr($zip->getFileContents($info), 0, 8*1024);
				$header = self::getGenericHeaders($fileContents);
				$genericFile = $fileName;
				$type = 'generic';
			}

			if (!empty($header) && $fileContents){
				$extra = 'generic' === $type ?
					self::getGenericExtraHeaders($fileContents) :
					self::getExtraHeaders($fileContents);
				error_log(print_r( $extra, true ));
			}
		}

		if (empty($type)){
			return false;
		} else {
			return compact('header', 'extra', 'readme', 'pluginFile', 'stylesheet', 'genericFile', 'type');
		}
	}

	/**
	 * Parse the generic package's headers from wppus.json file.
	 * Returns an array that may contain the following:
	 * 'Name'
	 * 'Version'
	 * 'Homepage'
	 * 'Author'
	 * 'AuthorURI'
	 * 'Description'
	 * @param string $fileContents Contents of the package file
	 * @return array See above for description.
	 */
	public static function getGenericHeaders($fileContents) {
		$decodedContents = json_decode($fileContents, true);
		$genericHeaders = array();

		if (isset($decodedContents['packageData']) && !empty($decodedContents['packageData'])) {
			$packageData = $decodedContents['packageData'];
			$validKeys = array(
				'Name',
				'Version',
				'Homepage',
				'Author',
				'AuthorURI',
				'Description',
			);

			foreach ($validKeys as $key) {
				if (!empty($packageData[$key])) {
					$genericHeaders[$key] = $packageData[$key];
				}
			}
		}

		return $genericHeaders;
	}

	/**
	 * Parse the generic package's extra headers from wppus.json file.
	 * Returns an array that may contain the following:
	 * 'Icon1x'
	 * 'Icon2x'
	 * 'BannerHigh'
	 * 'BannerLow'
	 * 'RequireLicense'
	 * 'LicensedWith'
	 * @param string $fileContents Contents of the package file
	 * @return array See above for description.
	 */
	public static function getGenericExtraHeaders($fileContents) {
		$decodedContents = json_decode($fileContents, true);
		$genericExtra = array();

		if (isset($decodedContents['packageData']) && !empty($decodedContents['packageData'])) {
			$packageData = $decodedContents['packageData'];
			$extraHeaderNames = array(
				'Icon1x' => 'Icon1x',
				'Icon2x' => 'Icon2x',
				'BannerHigh' => 'BannerHigh',
				'BannerLow' => 'BannerLow',
				'RequireLicense' => 'require_license',
				'LicensedWith' => 'licensed_with',
			);

			foreach ($extraHeaderNames as $name => $key) {
				if (!empty($packageData[$name])) {
					if (0 === strpos($name, 'Banner')) {
						$genericExtra['banners'] = is_array($genericExtra['banners']) ? $genericExtra['banners'] : array();
						$idx = strtolower(str_replace('Banner', '', $name));
						$genericExtra['banners'][$idx] = $packageData[$name];
					} elseif (0 === strpos($name, 'Icon')) {
						$genericExtra['icons'] = is_array($genericExtra['icons']) ? $genericExtra['icons'] : array();
						$idx = str_replace('Icon', '', $name);
						$genericExtra['icons'][$idx] = $packageData[$name];
					} else {
						$genericExtra[$key] = $packageData[$name];
					}
				}
			}
		}

		return $genericExtra;
	}


	/**
	 * Parse the package contents to retrieve icons and banners information.
	 *
	 * Adapted from @see WshWordPressPackageParser::getPluginHeaders.
	 * Returns an array that may contain the following:
	 * 'icons':
	 *		'Icon1x'
	 *		'Icon2x'
	 * 'banners':
	 *		'BannerHigh'
	 *		'BannerLow'
	 * 'Require License'
	 * 'Licensed With'
	 *
	 * If the data is not found, the function
	 * will return NULL.
	 *
	 * @param string $fileContents Contents of the package file
	 * @return array|null See above for description.
	 */
	public static function getExtraHeaders($fileContents) {
		//[Internal name => Name used in the package file]
		$extraHeaderNames = array(
			'Icon1x' => 'Icon1x',
			'Icon2x' => 'Icon2x',
			'BannerHigh' => 'BannerHigh',
			'BannerLow' => 'BannerLow',
			'RequireLicense' => 'Require License',
			'LicensedWith' => 'Licensed With',
		);

		$headers = self::getFileHeaders($fileContents, $extraHeaderNames);
		$extraHeaders = array();

		if (!empty($headers['RequireLicense'])) {
			$extraHeaders['require_license'] = $headers['RequireLicense'];
		}

		if (!empty($headers['LicensedWith'])) {
			$extraHeaders['licensed_with'] = $headers['LicensedWith'];
		}

		if (!empty($headers['Icon1x']) || !empty($headers['Icon2x'])) {
			$extraHeaders['icons'] = array();

			if (!empty($headers['Icon1x'])) {
				$extraHeaders['icons']['1x'] = $headers['Icon1x'];
			}

			if (!empty($headers['Icon2x'])) {
				$extraHeaders['icons']['2x'] = $headers['Icon2x'];
			}
		}

		if (!empty($headers['BannerLow']) || !empty($headers['BannerHigh'])) {
			$extraHeaders['banners'] = array();

			if (!empty($headers['BannerLow'])) {
				$extraHeaders['banners']['low'] = $headers['BannerLow'];
			}

			if (!empty($headers['BannerHigh'])) {
				$extraHeaders['banners']['high'] = $headers['BannerHigh'];
			}
		}

		if (empty($extraHeaders)){
			return null;
		} else {
			return $extraHeaders;
		}
	}
}
