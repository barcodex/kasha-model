<?php

namespace Kasha\Model;

use Temple\Util;
use Kasha\Caching\Cache as BaseCache;

class Cache extends BaseCache
{
	/** @var array */
	protected $modelMetadata = array(); // each item stored in this array is also an array

	/** @var array */
	protected $models = array(); // each item stored in this array is also an array

	public function __construct($rootFolder)
	{
		$this->setRootFolder($rootFolder);
		self::$instance = $this;
	}

//region metadata

	/**
	 * @param $modelName
	 *
	 * @return bool|mixed
	 */
	public function getModelMetadata($modelName)
	{
		$metadata = false;

		if (array_key_exists($modelName, $this->modelMetadata)) {
			// we already have de-serialized version in cache (it means it was already used by the Runtime)
			$metadata = $this->modelMetadata[$modelName];
		} else {
			// try to get serialized model from the cache
			$modelSerialized = (self::hasKey('metadata/' . $modelName)) ? self::get('metadata/' . $modelName) : false;
			$metadata = $modelSerialized ? json_decode($modelSerialized, true) : false;
			$this->modelMetadata[$modelName] = $metadata;
		}

		return $metadata;
	}

	/**
	 * @param $modelName
	 * @param $metadata
	 */
	public function setModelMetadata($modelName, $metadata)
	{
		$this->modelMetadata[$modelName] = $metadata;
		self::set('metadata/' . $modelName, json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
	}

	/**
	 * @param $modelName
	 */
	public function deleteModelMetadata($modelName)
	{
		if (isset($this->modelMetadata[$modelName])) {
			unset($this->modelMetadata[$modelName]);
		}

		// global cache might have the key even if dictionary cache does not -> delete it
		self::delete('metadata/' . $modelName);
	}

	/**
	 * @return int
	 */
	public function invalidateAllMetadata()
	{
		self::delete('settings:modelMapping');
		$prefix = 'metadata/';
		return self::deleteByPrefix($prefix);
	}

//endregion

//region models
	/**
	 * @param $tableName string
	 * @param $id
	 *
	 * @return array|mixed
	 */
	public function getModelData($tableName, $id)
	{
		$data = false;

		if (isset($this->models[$tableName][$id])) {
			// we already have de-serialized version in cache (it means it was already used by the Runtime)
			$data = $this->models[$tableName][$id];
		} else {
			$key = 'models/' . $tableName . '/' . $id;
			$modelDataJson = $this->get($key);
			$data = $modelDataJson ? json_decode($modelDataJson, true) : false;
		}

		return $data;
	}

	/**
	 * @param Model $model
	 */
	public function setModelData($model)
	{
		$data = $model->getExtendedData();
		$tableName = $model->getTableName();
		$id = Util::lavnn('id', $data, 0);
		if ($id > 0) {
			// save the value in cache
			$key = 'models/' . $tableName . '/' . $id;
			$this->set($key, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
			// also save in memory
			$this->models[$tableName][$id] = $data;
		}
	}

	/**
	 * @param $tableName
	 * @param $id
	 */
	public function deleteModelData($tableName, $id)
	{
		$key = 'models/' . $tableName . '/' . $id;
		self::delete($key);
	}

	/**
	 * @param $tableName
	 *
	 * @return int
	 */
	public function invalidateModel($tableName)
	{
		return self::deleteByPrefix('models/' . $tableName . '/');
	}

	/**
	 * @return int
	 */
	public function invalidateAllModels()
	{
		$cnt = 0;
		foreach (self::listKeysByPrefix('models/') as $modelFolder) {
			$cnt += count(array_map("unlink", glob("$modelFolder/*")));
			@rmdir($modelFolder);
		}
		return $cnt;
	}

//endregion

}
