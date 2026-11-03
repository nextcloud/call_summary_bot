<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\CallSummaryBot\Tests\Listener;

use OCA\CallSummaryBot\Listener\ChatMessageSentListener;
use OCA\CallSummaryBot\Model\SummaryMapper;
use OCP\Comments\IComment;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class ChatMessageSentListenerTest extends TestCase {
	protected SummaryMapper&MockObject $summaryMapper;

	protected function setUp(): void {
		parent::setUp();
		$this->summaryMapper = $this->createMock(SummaryMapper::class);
	}

	public static function dataHandleComment(): array {
		return [
			['bots', 'abc', true],
			['bots', null, false],
			['bots', '', false],
			['users', 'abc', false],
		];
	}

	/**
	 * @dataProvider dataHandleComment
	 */
	public function testHandleComment(string $actorType, ?string $referenceId, bool $expected): void {
		$comment = $this->createMock(IComment::class);
		$comment->method('getActorType')
			->willReturn($actorType);
		$comment->method('getReferenceId')
			->willReturn($referenceId);
		$comment->method('getId')
			->willReturn('133');

		$this->summaryMapper->expects($expected ? $this->once() : $this->never())
			->method('setMessageIdByReferenceId')
			->with('abc', 133);

		(new ChatMessageSentListener($this->summaryMapper))->handleComment($comment);
	}
}
