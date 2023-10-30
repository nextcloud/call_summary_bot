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

namespace OCA\CallSummaryBot\Controller;

use OCA\CallSummaryBot\Model\Bot;
use OCA\CallSummaryBot\Model\LogEntry;
use OCA\CallSummaryBot\Model\LogEntryMapper;
use OCA\CallSummaryBot\Service\SummaryService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class BotController extends OCSController {

	protected bool $legacySecret = false;

	public function __construct(
		string $appName,
		IRequest $request,
		protected IClientService $clientService,
		protected ITimeFactory $timeFactory,
		protected LogEntryMapper $logEntryMapper,
		protected SummaryService $summaryService,
		protected IConfig $config,
		protected LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Return the body of the POST request
	 */
	protected function getInputStream(): string {
		return file_get_contents('php://input');
	}

	#[BruteForceProtection(action: 'webhook')]
	#[PublicPage]
	public function receiveWebhook(string $lang): DataResponse {
		if (!in_array($lang, Bot::SUPPORTED_LANGUAGES, true)) {
			$this->logger->warning('Request for unsupported language was sent');
			$response = new DataResponse([], Http::STATUS_BAD_REQUEST);
			$response->throttle(['action' => 'webhook']);
			return $response;
		}

		$signature = $this->request->getHeader('X_NEXTCLOUD_TALK_SIGNATURE');
		$random = $this->request->getHeader('X_NEXTCLOUD_TALK_RANDOM');
		$server = rtrim($this->request->getHeader('X_NEXTCLOUD_TALK_BACKEND'), '/') . '/';

		$secretData = $this->config->getAppValue('call_summary_bot', 'secret_' . sha1($server));
		if ($secretData === '') {
			$this->logger->warning('No matching secret found for server: ' . $server);
			$response = new DataResponse([], Http::STATUS_UNAUTHORIZED);
			$response->throttle(['action' => 'webhook']);
			return $response;
		}

		try {
			$config = json_decode($secretData, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			$this->logger->error('Could not json_decode config');
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$body = $this->getInputStream();
		$secret = $config['secret'] . str_replace('_', '', $lang);
		$generatedDigest = hash_hmac('sha256', $random . $body, $secret);

		if (!hash_equals($generatedDigest, strtolower($signature))) {
			$generatedLegacyDigest = hash_hmac('sha256', $random . $body, $config['secret']);
			if (!hash_equals($generatedLegacyDigest, strtolower($signature))) {
				$this->logger->warning('Message signature could not be verified');
				$response = new DataResponse([], Http::STATUS_UNAUTHORIZED);
				$response->throttle(['action' => 'webhook']);
				return $response;
			}
			// Installed before final release, when the secret was not unique
			$secret = $config['secret'];
			$this->legacySecret = true;
		}

		$this->logger->debug($body);
		$data = json_decode($body, true);

		if ($data['type'] === 'Create' && $data['object']['name'] === 'message') {
			if (!$this->logEntryMapper->hasActiveCall($server, $data['target']['id'])) {
				return new DataResponse();
			}

			$messageData = json_decode($data['object']['content'], true);
			$message = $messageData['message'];

			$taskDetected = $this->summaryService->readTasksFromMessage($message, $messageData, $server, $data);

			if ($taskDetected) {
				// React with thumbs up as we detected a task
				$this->sendReaction($server, $secret, $data);
				// Sample: $this->removeReaction($server, $secret, $data);
			}
		} elseif ($data['type'] === 'Activity') {
			if ($data['object']['name'] === 'call_joined' || $data['object']['name'] === 'call_started') {
				if ($data['object']['name'] === 'call_started') {
					$logEntry = new LogEntry();
					$logEntry->setServer($server);
					$logEntry->setToken($data['target']['id']);
					$logEntry->setType(LogEntry::TYPE_START);
					$logEntry->setDetails((string) $this->timeFactory->now()->getTimestamp());
					$this->logEntryMapper->insert($logEntry);

					$logEntry = new LogEntry();
					$logEntry->setServer($server);
					$logEntry->setToken($data['target']['id']);
					$logEntry->setType(LogEntry::TYPE_ELEVATOR);
					$logEntry->setDetails((string) $data['object']['id']);
					$this->logEntryMapper->insert($logEntry);
				}

				$logEntry = new LogEntry();
				$logEntry->setServer($server);
				$logEntry->setToken($data['target']['id']);
				$logEntry->setType(LogEntry::TYPE_ATTENDEE);
				$logEntry->setDetails($data['actor']['name']);
				if ($logEntry->getDetails()) {
					// Only store when not empty
					$this->logEntryMapper->insert($logEntry);
				}
			} elseif ($data['object']['name'] === 'call_ended' || $data['object']['name'] === 'call_ended_everyone') {
				$summary = $this->summaryService->summarize($server, $data['target']['id'], $data['target']['name'], $lang);
				if ($summary !== null) {
					$body = [
						'message' => $summary['summary'],
						'referenceId' => sha1($random),
						'replyTo' => sha1($random),
					];

					if (!empty($summary['elevator'])) {
						$body['replyTo'] = $summary['elevator'];
					}

					// Generate and post summary
					$this->sendResponse($server, $secret, $body, $data);
				}
			}
		}
		return new DataResponse();
	}

	protected function sendResponse(string $server, string $secret, array $body, array $data): void {
		$jsonBody = json_encode($body, JSON_THROW_ON_ERROR);

		$random = bin2hex(random_bytes(32));
		$hash = hash_hmac('sha256', $random . $body['message'], $secret);
		$this->logger->debug('Reply: Random ' . $random);
		$this->logger->debug('Reply: Hash ' . $hash);

		try {
			$options = [
				'headers' => [
					'OCS-APIRequest' => 'true',
					'Content-Type' => 'application/json',
					'Accept' => 'application/json',
					'X-Nextcloud-Talk-Bot-Random' => $random,
					'X-Nextcloud-Talk-Bot-Signature' => $hash,
					'User-Agent' => 'nextcloud-call-summary-bot/1.0',
				],
				'body' => $jsonBody,
				'verify' => false, // FIXME
			];

			$client = $this->clientService->newClient();
			$response = $client->post(rtrim($server, '/') . '/ocs/v2.php/apps/spreed/api/v1/bot/' . $data['target']['id'] . '/message', $options);
			$this->logger->info('Response: ' . $response->getBody());
		} catch (\Exception $exception) {
			$this->logger->info(get_class($exception) . ': ' . $exception->getMessage());
		}
	}

	protected function sendReaction(string $server, string $secret, array $data): void {
		$body = [
			'reaction' => '👍',
		];
		$jsonBody = json_encode($body, JSON_THROW_ON_ERROR);

		$random = bin2hex(random_bytes(32));
		$hash = hash_hmac('sha256', $random . $body['reaction'], $secret);
		$this->logger->debug('Reaction: Random ' . $random);
		$this->logger->debug('Reaction: Hash ' . $hash);

		try {
			$options = [
				'headers' => [
					'OCS-APIRequest' => 'true',
					'Content-Type' => 'application/json',
					'Accept' => 'application/json',
					'X-Nextcloud-Talk-Bot-Random' => $random,
					'X-Nextcloud-Talk-Bot-Signature' => $hash,
					'User-Agent' => 'nextcloud-call-summary-bot/1.0',
				],
				'body' => $jsonBody,
				'verify' => false, // FIXME
			];

			$client = $this->clientService->newClient();
			$response = $client->post(rtrim($server, '/') . '/ocs/v2.php/apps/spreed/api/v1/bot/' . $data['target']['id'] . '/reaction/' . $data['object']['id'], $options);
			$this->logger->info('Response: ' . $response->getBody());
		} catch (\Exception $exception) {
			$this->logger->info(get_class($exception) . ': ' . $exception->getMessage());
		}
	}

	protected function removeReaction(string $server, string $secret, array $data): void {
		$body = [
			'reaction' => '👍',
		];
		$jsonBody = json_encode($body, JSON_THROW_ON_ERROR);

		$random = bin2hex(random_bytes(32));
		$hash = hash_hmac('sha256', $random . $body['reaction'], $secret);
		$this->logger->debug('RemoveReaction: Random ' . $random);
		$this->logger->debug('RemoveReaction: Hash ' . $hash);

		try {
			$options = [
				'headers' => [
					'OCS-APIRequest' => 'true',
					'Content-Type' => 'application/json',
					'Accept' => 'application/json',
					'X-Nextcloud-Talk-Bot-Random' => $random,
					'X-Nextcloud-Talk-Bot-Signature' => $hash,
					'User-Agent' => 'nextcloud-call-summary-bot/1.0',
				],
				'body' => $jsonBody,
				'verify' => false, // FIXME
			];

			$client = $this->clientService->newClient();
			$response = $client->delete(rtrim($server, '/') . '/ocs/v2.php/apps/spreed/api/v1/bot/' . $data['target']['id'] . '/reaction/' . $data['object']['id'], $options);
			$this->logger->info('Response: ' . $response->getBody());
		} catch (\Exception $exception) {
			$this->logger->info(get_class($exception) . ': ' . $exception->getMessage());
		}
	}
}
