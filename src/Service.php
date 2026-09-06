<?php

// src/Service.php

namespace FreePBX\Modules\Oryk_Devices;

/**
 * What every part of this module is given when it is built.
 *
 * The subsystems this module is made of all reach the same three things:
 * the FreePBX application, so they can ask whether a module is installed
 * and get at the ones that are; the Asterisk database; and the manager
 * connection, which is not always there. Rather than each of them taking
 * those apart again, they are taken apart once here.
 *
 * The logging helpers exist for the same reason. Everything this module
 * writes to the FreePBX log is prefixed with the module name so a line can
 * be traced back to it, and a prefix repeated by hand in fifty places is a
 * prefix that eventually gets typed wrong.
 */
abstract class Service
{
	/**
	 * How every line this module logs begins.
	 */
	const LOG_PREFIX = 'oryk_devices: ';

	/**
	 * FreePBX application instance.
	 *
	 * @var object
	 */
	protected $freepbx;

	/**
	 * Asterisk database handle.
	 *
	 * @var \PDO
	 */
	protected $db;

	/**
	 * Asterisk manager connection, when one is available.
	 *
	 * @var object|null
	 */
	protected $astman;

	/**
	 * @param object $freepbx FreePBX application instance.
	 */
	public function __construct($freepbx)
	{
		$this->freepbx = $freepbx;
		$this->db = $freepbx->Database;
		$this->astman = $freepbx->astman;
	}

	/**
	 * Report whether a FreePBX module is installed and enabled.
	 *
	 * Most of what this module does reaches into another one, and which of
	 * those a site has is up to the site, so nearly everything asks this
	 * before it starts.
	 *
	 * @param string $module Module rawname.
	 *
	 * @return bool True when the module can be used.
	 */
	protected function moduleActive($module)
	{
		try {
			return (bool) $this->freepbx->Modules->checkStatus($module);
		} catch (\Throwable $e) {
			return false;
		}
	}

	/**
	 * Report whether the Asterisk manager can be written to.
	 *
	 * The manager is absent whenever Asterisk is not running, which is a
	 * normal state during an install or a restart rather than an error, so
	 * every write to the Asterisk database asks first.
	 *
	 * @return bool True when the manager is connected.
	 */
	protected function astmanReady()
	{
		return $this->astman && $this->astman->connected();
	}

	/**
	 * Log something that went wrong.
	 *
	 * @param string $message What happened.
	 *
	 * @return void
	 */
	protected function logError($message)
	{
		freepbx_log(FPBX_LOG_ERROR, self::LOG_PREFIX . $message);
	}

	/**
	 * Log something that did not go as expected but was stepped over.
	 *
	 * @param string $message What happened.
	 *
	 * @return void
	 */
	protected function logWarning($message)
	{
		freepbx_log(FPBX_LOG_WARNING, self::LOG_PREFIX . $message);
	}

	/**
	 * Log something worth being able to look back at.
	 *
	 * @param string $message What happened.
	 *
	 * @return void
	 */
	protected function logInfo($message)
	{
		freepbx_log(FPBX_LOG_INFO, self::LOG_PREFIX . $message);
	}
}
