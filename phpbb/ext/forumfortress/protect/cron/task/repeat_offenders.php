<?php
/** Copyright (c) 2026 Marscastle Ltd trading as Forum Fortress; GPL-2.0-or-later. */
namespace forumfortress\protect\cron\task;
class repeat_offenders extends \phpbb\cron\task\base
{
	private $worker;
	private $config;
	public function __construct(\forumfortress\protect\service\repeat_offenders $worker, \phpbb\config\config $config) { $this->worker=$worker; $this->config=$config; }
	public function run(): void { $this->worker->run($this); $this->config->set('ffprotect_repeat_last',time(),true); }
	public function is_runnable(): bool { return $this->worker->available(); }
	public function should_run(): bool { return (int)($this->config['ffprotect_repeat_last'] ?? 0)<time()-60; }
}
