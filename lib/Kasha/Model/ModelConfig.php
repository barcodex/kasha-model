<?php

namespace Kasha\Model;

use Kasha\Model\Cache;
use Kasha\Caching\Cache as BaseCache;

class ModelConfig
{
	/** @var ModelConfig */
	protected static $instance;

	/** @var BaseCache */
	private $cache = null;

	public static function getInstance()
	{
		if (is_null(self::$instance)) {
			self::$instance = new ModelConfig();
		}

		return self::$instance;
	}

	public function __construct()
	{
		$rootFolder = BaseCache::getInstance()->getRootFolder();
		$this->cache = new Cache($rootFolder);
	}

	/**
	 * @param BaseCache $cache
	 */
	public function setCache($cache)
	{
		$this->cache = $cache;
	}

	/**
	 * @return \Kasha\Caching\Cache|Cache|null
	 */
	public function getCache()
	{
		return $this->cache;
	}
}
