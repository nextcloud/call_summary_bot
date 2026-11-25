<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\CallSummaryBot\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version3006Date20261031120000 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('csb_summaries')) {
			$table = $schema->createTable('csb_summaries');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('token', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('start_time', Types::BIGINT, [
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('end_time', Types::BIGINT, [
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('message_id', Types::BIGINT, [
				'notnull' => false,
				'length' => 20,
			]);
			$table->addColumn('reference_id', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
			$table->addColumn('attendee_count', Types::INTEGER, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->addColumn('summary', Types::TEXT, [
				'notnull' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['token', 'start_time'], 'csb_summaries_token_start');
			$table->addIndex(['reference_id'], 'csb_summaries_reference');
			return $schema;
		}
		return null;
	}
}
