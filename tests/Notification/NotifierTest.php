<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\CallSummaryBot\Tests\Notification;

use OCA\CallSummaryBot\Notification\Notifier;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\IUserFolder;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\IAction;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class NotifierTest extends TestCase {
	protected IFactory&MockObject $l10nFactory;
	protected IURLGenerator&MockObject $urlGenerator;
	protected IRootFolder&MockObject $rootFolder;
	protected Notifier $notifier;

	protected function setUp(): void {
		parent::setUp();
		$this->l10nFactory = $this->createMock(IFactory::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);

		$l = $this->createMock(IL10N::class);
		$l->method('t')
			->willReturnArgument(0);
		$l->method('n')
			->willReturnCallback(static fn (string $singular, string $plural, int $count): string => str_replace('%n', (string)$count, $count === 1 ? $singular : $plural));
		$this->l10nFactory->method('get')
			->willReturn($l);

		$this->notifier = new Notifier($this->l10nFactory, $this->urlGenerator, $this->rootFolder);
	}

	protected function createNotification(string $app, string $subject, array $parameters = []): INotification&MockObject {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')
			->willReturn($app);
		$notification->method('getSubject')
			->willReturn($subject);
		$notification->method('getSubjectParameters')
			->willReturn($parameters);
		$notification->method('getUser')
			->willReturn('alice');
		$notification->method('getObjectId')
			->willReturn('1337');
		return $notification;
	}

	/**
	 * IRootFolder::getUserFolder() returns an IUserFolder since Nextcloud 36
	 */
	protected function createUserFolderMock(): Folder&MockObject {
		return $this->createMock(interface_exists(IUserFolder::class) ? IUserFolder::class : Folder::class);
	}

	public function testPrepareOtherApp(): void {
		$this->expectException(UnknownNotificationException::class);
		$this->notifier->prepare($this->createNotification('spreed', 'exported'), 'en');
	}

	public function testPrepareDeletedFile(): void {
		$userFolder = $this->createUserFolderMock();
		$userFolder->method('getFirstNodeById')
			->with(1337)
			->willReturn(null);
		$this->rootFolder->method('getUserFolder')
			->with('alice')
			->willReturn($userFolder);

		$this->expectException(AlreadyProcessedException::class);
		$this->notifier->prepare($this->createNotification('call_summary_bot', 'exported', ['count' => 2, 'roomName' => 'Weekly']), 'en');
	}

	public function testPrepare(): void {
		$file = $this->createMock(File::class);
		$file->method('getId')
			->willReturn(1337);
		$file->method('getName')
			->willReturn('Weekly.md');
		$file->method('getPath')
			->willReturn('/alice/files/Call summaries/Weekly.md');

		$userFolder = $this->createUserFolderMock();
		$userFolder->method('getFirstNodeById')
			->with(1337)
			->willReturn($file);
		$userFolder->method('getRelativePath')
			->with('/alice/files/Call summaries/Weekly.md')
			->willReturn('/Call summaries/Weekly.md');
		$this->rootFolder->method('getUserFolder')
			->with('alice')
			->willReturn($userFolder);

		$this->urlGenerator->method('linkToRouteAbsolute')
			->with('files.viewcontroller.showFile', ['fileid' => 1337])
			->willReturn('https://cloud/f/1337');

		$action = $this->createMock(IAction::class);
		$action->method('setLabel')->willReturnSelf();
		$action->method('setParsedLabel')->willReturnSelf();
		$action->expects($this->once())
			->method('setLink')
			->with('https://cloud/f/1337', 'WEB')
			->willReturnSelf();
		$action->method('setPrimary')->willReturnSelf();

		$notification = $this->createNotification('call_summary_bot', 'exported', ['count' => 2, 'roomName' => 'Weekly']);
		$notification->method('createAction')
			->willReturn($action);
		$notification->expects($this->once())
			->method('setRichSubject')
			->with('Exported 2 call summaries of "Weekly" to {file}', [
				'file' => [
					'type' => 'file',
					'id' => '1337',
					'name' => 'Weekly.md',
					'path' => 'Call summaries/Weekly.md',
					'link' => 'https://cloud/f/1337',
				],
			])
			->willReturnSelf();
		$notification->expects($this->once())
			->method('setParsedSubject')
			->with('Exported 2 call summaries of "Weekly" to Call summaries/Weekly.md')
			->willReturnSelf();
		$notification->expects($this->once())
			->method('setLink')
			->with('https://cloud/f/1337')
			->willReturnSelf();
		$notification->method('setIcon')
			->willReturnSelf();
		$notification->expects($this->once())
			->method('addParsedAction')
			->with($action);

		self::assertSame($notification, $this->notifier->prepare($notification, 'en'));
	}
}
