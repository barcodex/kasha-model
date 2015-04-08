<?php

namespace Kasha\Model;

use Kasha\Caching\Cache;

class ModelMapper
{
	private static $cache = null;

	/** @var array */
	private $modelMapping = array();

	private function loadModelMappingFile($fileName, $moduleName = '')
	{
		foreach (explode(PHP_EOL, file_get_contents($fileName)) as $line) {
			$line = trim($line);
			if ($line != '') {
				list($modelClassName, $modelTableName, $optionalParams) = explode(':', $line, 3);
				parse_str($optionalParams, $modelParams);
				$modelParams['module'] = ($moduleName == '' ? '' : basename($moduleName));
				$modelParams['class'] = $modelClassName;
				//@TODO if model has i18n key, convert its value to the list
				if (isset($modelParams['i18n'])) {
					$modelParams['i18n'] = array_map('trim', explode(',', $modelParams['i18n']));
				}

				// when we save by keys, app modules can override shared modules
				$this->modelMapping[$modelTableName] = $modelParams;
			}
		}
	}

	private function getCacheMapping()
	{
		$output = false;
		if (!is_null($this->cache))
		{
			$output = Cache::get('settings:modelMapping');
		}

		return $output;
	}

	private function setCacheMapping($mapping)
	{
		if (!is_null($this->cache)) {
			Cache::set('settings:modelMapping', $mapping);
		}
	}

	private function loadModelMapping()
	{
		if ($mapping = $this->getCacheMapping()) {
			$this->modelMapping = json_decode($mapping, true);
		} else {
			// Load mapping for all models that are defined on the framework level
			$this->loadModelMappingFile(__DIR__ . '/settings/modelMapping.txt');
			// Load mapping for all models that are defined inside shared modules
			foreach (glob($this->r->config['folders']['shared'] . 'modules/*') as $module) {
				$fileName = "$module/settings/modelMapping.txt";
				if (file_exists($fileName)) {
					$this->loadModelMappingFile($fileName, $module);
				}
			}
			// Load mapping for all models that are defined inside app modules.
			//  Since app modules can have the same names, they can "override" shared app mappings
			foreach (glob($this->r->config['folders']['app'] . 'modules/*') as $module) {
				$fileName = "$module/settings/modelMapping.txt";
				if (file_exists($fileName)) {
					$this->loadModelMappingFile($fileName, $module);
				}
			}
			$this->setCacheMapping(json_encode($this->modelMapping, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
		}
	}

	public static function getModelMapping()
	{
		return self::$instance->modelMapping;
	}

	public function getModelInfo($tableName)
	{
		$output = array();
		if (array_key_exists($tableName, $this->modelMapping)) {
			$output = $this->modelMapping[$tableName];
		}

		return $output;
	}
}
