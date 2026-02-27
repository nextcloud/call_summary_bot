<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\CallSummaryBot\Model;

class Bot {
	public const COMMAND_SILENT_MUTED = '!agenda silent muted';
	public const COMMAND_SILENT_POST = '!agenda silent post';

	public const SUPPORTED_LANGUAGES = [
		'en',
		'de',
		'es',
		'fr',
		'ar',
		'pt_BR',
		'tr',
		'zh_CN',
	];
}
