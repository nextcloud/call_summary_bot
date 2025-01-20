<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\CallSummaryBot\Listener;

use OCA\CallSummaryBot\AppInfo\Application;
use OCA\CallSummaryBot\Model\Bot;
use OCA\CallSummaryBot\Model\LogEntry;
use OCA\CallSummaryBot\Model\LogEntryMapper;
use OCA\CallSummaryBot\Service\SummaryService;
use OCA\Talk\Events\BotInvokeEvent;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<Event>
 */
class BotInvokeListener implements IEventListener {
	public function __construct(
		protected ITimeFactory $timeFactory,
		protected IFactory $l10nFactory,
		protected LogEntryMapper $logEntryMapper,
		protected SummaryService $summaryService,
		protected IConfig $config,
		protected LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof BotInvokeEvent) {
			return;
		}

		if (!str_starts_with($event->getBotUrl(), Application::APP_ID . '/')) {
			return;
		}

		[$appId, $lang] = explode('/', $event->getBotUrl(), 2);
		if ($appId !== Application::APP_ID || !in_array($lang, Bot::SUPPORTED_LANGUAGES, true)) {
			return;
		}

		$this->receiveWebhook($lang, $event);
	}

	public function receiveWebhook(string $lang, BotInvokeEvent $event): void {
		$data = $event->getMessage();
		if ($data['type'] === 'Create' && $data['object']['name'] === 'message') {
			$messageData = json_decode($data['object']['content'], true);
			$message = $messageData['message'];

			if (!$this->logEntryMapper->hasActiveCall($data['target']['id'])) {
				$agendaDetected = $this->summaryService->readAgendaFromMessage($message, $messageData, $data);

				if ($agendaDetected) {
					// React with thumbs up as we detected an agenda item
					$event->addReaction('👍');
				}
				return;
			}

			$taskDetected = $this->summaryService->readTasksFromMessage($message, $messageData, $data);

			if ($taskDetected) {
				// React with thumbs up as we detected a task
				$event->addReaction('👍');
			}
		} elseif ($data['type'] === 'Activity') {
			if ($data['object']['name'] === 'call_joined' || $data['object']['name'] === 'call_started') {
				if ($data['object']['name'] === 'call_started') {
					$agenda = $this->summaryService->agenda($data['target']['id'], $lang);
					if ($agenda !== null) {
						$event->addAnswer($agenda, true);
					}

					$logEntry = new LogEntry();
					$logEntry->setServer('local');
					$logEntry->setToken($data['target']['id']);
					$logEntry->setType(LogEntry::TYPE_START);
					$logEntry->setDetails((string)$this->timeFactory->now()->getTimestamp());
					$this->logEntryMapper->insert($logEntry);

					$logEntry = new LogEntry();
					$logEntry->setServer('local');
					$logEntry->setToken($data['target']['id']);
					$logEntry->setType(LogEntry::TYPE_ELEVATOR);
					$logEntry->setDetails((string)$data['object']['id']);
					$this->logEntryMapper->insert($logEntry);
				}

				$logEntry = new LogEntry();
				$logEntry->setServer('local');
				$logEntry->setToken($data['target']['id']);
				$logEntry->setType(LogEntry::TYPE_ATTENDEE);

				$displayName = $data['actor']['name'];
				if (str_starts_with($data['actor']['id'], 'guests/') || str_starts_with($data['actor']['id'], 'emails/')) {
					if ($displayName === '') {
						return;
					}
					$l = $this->l10nFactory->get('call_summary_bot', $lang);
					$displayName = $l->t('%s (guest)', $displayName);
				} elseif (str_starts_with($data['actor']['id'], 'federated_users/')) {
					$cloudIdServer = explode('@', $data['actor']['id']);
					$displayName .= ' (' . array_pop($cloudIdServer) . ')';
				}

				$logEntry->setDetails($displayName);
				if ($logEntry->getDetails()) {
					// Only store when not empty
					$this->logEntryMapper->insert($logEntry);
				}
			} elseif ($data['object']['name'] === 'call_ended' || $data['object']['name'] === 'call_ended_everyone') {
				$summary = $this->summaryService->summarize($data['target']['id'], $data['target']['name'], $lang);
				if ($summary !== null) {
					$event->addAnswer($summary['summary'], $summary['elevator']);
				}
			}
		}
	}
}
