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

namespace OCA\DAV\Tests\unit\CardDAV;

use OCA\DAV\CardDAV\AddressBook;
use OCA\DAV\CardDAV\VCFMultiExportPlugin;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use Sabre\CardDAV\Card;
use Sabre\DAV\Node;
use Sabre\DAV\Server;
use Sabre\DAV\Tree;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use Test\TestCase;

class VCFMultiExportPluginTest extends TestCase {

	/** @var ResponseInterface|\PHPUnit_Framework_MockObject_MockObject */
	private $response;
	/** @var RequestInterface|\PHPUnit_Framework_MockObject_MockObject */
	private $request;
	/** @var VCFMultiExportPlugin|\PHPUnit_Framework_MockObject_MockObject */
	private $plugin;
	/** @var Server */
	private $server;
	/** @var Tree|\PHPUnit_Framework_MockObject_MockObject */
	private $tree;

	function setUp() {
		parent::setUp();

		$this->request = $this->createMock(RequestInterface::class);
		$this->response = $this->createMock(ResponseInterface::class);
		$this->server = $this->createMock(Server::class);
		$this->tree = $this->createMock(Tree::class);
		$this->server->tree = $this->tree;

		$this->plugin = $this->createMock(VCFMultiExportPlugin::class);
		$this->plugin->initialize($this->server);
	}

	/**
	 * @dataProvider providesQueryParams
	 * @param $param
	 */
	public function testQueryParams($param) {
		$this->request->expects($this->once())->method('getQueryParameters')->willReturn($param);
		$result = $this->plugin->httpGet($this->request, $this->response);
		$this->assertTrue($result);
	}

	public function providesQueryParams() {
		return [
			[[]],
			[['1']],
			[['foo' => 'bar']]
		];
	}
}
