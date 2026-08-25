<?php

namespace forumfortress\protect;

require_once __DIR__ . '/vendor/autoload.php';

class ext extends \phpbb\extension\base
{
	public function enable_step($old_state)
	{
		if ($old_state === false)
		{
			$cache = $this->container->get('cache');
			$cache->destroy('_ext');
		}

		return parent::enable_step($old_state);
	}

	public function purge_step($old_state)
	{
		$state = parent::purge_step($old_state);

		if ($state === false)
		{
			$this->purge_orphan_acp_modules();
		}

		return $state;
	}

	protected function purge_orphan_acp_modules(): void
	{
		if (!$this->container->has('migrator.tool.module'))
		{
			return;
		}

		foreach (migrations\acp_module_data::remove_all() as $step)
		{
			if ($step[0] !== 'module.remove')
			{
				continue;
			}
			$args = $step[1];
			$this->container->get('migrator.tool.module')->remove(
				$args[0],
				$args[1],
				$args[2] ?? ''
			);
		}

		$this->container->get('module.manager')->remove_cache_file('acp');
	}
}
