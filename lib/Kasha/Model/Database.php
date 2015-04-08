<?php

namespace Kasha\Model;

use Kasha\Templar\TextProcessor;
use Temple\Util;

class Database
{
	use DatabaseTrait;

	/** @var Database */
	private static $instance;

	/** @var string */
	public $lastError;

	/** @var int */
	public $affectedRows;
	/** @var int */
	public $affectedRowsTotal;
	/** @var int */
	public $lastInsertId;

	public static $integerTypes = array('tinyint', 'smallint', 'mediumint', 'int', 'bigint');
	public static $floatTypes = array('float', 'double', 'decimal');
	public static $textTypes = array('tinytext', 'mediumtext', 'text', 'longtext');
	public static $blobTypes = array('tinyblob', 'mediumblob', 'blob', 'longblob');
	public static $stringTypes = array('char', 'varchar');
	public static $dateTypes = array('date', 'datetime', 'time', 'timestamp');

	public static function getBlobTypes()
	{
		return self::$blobTypes;
	}

	public static function getDateTypes()
	{
		return self::$dateTypes;
	}

	public static function getFloatTypes()
	{
		return self::$floatTypes;
	}

	public static function getIntegerTypes()
	{
		return self::$integerTypes;
	}

	public static function getStringTypes()
	{
		return self::$stringTypes;
	}

	public static function getTextTypes()
	{
		return self::$textTypes;
	}


	/** @var \mysqli */
	private $db;

	private $stats = array(
		'getRow' => 0,
		'getArray' => 0,
		'runQuery' => 0
	);

	/**
	 * @throws \Exception
	 * @return \mysqli
	 */
	public function getLink()
	{
		if (is_null($this->db)) {
			throw new \Exception(__METHOD__);
		}

		return $this->db;
	}

	public function connect($serverName, $userName, $password, $dbName, $port = 3306)
	{
		$link = new \mysqli($serverName, $userName, $password, $dbName, $port);
		if (!$link) {
			throw new \Exception("mysqli_connect");
		}

		$this->db = $link;
	}

	/**
	 * Database class is a singleton - reuse the same instance of an object
	 *
	 * @return Database|null
	 * @throws \Exception
	 */
	public static function getInstance()
	{
		if (is_null(self::$instance)) {
			self::$instance = new Database();
		}

		return self::$instance;
	}

	/**
	 * Returns result of INSERT query stored in the template
	 *
	 * @param $moduleName
	 * @param $templateName
	 * @param array $params
	 *
	 * @return int
	 */
	public function insert(
		$moduleName,
		$templateName,
		$params = array()
	)
	{
		$query = TextProcessor::doSqlTemplate($moduleName, $templateName, $params);

		return $this->runInsertQuery($query);
	}

	/**
	 * @param $query
	 *
	 * @return int|mixed
	 */
	public function runInsertQuery($query)
	{
		return $this->runQuery($query) > 0 ? $this->db->insert_id : -1;
	}

	/**
	 * Returns number of rows returned by SELECT query stored in the template
	 *
	 * @param $moduleName
	 * @param $templateName
	 * @param array $params
	 *
	 * @return int
	 */
	public function count(
		$moduleName,
		$templateName,
		$params = array()
	)
	{
		$query = TextProcessor::doSqlTemplate($moduleName, $templateName, $params);
		$result = $this->getRow("SELECT COUNT(*) AS cnt FROM ($query) basequery");

		return intval($result['cnt']);
	}

	/**
	 * Returns ID of identity field affected by INSERT query stored in the template
	 *
	 * @param $moduleName
	 * @param $templateName
	 * @param array $params
	 *
	 * @return int
	 */
	public function getNewID(
		$moduleName,
		$templateName,
		$params = array()
	)
	{
		$query = TextProcessor::doSqlTemplate($moduleName, $templateName, $params);
		$result = $this->runQuery($query);

		return ($result['result'] == 'OK') ? $this->db->insert_id : -1;
	}

