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
namespace OCA\DAV\BackgroundJob;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use OC\BackgroundJob\Job;
use OCA\DAV\CalDAV\CalDavBackend;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\ILogger;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\PropPatch;
use Sabre\DAV\Xml\Property\Href;
use Sabre\VObject\Component;
use Sabre\VObject\DateTimeParser;
use Sabre\VObject\InvalidDataException;
use Sabre\VObject\ParseException;
use Sabre\VObject\Reader;
use Sabre\VObject\Splitter\ICalendar;

class RefreshWebcalJob extends Job {

	/** @var CalDavBackend */
	private $calDavBackend;

	/** @var IClientService */
	private $clientService;

	/** @var ILogger */
	private $logger;

	/** @var ITimeFactory */
	private $timeFactory;

	/** @var array */
	private $subscription;

	/**
	 * RefreshWebcalJob constructor.
	 *
	 * @param CalDavBackend $calDavBackend
	 * @param IClientService $clientService
	 * @param ILogger $logger
	 * @param ITimeFactory $timeFactory
	 */
	public function __construct(CalDavBackend $calDavBackend, IClientService $clientService, ILogger $logger, ITimeFactory $timeFactory) {
		$this->calDavBackend = $calDavBackend;
		$this->clientService = $clientService;
		$this->logger = $logger;
		$this->timeFactory = $timeFactory;
	}

	/**
	 * this function is called at most every hour
	 *
	 * @inheritdoc
	 */
	public function execute($jobList, ILogger $logger = null) {
		$subscription = $this->getSubscription($this->argument['principaluri'], $this->argument['uri']);
		if (!$subscription) {
			return;
		}

		// if no refresh rate was configured, just refresh once a week
		$subscriptionId = $subscription['id'];
		$refreshrate = $subscription['refreshrate'] ?? 'P1W';

		try {
			/** @var \DateInterval $dateInterval */
			$dateInterval = DateTimeParser::parseDuration($refreshrate);
		} catch(InvalidDataException $ex) {
			$this->logger->logException($ex);
			$this->logger->warning("Subscription $subscriptionId could not be refreshed, refreshrate in database is invalid");
			return;
		}

		$interval = $this->getIntervalFromDateInterval($dateInterval);
		if (($this->timeFactory->getTime() - $this->lastRun) <= $interval) {
			return;
		}

		parent::execute($jobList, $logger);
	}

	/**
	 * @param array $argument
	 */
	protected function run($argument) {
		$subscription = $this->getSubscription($argument['principaluri'], $argument['uri']);
		$mutations = [];
		if (!$subscription) {
			return;
		}

		$webcalData = $this->queryWebcalFeed($subscription, $mutations);
		if (!$webcalData) {
			return;
		}

		$stripTodos = $subscription['striptodos'] ?? true;
		$stripAlarms = $subscription['stripalarms'] ?? true;
		$stripAttachments = $subscription['stripattachments'] ?? true;

		try {
			$splitter = new ICalendar($webcalData, Reader::OPTION_FORGIVING);

			// we wait with deleting all outdated events till we parsed the new ones
			// in case the new calendar is broken and `new ICalendar` throws a ParseException
			// the user will still see the old data
			$this->calDavBackend->purgeAllCachedEventsForSubscription($subscription['id']);

			while ($vObject = $splitter->getNext()) {
				/** @var Component $vObject */
				$uid = null;
				$compName = null;

				foreach ($vObject->getComponents() as $component) {
					if ($component->name === 'VTIMEZONE') {
						continue;
					}

					$uid = $component->{'UID'}->getValue();
					$compName = $component->name;

					if ($stripAlarms) {
						unset($component->{'VALARM'});
					}
					if ($stripAttachments) {
						unset($component->{'ATTACH'});
					}
				}

				if ($stripTodos && $compName === 'VTODO') {
					continue;
				}

				$uri = $uid . '.ics';
				$calendarData = $vObject->serialize();
				try {
					$this->calDavBackend->addCachedEvent($subscription['id'], $uri, $calendarData);
				} catch(BadRequest $ex) {
					$this->logger->logException($ex);
				}
			}

			$newRefreshRate = $this->checkWebcalDataForRefreshRate($subscription, $webcalData);
			if ($newRefreshRate) {
				$mutations['{http://apple.com/ns/ical/}refreshrate'] = $newRefreshRate;
			}

			$this->updateSubscription($subscription, $mutations);
		} catch(ParseException $ex) {
			$subscriptionId = $subscription['id'];

			$this->logger->logException($ex);
			$this->logger->warning("Subscription $subscriptionId could not be refreshed due to a parsing error");
		}
	}

