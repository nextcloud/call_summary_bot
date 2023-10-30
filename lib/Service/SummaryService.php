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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\CallSummaryBot\Service;

use OCA\CallSummaryBot\Model\LogEntry;
use OCA\CallSummaryBot\Model\LogEntryMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IDateTimeFormatter;
use OCP\L10N\IFactory;

class SummaryService {
	public const TASK_PATTERN = '/(^[-*]\s|^)(to[\s-]?do|task)s?\s*:/mi';

	public function __construct(
		protected IConfig $config,
		protected LogEntryMapper $logEntryMapper,
		protected ITimeFactory $timeFactory,
		protected IDateTimeFormatter $dateTimeFormatter,
		protected IFactory $l10nFactory,
	) {
	}

	public function readTasksFromMessage(string $message, array $messageData, string $server, array $data): bool {
		$endOfFirstLine = strpos($message, "\n") ?: -1;
		$firstLowerLine = strtolower(substr($message, 0, $endOfFirstLine));

		if (!str_starts_with($firstLowerLine, '- [ ] ')
			&& !preg_match(self::TASK_PATTERN, $firstLowerLine)) {
			return false;
		}


		$placeholders = $replacements = [];
		foreach ($messageData['parameters'] as $placeholder => $parameter) {
			$placeholders[] = '{' . $placeholder . '}';
			if ($parameter['type'] === 'user') {
				if (str_contains($parameter['id'], ' ') || str_contains($parameter['id'], '/')) {
					$replacements[] = '@"' . $parameter['id'] . '"';
				} else {
					$replacements[] = '@' . $parameter['id'];
				}
			} elseif ($parameter['type'] === 'call') {
				$replacements[] = '@all';
			} elseif ($parameter['type'] === 'guest') {
				$replacements[] = '@' . $parameter['name'];
			} else {
				$replacements[] = $parameter['name'];
			}
		}

		$parsedMessage = str_replace($placeholders, $replacements, $message);
		if (!str_starts_with($firstLowerLine, '- [ ] ')) {
			$parsedMessage = preg_replace(self::TASK_PATTERN, '- [ ] ', $parsedMessage);
		}

		if (str_starts_with($parsedMessage, '- [ ] ')) {
			// Cut of the first `- [ ] `
			$todos = explode("\n- [ ] ", substr($parsedMessage, 5));
			foreach ($todos as $todo) {
				$todoText = trim($todo);

				if ($todoText) {
					// Only store when not empty
					$this->saveTask($server, $data['target']['id'], $todoText);
				}
			}

			// React with thumbs up as we detected a task
			return true;
		}

		return false;
	}

	protected function saveTask(string $server, string $token, string $text): void {
		$logEntry = new LogEntry();
		$logEntry->setServer($server);
		$logEntry->setToken($token);
		$logEntry->setType(LogEntry::TYPE_TODO);
		$logEntry->setDetails($text);
		$this->logEntryMapper->insert($logEntry);
	}

	/**
	 * @param string $server
	 * @param string $token
	 * @param string $roomName
	 * @param string $lang
	 * @return array{summary: string, elevator: ?int}|null
	 */
	public function summarize(string $server, string $token, string $roomName, string $lang = 'en'): ?array {
		$logEntries = $this->logEntryMapper->findByConversation($server, $token);
		$this->logEntryMapper->deleteByConversation($server, $token);

		$libL10N = $this->l10nFactory->get('lib', $lang);
		$l = $this->l10nFactory->get('call_summary_bot', $lang);

		$endDateTime = $this->timeFactory->now();
		$endTimestamp = $endDateTime->getTimestamp();
		$startTimestamp = $endTimestamp;

		$attendees = [];
		$todos = [];
		$elevator = null;

		foreach ($logEntries as $logEntry) {
			if ($logEntry->getType() === LogEntry::TYPE_START) {
				$time = (int) $logEntry->getDetails();
				if ($startTimestamp > $time) {
					$startTimestamp = $time;
				}
			} elseif ($logEntry->getType() === LogEntry::TYPE_ATTENDEE) {
				$attendees[] = $logEntry->getDetails();
			} elseif ($logEntry->getType() === LogEntry::TYPE_TODO) {
				$todos[] = $logEntry->getDetails();
			} elseif ($logEntry->getType() === LogEntry::TYPE_ELEVATOR) {
				$elevator = (int) $logEntry->getDetails();
			}
		}

		if (($endTimestamp - $startTimestamp) < (int) $this->config->getAppValue('call_summary_bot', 'min-length', '60')) {
			// No call summary for short calls
			return null;
		}

		$attendees = array_unique($attendees);
		sort($attendees);

		$startDate = $this->dateTimeFormatter->formatDate($startTimestamp, 'full', null, $libL10N);
		$startTime = $this->dateTimeFormatter->formatTime($startTimestamp, 'short', null, $libL10N);
		$endTime = $this->dateTimeFormatter->formatTime($endTimestamp, 'short', null, $libL10N);


		$summary = '# ' . str_replace('{title}', $roomName, $l->t('Call summary - {title}')) . "\n\n";
		$summary .= $startDate . ' · ' . $startTime  . ' – ' . $endTime
			. ' (' . $endDateTime->getTimezone()->getName() . ")\n";

		$summary .= "\n";
		$summary .= '## ' . $l->t('Attendees') . "\n";
		foreach ($attendees as $attendee) {
			$summary .= '- ' . $attendee . "\n";
		}

		if (!empty($todos)) {
			$summary .= "\n";
			$summary .= '## ' . $l->t('Tasks') . "\n";
			foreach ($todos as $todo) {
				$summary .= '- [ ] ' . $todo . "\n";
			}
		}

		return ['summary' => $summary, 'elevator' => $elevator];
	}
}