	/**
	 * Prepares array from query execution results
	 * If offset and limit are given, already returns
	 *
	 * @param string $query
	 * @param array $paging
	 * @param array $blackList
	 *
	 * @return array
	 */
	public function getArray(
		$query,
		$paging = array(),
		$blackList = array()
	)
	{
		$output = array();
		$this->lastError = '';
		$this->affectedRows = 0;
		$this->affectedRowsTotal = 0;
		$result = $this->db->query($query);
		if (!$result) {
			$error = $this->lastError = $this->db->error();
		} else {
			$this->affectedRows = mysqli_num_rows($result);
			// @TODO port to mysqli
//			$this->affectedRowsTotal = intval(mysql_result(mysql_query("SELECT FOUND_ROWS()"), 0));
			if ($this->affectedRows > 0) {
				$skipped = Util::lavnn('offset', $paging, -1);
				$stopAfter = Util::lavnn('limit', $paging, -1) > 0 ? $paging['offset'] + $paging['limit'] : -1;
				$i = 0;
				while ($row = mysqli_fetch_assoc($result)) {
					if ($i > $skipped) {
						if (count($blackList) > 0) {
							foreach ($blackList as $fieldName) {
								if (isset($row[$fieldName])) {
									unset($row[$fieldName]);
								}
							}
						}
						$output[] = $row + array('_i_' => $i, '_mod2_' => $i++ % 2);
					}
					if ($stopAfter > 0 && $i > $stopAfter) {
						break;
					}
				}
				$row = null;
			}
		}
		$result = null;
		$this->stats['getArray']++;

		return $output;
	}

	/**
	 * Prepares associated array with one row data from query execution results
	 *
	 * @param $query
	 *
	 * @return array
	 */
	public function getRow($query)
	{
		$output = array();
		$this->lastError = '';
		$result = $this->db->query($query);
		$this->affectedRows = mysqli_num_rows($result);
		// @TODO port to mysqli
//			$this->affectedRowsTotal = intval(mysql_result(mysql_query("SELECT FOUND_ROWS()"), 0));
		if (!$result) {
			$error = $this->lastError = $this->db->error;
		} elseif ($this->affectedRows > 0) {
			$output = mysqli_fetch_assoc($result);
		}
		$result = null;
		$this->stats['getRow']++;

		return $output;
	}

	/**
	 * Generic execution of the query, wrapping possible exception.
	 * Returns -1 on failure, or number of affected fields on success.
	 *
	 * @param $query
	 *
	 * @return int
	 */
	public function runQuery($query)
	{
		$this->lastError = '';
		$result = $this->db->query($query);
		$this->stats['runQuery']++;
		if (!$result) {
			$error = $this->lastError = mysqli_error($this->db);
			return -1;
		} else {
			$n = $this->affectedRows = mysqli_affected_rows($this->db);
			//$this->lastInsertId = mysqli_insert_id($this->db);
			//$this->affectedRowsTotal = intval(mysqli_result(mysqli_query("SELECT FOUND_ROWS()"), 0));
			return $n;
		}
	}

	/**
	 * Reconstruct field metadata from mysql result
	 *
	 * @param $res
	 * @param $i
	 *
	 * @return array
	 */
	public static function getFieldMetadata($res, $i)
	{
		$metadata = mysqli_fetch_field($res, $i);

		return array(
			'name' => $metadata->name,
			'table' => $metadata->table,
			'type' => $metadata->type,
			'max_length' => $metadata->max_length,
			'not_null' => $metadata->not_null,
			'primary_key' => $metadata->primary_key,
			'unique_key' => $metadata->unique_key,
			'multiple_key' => $metadata->multiple_key,
			'numeric' => $metadata->numeric,
			'blob' => $metadata->blob,
			'unsigned' => $metadata->unsigned,
			'zerofill' => $metadata->zerofill,
			'quotes' => self::needQuotes($metadata->type),
			'nullable' => ($metadata->not_null == 0),
			'scale' => $metadata->max_length,
			'align' => self::getFieldAlignment($metadata->type)
		);
	}

	/**
	 * Collect metadata for all the fields that appear in the selection list of given $query
	 *
	 * @param string $query
	 *
	 * @return array
	 */
	public static function getQueryMetadata($query)
	{
		$output = array();

		$db = self::getInstance()->getLink();
		$result = Database::getInstance()->getLink()->query($query);
		if (!$result) {
			$error = self::$instance->lastError = $db->error;
		} else {
			for ($i = 0; $i < mysqli_num_fields($result); $i++) {
				$output[] = self::getFieldMetadata($result, $i);
			}
		}

		return $output;
	}

	public function getStats()
	{
		return $this->stats;
	}

	public static function removeQueryLimits($query)
	{
		$parts = Util::explode('LIMIT', $query, 2);

		return str_replace('SQL_CALC_FOUND_ROWS', '', $parts[0]);
	}

	/**
	 * Calculate field alignment for rendering tables
	 *
	 * @param $type
	 *
	 * @return string
	 */
	public static function getFieldAlignment($type)
	{
		if (in_array($type, self::$integerTypes) || in_array($type, self::$floatTypes)) {
			return 'right';
		} else {
			return 'left';
		}
	}

	/**
	 * Calculate the boolean flag of quoting requirement for the type
	 *
	 * @param $type
	 *
	 * @return bool
	 */
	public static function needQuotes($type)
	{
		return !in_array($type, self::$integerTypes) && !in_array($type, self::$floatTypes);
	}

}