	/**
	 * gets webcal feed from remote server
	 *
	 * @param array $subscription
	 * @param array &$mutations
	 * @return null|string
	 */
	private function queryWebcalFeed(array $subscription, array &$mutations) {
		$client = $this->clientService->newClient();

		$didBreak301Chain = false;
		$latestLocation = null;

		$handlerStack = HandlerStack::create();
		$handlerStack->push(Middleware::mapRequest(function (RequestInterface $request) {
			return $request
				->withHeader('User-Agent', 'Nextcloud Webcal Crawler');
		}));
		$handlerStack->push(Middleware::mapResponse(function(ResponseInterface $response) use (&$didBreak301Chain, &$latestLocation) {
			if (!$didBreak301Chain) {
				if ($response->getStatusCode() !== 301) {
					$didBreak301Chain = true;
				} else {
					$latestLocation = $response->getHeader('Location');
				}
			}
			return $response;
		}));

		try {
			$response = $client->get($subscription['source'], [
				'allow_redirects' => [
					'redirects' => 10
				],
				'handler' => $handlerStack,
			]);

			$body = $response->getBody();

			if ($latestLocation) {
				$mutations['{http://calendarserver.org/ns/}source'] = new Href($latestLocation);
			}

			return $body;
		} catch(\Exception $ex) {
			$subscriptionId = $subscription['id'];

			$this->logger->logException($ex);
			$this->logger->warning("Subscription $subscriptionId could not be refreshed due to a network error");

			return null;
		}
	}

	/**
	 * loads subscription from backend and store it locally
	 *
	 * @param string $principalUri
	 * @param string $uri
	 * @return array|null
	 */
	private function getSubscription($principalUri, $uri) {
		if ($this->subscription) {
			return $this->subscription;
		}

		$subscriptions = array_filter(
			$this->calDavBackend->getSubscriptionsForUser($principalUri),
			function($sub) use ($uri) {
				return $sub['uri'] === $uri;
			}
		);

		if (\count($subscriptions) === 0) {
			return null;
		}

		$this->subscription = $subscriptions[0];
		return $this->subscription;
	}

	/**
	 * get total number of seconds from DateInterval object
	 *
	 * @param \DateInterval $interval
	 * @return int
	 */
	private function getIntervalFromDateInterval(\DateInterval $interval):int {
		return $interval->s
			+ ($interval->i * 60)
			+ ($interval->h * 60 * 60)
			+ ($interval->d * 60 * 60 * 24)
			+ ($interval->m * 60 * 60 * 24 * 30)
			+ ($interval->y * 60 * 60 * 24 * 365);
	}

	/**
	 * check if:
	 *  - current subscription stores a refreshrate
	 *  - the webcal feed suggests a refreshrate
	 *  - return suggested refreshrate if user didn't set a custom one
	 *
	 * @param array $subscription
	 * @param string $webcalData
	 * @return string|null
	 */
	private function checkWebcalDataForRefreshRate($subscription, $webcalData) {
		// if there is no refreshrate stored in the database, check the webcal feed
		// whether it suggests any refresh rate and store that in the database
		if (isset($subscription['refreshrate']) && $subscription['refreshrate'] !== null) {
			return null;
		}

		/** @var Component\VCalendar $vCalendar */
		$vCalendar = Reader::read($webcalData);

		$newRefreshrate = null;
		if (isset($vCalendar->{'X-PUBLISHED-TTL'})) {
			$newRefreshrate = $vCalendar->{'X-PUBLISHED-TTL'}->getValue();
		}
		if (isset($vCalendar->{'REFRESH-INTERVAL'})) {
			$newRefreshrate = $vCalendar->{'REFRESH-INTERVAL'}->getValue();
		}

		if (!$newRefreshrate) {
			return null;
		}

		// check if new refresh rate is even valid
		try {
			DateTimeParser::parseDuration($newRefreshrate);
		} catch(InvalidDataException $ex) {
			return null;
		}

		return $newRefreshrate;
	}

	/**
	 * update subscription stored in database
	 * used to set:
	 *  - refreshrate
	 *  - source
	 *
	 * @param array $subscription
	 * @param array $mutations
	 */
	private function updateSubscription(array $subscription, array $mutations) {
		if (empty($mutations)) {
			return;
		}

		$propPatch = new PropPatch($mutations);
		$this->calDavBackend->updateSubscription($subscription['id'], $propPatch);
		$propPatch->commit();
	}
}
