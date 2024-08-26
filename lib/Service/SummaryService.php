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
use OCP\IL10N;
use OCP\L10N\IFactory;

class SummaryService {
	public const UNCHECKED = '[ ]';
	public const CHECKED = '[x]';
	public const LIST_PATTERN = '/^[-*]\s(\[[ x]])\s*/mi';
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

		if (!preg_match(self::LIST_PATTERN, $firstLowerLine)
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
		if (!preg_match(self::LIST_PATTERN, $firstLowerLine)) {
			$parsedMessage = preg_replace(self::TASK_PATTERN, '- [ ] ', $parsedMessage);
		}

		if (str_starts_with($parsedMessage, '- [ ] ') || str_starts_with($parsedMessage, '- [x] ')) {
			$todos = preg_split(self::LIST_PATTERN, $parsedMessage, flags: PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
			$nextTodoSolved = false;
			foreach ($todos as $todo) {
				if ($todo === self::UNCHECKED || $todo === self::CHECKED) {
					$nextTodoSolved = $todo === self::CHECKED;
					continue;
				}

				$todoText = trim($todo);
				if ($todoText) {
					// Only store when not empty
					$this->saveTask($server, $data['target']['id'], $todoText, $nextTodoSolved);
				}
			}

			// React with thumbs up as we detected a task
			return true;
		}

		return false;
	}

	protected function saveTask(string $server, string $token, string $text, bool $solved = false): void {
		$logEntry = new LogEntry();
		$logEntry->setServer($server);
		$logEntry->setToken($token);
		$logEntry->setType($solved ? LogEntry::TYPE_SOLVED : LogEntry::TYPE_TODO);
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

		$attendees = $todos = $solved = [];
		$elevator = null;

		foreach ($logEntries as $logEntry) {
			if ($logEntry->getType() === LogEntry::TYPE_START) {
				$time = (int)$logEntry->getDetails();
				if ($startTimestamp > $time) {
					$startTimestamp = $time;
				}
			} elseif ($logEntry->getType() === LogEntry::TYPE_ATTENDEE) {
				$attendees[] = $logEntry->getDetails();
			} elseif ($logEntry->getType() === LogEntry::TYPE_TODO) {
				$todos[] = $logEntry->getDetails();
			} elseif ($logEntry->getType() === LogEntry::TYPE_SOLVED) {
				$solved[] = $logEntry->getDetails();
			} elseif ($logEntry->getType() === LogEntry::TYPE_ELEVATOR) {
				$elevator = (int)$logEntry->getDetails();
			}
		}

		if (($endTimestamp - $startTimestamp) < (int)$this->config->getAppValue('call_summary_bot', 'min-length', '60')) {
			// No call summary for short calls
			return null;
		}

		$attendees = array_unique($attendees);
		sort($attendees);

		$startDate = $this->dateTimeFormatter->formatDate($startTimestamp, 'full', null, $libL10N);
		$startTime = $this->dateTimeFormatter->formatTime($startTimestamp, 'short', null, $libL10N);
		$endTime = $this->dateTimeFormatter->formatTime($endTimestamp, 'short', null, $libL10N);


		$summary = '# ' . $this->getTitle($l, $roomName) . "\n\n";
		$summary .= $startDate . ' · ' . $startTime  . ' – ' . $endTime
			. ' (' . $endDateTime->getTimezone()->getName() . ")\n";

		$summary .= "\n";
		$summary .= '## ' . $l->t('Attendees') . "\n";
		foreach ($attendees as $attendee) {
			$summary .= '- ' . $attendee . "\n";
		}

		if (!empty($todos) || !empty($solved)) {
			$summary .= "\n";
			$summary .= '## ' . $l->t('Tasks') . "\n";
			foreach ($solved as $todo) {
				$summary .= '- [x] ' . $todo . "\n";
			}
			foreach ($todos as $todo) {
				$summary .= '- [ ] ' . $todo . "\n";
			}
		}

		return ['summary' => $summary, 'elevator' => $elevator];
	}

	protected function getTitle(IL10N $l, string $roomName): string {
		try {
			$data = json_decode($roomName, true, flags: JSON_THROW_ON_ERROR);
			if (is_array($data) && count($data) === 2 && isset($data[0]) && is_string($data[0]) && isset($data[1]) && is_string($data[1])) {
				// Seems like the room name is a JSON map with the 2 user IDs of a 1-1 conversation,
				// so we don't add it to the title to avoid things like:
				// `Call summary - ["2991c735-4f9e-46e2-a107-7569dd19fdf8","42e6a9c2-a833-43f6-ab47-6b7004094912"]`
				return $l->t('Call summary');
			}
		} catch (\JsonException) {
		}

		return str_replace('{title}', $roomName, $l->t('Call summary - {title}'));
	}
}
