<?php

namespace forumfortress\protect\migrations;

class v_1_0_20_api_region extends \phpbb\db\migration\container_aware_migration
{
	public static function depends_on()
	{
		return ['\\forumfortress\\protect\\migrations\\v_1_0_19_acp_module_path'];
	}

	public function effectively_installed()
	{
		return isset($this->config['ffprotect_api_region'])
			&& isset($this->config['ffprotect_allow_global_fallback']);
	}

	public function update_data()
	{
		return [
			['config.add', ['ffprotect_api_region', 'global']],
			['config.add', ['ffprotect_allow_global_fallback', 0]],
			['custom', [[$this, 'migrate_api_region']]],
		];
	}

	public function migrate_api_region()
	{
		$legacy = strtolower(rtrim((string) ($this->config['ffprotect_api_base_url'] ?? ''), '/'));
		$regions = [
			'https://api.ffapi.net' => 'global',
			'https://api-uk.ffapi.net' => 'uk',
			'https://api-eu.ffapi.net' => 'eu',
			'https://api-us.ffapi.net' => 'us',
		];
		$region = $regions[$legacy] ?? 'global';
		$this->config->set('ffprotect_api_region', $region);
		$this->config->set('ffprotect_api_base_url', array_search($region, $regions, true));
		$this->config->set('ffprotect_preferred_endpoint', '');
	}

	public function revert_data()
	{
		return [
			['config.remove', ['ffprotect_api_region']],
			['config.remove', ['ffprotect_allow_global_fallback']],
		];
	}
}
