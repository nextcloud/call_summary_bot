<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2023 Joas Schilling <coding@schilljs.com>
 *
 * @author Joas Schilling <coding@schilljs.com>
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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\CallSummaryBot\Model;

use OCP\AppFramework\Db\Entity;

/**
 * @method void setServer(string $server)
 * @method string getServer()
 * @method void setToken(string $token)
 * @method string getToken()
 * @method void setType(string $type)
 * @method string getType()
 * @method void setDetails(?string $details)
 * @method string|null getDetails()
 */
class LogEntry extends Entity {
	public const TYPE_ATTENDEE = 'attendee';
	public const TYPE_START = 'start';
	public const TYPE_TODO = 'todo';

	/** @var string */
	protected $server;

	/** @var string */
	protected $token;

	/** @var string */
	protected $type;

	/** @var ?string */
	protected $details;

	public function __construct() {
		$this->addType('server', 'string');
		$this->addType('token', 'string');
		$this->addType('type', 'string');
		$this->addType('details', 'string');
	}
}
