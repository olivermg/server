<?php
declare(strict_types=1);
/**
 * @copyright 2018 Georg Ehrke <oc.list@georgehrke.com>
 *
 * @author Georg Ehrke <oc.list@georgehrke.com>
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
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace OCA\DAV\CalDAV;

use Sabre\CalDAV\Backend\BackendInterface;
use Sabre\DAV\Exception\MethodNotAllowed;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\PropPatch;

/**
 * Class CachedSubscription
 *
 * @package OCA\DAV\CalDAV
 * @property BackendInterface|CalDavBackend $caldavBackend
 */
class CachedSubscription extends \Sabre\CalDAV\Calendar {

	/**
	 * @return string
	 */
	public function getPrincipalURI():string {
		return $this->calendarInfo['principaluri'];
	}

	/**
	 * @return array
	 */
	public function getACL():array {
		return [
			[
				'privilege' => '{DAV:}read',
				'principal' => $this->getOwner(),
				'protected' => true,
			]
		];
	}

	/**
	 * @return array
	 */
	public function getChildACL():array {
		return $this->getACL();
	}

	/**
	 * @return null|string
	 */
	public function getOwner() {
		if (isset($this->calendarInfo['{http://owncloud.org/ns}owner-principal'])) {
			return $this->calendarInfo['{http://owncloud.org/ns}owner-principal'];
		}
		return parent::getOwner();
	}

	/**
	 *
	 */
	public function delete() {
		$this->caldavBackend->deleteSubscription($this->calendarInfo['id']);
	}

	/**
	 * @param PropPatch $propPatch
	 */
	public function propPatch(PropPatch $propPatch) {
		$this->caldavBackend->updateSubscription($this->calendarInfo['id'], $propPatch);
	}

	/**
	 * @param string $name
	 * @return CalendarObject|\Sabre\CalDAV\ICalendarObject
	 * @throws NotFound
	 */
	public function getChild($name) {
		$obj = $this->caldavBackend->getCachedCalendarObject($this->calendarInfo['id'], $name);
		if (!$obj) {
			throw new NotFound('Calendar object not found');
		}

		$obj['acl'] = $this->getChildACL();
		return new CalendarObject($this->caldavBackend, $this->calendarInfo, $obj);

	}

	/**
	 * @return array
	 */
	public function getChildren():array {
		$objs = $this->caldavBackend->getCachedCalendarObjects($this->calendarInfo['id']);

		$children = [];
		foreach($objs as $obj) {
			$children[] = new CachedSubscriptionObject($this->caldavBackend, $this->calendarInfo, $obj);
		}

		return $children;
	}

	/**
	 * @param array $paths
	 * @return array
	 */
	public function getMultipleChildren(array $paths):array {
		$objs = $this->caldavBackend->getMultipleCachedCalendarObjects($this->calendarInfo['id'], $paths);

		$children = [];
		foreach($objs as $obj) {
			$children[] = new CachedSubscriptionObject($this->caldavBackend, $this->calendarInfo, $obj);
		}

		return $children;
	}

	/**
	 * @param string $name
	 * @param null $calendarData
	 * @return null|string|void
	 * @throws MethodNotAllowed
	 */
	public function createFile($name, $calendarData = null) {
		throw new MethodNotAllowed('Creating objects in cached subscription is not allowed');
	}

	/**
	 * @param string $name
	 * @return bool
	 */
	public function childExists($name):bool {
		$obj = $this->caldavBackend->getCachedCalendarObject($this->calendarInfo['id'], $name);
		if (!$obj) {
			return false;
		}

		return true;
	}

	/**
	 * @param array $filters
	 * @return array
	 */
	public function calendarQuery(array $filters):array {
		return $this->caldavBackend->cachedCalendarQuery($this->calendarInfo['id'], $filters);
	}

	/**
	 * CachedSubscriptions don't support sync-tokens for now
	 * Clients will have to do a multi get etag for now
	 *
	 * @return null
	 */
	public function getSyncToken() {
		return null;
	}
}
