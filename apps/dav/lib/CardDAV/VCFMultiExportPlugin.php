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
	function initialize(Server $server) {
		$this->server = $server;
		$this->server->on('method:POST', [$this, 'httpPOST'], 90);
	}

	/**
	 * Intercepts POST requests on addressbook urls ending with ?export.
	 *
	 * @param RequestInterface $request
	 * @param ResponseInterface $response
	 * @return bool
	 */
	function httpPOST(RequestInterface $request, ResponseInterface $response) {

		$queryParams = $request->getQueryParameters();
		$postData    = $request->getPostData();

		// check for ?export param
		// if (!array_key_exists('export', $queryParams)) {
		// 	return;
		// }

		// check for post data validity
		if (!array_key_exists('vcards', $postData) || !is_array($postData['vcards'])) {
			return;
		}

		// user addressbooks home
		$path = $request->getPath();

		// valid addressbooks and the requested vcards
		$addressbooks = [];
		foreach ($postData['vcards'] as $addressbook => $vcards) {
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

		$this->server->transactionType = 'get-vcards-multi-export';

		// array of vcard paths
		$paths = [];
		foreach ($addressbooks as $addressbook => $vcards) {
			// creating array of paths based on the vcard filenames
			$vcardspaths = array_map(function ($vcard) use ($addressbook, $path) {
				return "$path/$addressbook/$vcard";
			}, $vcards);
			$paths = array_merge($paths, $vcardspaths);
		}

		$nodes = $this->server->tree->getMultipleNodes($paths);

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
	function generateVCF(array $nodes) {

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
	function getPluginName() {

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
	function getPluginInfo() {

		return [
			'name'        => $this->getPluginName(),
			'description' => 'Adds the ability to export multiple vCard as a single vCard file.'
		];

	}

}
