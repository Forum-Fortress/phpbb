<?php

namespace forumfortress\protect\cron\task;

use forumfortress\protect\service\api_client;
use phpbb\config\config;
use phpbb\config\db_text;

class endpoint_catalog extends \phpbb\cron\task\base
{
	protected api_client $client;
	protected config $config;
	protected db_text $config_text;

	public function __construct(api_client $client, config $config, db_text $config_text)
	{
		$this->client = $client;
		$this->config = $config;
		$this->config_text = $config_text;
	}

	public function run(): void
	{
		$this->client->refresh_endpoint_catalog_if_stale();
	}

	public function is_runnable(): bool
	{
		return (bool) ($this->config['ffprotect_enabled'] ?? false)
			&& trim((string) ($this->config['ffprotect_api_key'] ?? '')) !== ''
			&& trim((string) ($this->config['ffprotect_api_base_url'] ?? '')) !== '';
	}

	public function should_run(): bool
	{
		$raw = trim((string) ($this->config_text->get('ffprotect_endpoint_state') ?? ''));
		$state = $raw !== '' ? json_decode($raw, true) : [];
		if (!is_array($state))
		{
			$state = [];
		}

		return \FfApiResilience::isEndpointCatalogStale($state);
	}
}
