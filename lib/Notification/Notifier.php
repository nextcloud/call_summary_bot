<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\CallSummaryBot\Notification;

use OCA\CallSummaryBot\AppInfo\Application;
use OCP\Files\IRootFolder;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

class Notifier implements INotifier {
	public const OBJECT_TYPE_EXPORT = 'export';
	public const SUBJECT_EXPORTED = 'exported';

	public function __construct(
		protected IFactory $l10nFactory,
		protected IURLGenerator $urlGenerator,
		protected IRootFolder $rootFolder,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('Call summary bot');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID
			|| $notification->getSubject() !== self::SUBJECT_EXPORTED) {
			throw new UnknownNotificationException();
		}

		$file = $this->rootFolder->getUserFolder($notification->getUser())
			->getFirstNodeById((int)$notification->getObjectId());
		if ($file === null) {
			// The export was deleted in the meantime
			throw new AlreadyProcessedException();
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$parameters = $notification->getSubjectParameters();
		$count = (int)($parameters['count'] ?? 0);
		$roomName = (string)($parameters['roomName'] ?? '');

		if ($roomName === '') {
			$subject = $l->n('Exported %n call summary to {file}', 'Exported %n call summaries to {file}', $count);
		} else {
			$subject = str_replace(
				'{conversation}',
				$roomName,
				$l->n('Exported %n call summary of "{conversation}" to {file}', 'Exported %n call summaries of "{conversation}" to {file}', $count)
			);
		}

		$path = $this->rootFolder->getUserFolder($notification->getUser())->getRelativePath($file->getPath()) ?? $file->getName();
		$path = ltrim($path, '/');
		$link = $this->urlGenerator->linkToRouteAbsolute('files.viewcontroller.showFile', ['fileid' => $file->getId()]);

		$notification->setRichSubject($subject, [
			'file' => [
				'type' => 'file',
				'id' => (string)$file->getId(),
				'name' => $file->getName(),
				'path' => $path,
				'link' => $link,
			],
		])
			->setParsedSubject(str_replace('{file}', $path, $subject))
			->setLink($link)
			->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('core', 'filetypes/text.svg')));

		$action = $notification->createAction();
		$action->setLabel('open')
			->setParsedLabel($l->t('Open file'))
			->setLink($link, 'WEB')
			->setPrimary(true);
		$notification->addParsedAction($action);

		return $notification;
	}
}
