<?php
/**
 * @author Björn Schießle <schiessle@owncloud.com>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
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
use OCA\DAV\Command\CreateAddressBook;
use OCA\DAV\Command\CreateCalendar;
use OCA\DAV\Command\SyncSystemAddressBook;

$config = \OC::$server->getConfig();
$dbConnection = \OC::$server->getDatabaseConnection();
$userManager = OC::$server->getUserManager();
$config = \OC::$server->getConfig();
$logger = \OC::$server->getLogger();

/** @var Symfony\Component\Console\Application $application */
$application->add(new CreateAddressBook($userManager, $dbConnection, $config, $logger));
$application->add(new CreateCalendar($userManager, $dbConnection));
$application->add(new SyncSystemAddressBook($userManager, $dbConnection, $config));
