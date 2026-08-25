<?php

namespace forumfortress\protect\migrations;

class v_1_0_0 extends \phpbb\db\migration\migration
{
	public function effectively_installed()
	{
		return isset($this->config['ffprotect_enabled'])
			&& isset($this->config['ffprotect_delete_rejected_users'])
			&& isset($this->config['ffprotect_cron_sync_last'])
			&& isset($this->config['ffprotect_primary_domain'])
			&& isset($this->config['ffprotect_control_base_url'])
			&& isset($this->config['ffprotect_preferred_endpoint'])
			&& isset($this->config['ffprotect_bypass_administrators'])
			&& isset($this->config['ffprotect_acp_menu_v2']);
	}

	public function update_data()
	{
		return array_merge(
			[
				['config.add', ['ffprotect_enabled', 1]],
				['config.add', ['ffprotect_api_base_url', 'https://api.ffapi.net']],
				['config.add', ['ffprotect_api_region', 'global']],
				['config.add', ['ffprotect_allow_global_fallback', 0]],
				['config.add', ['ffprotect_control_base_url', 'https://control.ffapi.net']],
				['config.add', ['ffprotect_preferred_endpoint', '']],
				['config.add', ['ffprotect_timeout', 3]],
				['config.add', ['ffprotect_api_key', '']],
				['config.add', ['ffprotect_site_id', '']],
				['config.add', ['ffprotect_fail_open', 1]],
				['config.add', ['ffprotect_debug_log', 0]],
				['config.add', ['ffprotect_send_ham', 1]],
				['config.add', ['ffprotect_delete_rejected_users', 0]],
				['config.add', ['ffprotect_cron_sync_last', 0]],
				['config.add', ['ffprotect_primary_domain', '']],
				['config.add', ['ffprotect_endpoint_state', '']],
				['config.add', ['ffprotect_bypass_administrators', 1]],
				['config.add', ['ffprotect_bypass_moderators', 1]],
				['config.add', ['ffprotect_acp_menu_v2', 1]],
			],
			acp_module_data::add_modules()
		);
	}

	public function revert_data()
	{
		return array_merge(
			acp_module_data::remove_all(),
			[
				['config.remove', ['ffprotect_enabled']],
				['config.remove', ['ffprotect_api_base_url']],
				['config.remove', ['ffprotect_api_region']],
				['config.remove', ['ffprotect_allow_global_fallback']],
				['config.remove', ['ffprotect_control_base_url']],
				['config.remove', ['ffprotect_preferred_endpoint']],
				['config.remove', ['ffprotect_timeout']],
				['config.remove', ['ffprotect_api_key']],
				['config.remove', ['ffprotect_site_id']],
				['config.remove', ['ffprotect_fail_open']],
				['config.remove', ['ffprotect_debug_log']],
				['config.remove', ['ffprotect_send_ham']],
				['config.remove', ['ffprotect_delete_rejected_users']],
				['config.remove', ['ffprotect_cron_sync_last']],
				['config.remove', ['ffprotect_primary_domain']],
				['config.remove', ['ffprotect_endpoint_state']],
				['config.remove', ['ffprotect_bypass_administrators']],
				['config.remove', ['ffprotect_bypass_moderators']],
				['config.remove', ['ffprotect_acp_menu_v2']],
			]
		);
	}
}
