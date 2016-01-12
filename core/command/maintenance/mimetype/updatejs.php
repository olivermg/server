<?php
/**
 * @author Robin McCorkell <robin@mccorkell.me.uk>
 * @author Roeland Jago Douma <rullzer@owncloud.com>
 *
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OC\Core\Command\Maintenance\Mimetype;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use OCP\Files\IMimeTypeDetector;

class UpdateJS extends Command {

	/** @var IMimeTypeDetector */
	protected $mimetypeDetector;

	public function __construct(
		IMimeTypeDetector $mimetypeDetector
	) {
		parent::__construct();
		$this->mimetypeDetector = $mimetypeDetector;
	}

	protected function configure() {
		$this
			->setName('maintenance:mimetype:update-js')
			->setDescription('Update mimetypelist.js');
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		// Fetch all the aliases
		$aliases = $this->mimetypeDetector->getAllAliases();

		// Remove comments
		$keys = array_filter(array_keys($aliases), function($k) {
			return $k[0] === '_';
		});
		foreach($keys as $key) {
			unset($aliases[$key]);
		}

		// Fetch all files
		$dir = new \DirectoryIterator(\OC::$SERVERROOT.'/core/img/filetypes');

		$files = [];
		foreach($dir as $fileInfo) {
			if ($fileInfo->isFile()) {
				$file = preg_replace('/.[^.]*$/', '', $fileInfo->getFilename());
				$files[] = $file;
			}
		}

		//Remove duplicates
		$files = array_values(array_unique($files));

		// Fetch all themes!
		$themes = [];
		$dirs = new \DirectoryIterator(\OC::$SERVERROOT.'/themes/');
		foreach($dirs as $dir) {
			//Valid theme dir
			if ($dir->isFile() || $dir->isDot()) {
				continue;
			}

			$theme = $dir->getFilename();
			$themeDir = $dir->getPath() . '/' . $theme . '/core/img/filetypes/';
			// Check if this theme has its own filetype icons
			if (!file_exists($themeDir)) {
				continue;
			}

			$themes[$theme] = [];
			// Fetch all the theme icons!
			$themeIt = new \DirectoryIterator($themeDir);
			foreach ($themeIt as $fileInfo) {
				if ($fileInfo->isFile()) {
					$file = preg_replace('/.[^.]*$/', '', $fileInfo->getFilename());
					$themes[$theme][] = $file;
				}
			}

			//Remove Duplicates
			$themes[$theme] = array_values(array_unique($themes[$theme]));
		}

		//Generate the JS
		$js = '/**
* This file is automatically generated
* DO NOT EDIT MANUALLY!
*
* You can update the list of MimeType Aliases in config/mimetypealiases.json
* The list of files is fetched from core/img/filetypes
* To regenerate this file run ./occ maintenance:mimetypesjs
*/
OC.MimeTypeList={
	aliases: ' . json_encode($aliases, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . ',
	files: ' . json_encode($files, JSON_PRETTY_PRINT) . ',
	themes: ' . json_encode($themes, JSON_PRETTY_PRINT) . '
};
';

		//Output the JS
		file_put_contents(\OC::$SERVERROOT.'/core/js/mimetypelist.js', $js);

		$output->writeln('<info>mimetypelist.js is updated');
	}
}
