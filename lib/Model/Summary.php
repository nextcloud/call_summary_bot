<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\CallSummaryBot\Model;

use OCP\AppFramework\Db\Entity;

/**
 * @method void setToken(string $token)
 * @method string getToken()
 * @method void setStartTime(int $startTime)
 * @method int getStartTime()
 * @method void setEndTime(int $endTime)
 * @method int getEndTime()
 * @method void setMessageId(?int $messageId)
 * @method int|null getMessageId()
 * @method void setReferenceId(?string $referenceId)
 * @method string|null getReferenceId()
 * @method void setAttendeeCount(int $attendeeCount)
 * @method int getAttendeeCount()
 * @method void setSummary(string $summary)
 * @method string getSummary()
 */
class Summary extends Entity {
	/** @var string */
	protected $token;

	/** @var int */
	protected $startTime;

	/** @var int */
	protected $endTime;

	/** @var ?int */
	protected $messageId;

	/** @var ?string */
	protected $referenceId;

	/** @var int */
	protected $attendeeCount;

	/** @var string */
	protected $summary;

	public function __construct() {
		$this->addType('token', 'string');
		$this->addType('startTime', 'integer');
		$this->addType('endTime', 'integer');
		$this->addType('messageId', 'integer');
		$this->addType('referenceId', 'string');
		$this->addType('attendeeCount', 'integer');
		$this->addType('summary', 'string');
	}
}
