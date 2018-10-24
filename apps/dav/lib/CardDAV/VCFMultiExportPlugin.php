<?php
declare (strict_types = 1);
/**
 * @copyright Copyright (c) 2018 John Molakvoæ <skjnldsv@protonmail.com>
 *
 * @author John Molakvoæ <skjnldsv@protonmail.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\DAV\CardDAV;

use OCA\DAV\CardDAV\AddressBook;
use Sabre\DAV;
use Sabre\DAV\Server;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use Sabre\VObject;

class VCFMultiExportPlugin extends DAV\ServerPlugin {

	/** @var Server */
	protected $server;

	/**
	 * Initializes the plugin and registers event handlers
	 *
	 * @param Server $server
	 * @return void
	 */
	public function initialize(Server $server) {
		$this->server = $server;
		$this->server->on('method:GET', [$this, 'httpGet'], 90);
	}

	/**
	 * Intercepts GET requests
	 *
	 * @param RequestInterface $request
	 * @param ResponseInterface $response
	 * @return bool
	 */
	public function httpGet(RequestInterface $request, ResponseInterface $response) {

		$queryParams = $request->getQueryParameters();

		var_dump($queryParams);

		// check for post data validity
		if (!array_key_exists('vcards', $queryParams)
		 || !is_array($queryParams['vcards'])) {
			return true;
		}

		// user addressbooks home
		$path = $request->getPath();
		$paths = $queryParams['vcards'];

		// extract unique addressbooks from all the paths
		$addressbookIDs = array_unique(array_map(function($path) {
			return explode('/', $path)[0];
		}, $paths));

		// valid addressbooks and the requested vcards
		$addressbooks = [];
		foreach ($addressbookIDs as $addressbook) {
			$node = $this->server->tree->getNodeForPath("$path/$addressbook/");

			// Checking ACL, if available.
			if ($aclPlugin = $this->server->getPlugin('acl')) {
				$aclPlugin->checkPrivileges("$path/$addressbook/", '{DAV:}read');
			}

			// checking that the path is a valid addressbook
			if ($node instanceof AddressBook) {
				$addressbooks = array_merge($addressbooks, [$addressbook => $vcards]);
			}
		}

		// array of vcard paths
		$absolutePaths = array_map(function ($vcard) use ($path) {
			return "$path/$vcard";
		}, $paths);

		$this->server->transactionType = 'vcf-multi-export';

		// We do not need to check for other paths
		// If one of the path is incorrect, it either
		// 1. would have been thrown if the ab doesn't exists
		// 2. will be ignored by the getMultipleNodes method
		$nodes = $this->server->tree->getMultipleNodes($absolutePaths);

		$format = 'text/directory';

		$output            = null;
		$filenameExtension = null;

		switch ($format) {
			case 'text/directory':
				$output            = $this->generateVCF($nodes);
				$filenameExtension = '.vcf';
				break;
		}

		$filename = preg_replace(
			'/[^a-zA-Z0-9-_ ]/um',
			'',
			$node->getName()
		);
		$filename .= '-' . date('Y-m-d') . $filenameExtension;

		$response->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
		$response->setHeader('Content-Type', $format);

		$response->setStatus(200);
		$response->setBody($output);

		// Returning false to break the event chain
		return false;
	}

	/**
	 * Merges all vcard objects, and builds one big vcf export
	 *
	 * @param array $nodes
	 * @return string
	 */
	private function generateVCF(array $nodes) {
		$output = '';

		foreach ($nodes as $node) {

			$nodeData = $node->get();

			// Parsing this node so VObject can clean up the output.
			$vcard = VObject\Reader::read($nodeData);
			$output .= $vcard->serialize();

			// Destroy circular references to PHP will GC the object.
			$vcard->destroy();

		}

		return $output;
	}

	/**
	 * Returns a plugin name.
	 *
	 * Using this name other plugins will be able to access other plugins
	 * using \Sabre\DAV\Server::getPlugin
	 *
	 * @return string
	 */
	public function getPluginName() {
		return 'vcf-multi-export';
	}

	/**
	 * Returns a bunch of meta-data about the plugin.
	 *
	 * Providing this information is optional, and is mainly displayed by the
	 * Browser plugin.
	 *
	 * The description key in the returned array may contain html and will not
	 * be sanitized.
	 *
	 * @return array
	 */
	public function getPluginInfo() {
		return [
			'name'        => $this->getPluginName(),
			'description' => 'Adds the ability to export multiple vCard as a single vCard file.'
		];

	}

}
