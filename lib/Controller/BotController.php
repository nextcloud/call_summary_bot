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

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\Http\Client\IClientService;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class BotController extends OCSController {

	public function __construct(
		string $appName,
		IRequest $request,
		protected IClientService $clientService,
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
	public function receiveWebhook(): DataResponse {
		$configData = file_get_contents(__DIR__ . '/webhook.json');

		if ($configData === false) {
			$this->logger->error('Could not read config');
			return new DataResponse(null, Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$config = json_decode($configData, true);

		if ($config === null) {
			$this->logger->error('Could not json_decode config');
			return new DataResponse(null, Http::STATUS_INTERNAL_SERVER_ERROR);
		}


		$signature = $this->request->getHeader('X_NEXTCLOUD_TALK_SIGNATURE');
		$random = $this->request->getHeader('X_NEXTCLOUD_TALK_RANDOM');
		$server = $this->request->getHeader('X_NEXTCLOUD_TALK_BACKEND');

		$body = $this->getInputStream();
		$generatedDigest = hash_hmac('sha256', $random . $body, $config['secret']);

		if (!hash_equals($generatedDigest, strtolower($signature))) {
			$this->logger->warning('Message signature could not be verified');
			$response = new DataResponse(null, Http::STATUS_UNAUTHORIZED);
			$response->throttle(['action' => 'webhook']);
			return $response;
		}

		$this->logger->debug($body);
		$data = json_decode($body, true);

		if ($server) {
			$message = 'Re: ' . json_decode($data['object']['name'], true)['message'];
			$body = [
				'message' => $message,
				'referenceId' => sha1($random),
				'replyTo' => (int) $data['object']['id'],
			];

			$jsonBody = json_encode($body, JSON_THROW_ON_ERROR);

			$random = bin2hex(random_bytes(32));
			$hash = hash_hmac('sha256', $random . $message, $config['secret']);
			$this->logger->info('Reply: Random ' . $random);
			$this->logger->info('Reply: Hash ' . $hash);

			try {
				$options = [
					'headers' => [
						'OCS-APIRequest' => 'true',
						'Content-Type' => 'application/json',
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
		return new DataResponse();
	}
}
