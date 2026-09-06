<?php

/**
 *
 * Forum Fortress. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Marscastle Ltd trading as Forum Fortress
 * @license license.txt GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace forumfortress\protect\cron\task;

use forumfortress\protect\service\api_client;
use phpbb\config\config;

/**
 * Synchronises Forum Fortress state on phpBB's cron schedule.
 */
class sync extends \phpbb\cron\task\base
{
	protected const SYNC_INTERVAL_SECONDS = 600;

	protected api_client $client;
	protected config $config;

	public function __construct(api_client $client, config $config)
	{
		$this->client = $client;
		$this->config = $config;
	}

	public function run(): void
	{
		$this->client->hourly_sync();
	}

	public function is_runnable(): bool
	{
		return (bool) ($this->config['ffprotect_enabled'] ?? false)
			&& trim((string) ($this->config['ffprotect_api_key'] ?? '')) !== ''
			&& trim((string) ($this->config['ffprotect_site_id'] ?? '')) !== '';
	}

	public function should_run(): bool
	{
		$last = (int) ($this->config['ffprotect_cron_sync_last'] ?? 0);
		return $last < time() - self::SYNC_INTERVAL_SECONDS;
	}
}
