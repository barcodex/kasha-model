<?php

namespace Kasha\Model;

use Kasha\Templar\TextProcessor;
use Temple\Util;

class Database
{
	use DatabaseTrait;

	/** @var Database */
	private static $instance;

	/** @var \mysqli */
	private $db;

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

	public function __construct() {
		self::$instance = $this;
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
			$error = $this->lastError = $this->db->error;
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
						$output[] = $row;
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

	public function getColumn($query, $column)
	{
		$output = array();
		$this->lastError = '';
		$this->affectedRows = 0;
		$this->affectedRowsTotal = 0;
		$result = $this->db->query($query);
		if (!$result) {
			$error = $this->lastError = $this->db->error;
		} else {
			$this->affectedRows = mysqli_num_rows($result);
			// @TODO port to mysqli
//			$this->affectedRowsTotal = intval(mysql_result(mysql_query("SELECT FOUND_ROWS()"), 0));
			if ($this->affectedRows > 0) {
				$i = 0;
				while ($row = mysqli_fetch_assoc($result)) {
					if (array_key_exists($column, $row)) {
						$output[] = $row[$column];
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
			$error = $this->lastError = $this->db->error;
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
	 * @param $fieldMetadata
	 *
	 * @return array
	 */
	public static function getFieldMetadata($fieldMetadata)
	{
		$flags = $fieldMetadata->flags;
		$convertedType = self::convertMysqliType($fieldMetadata->type);

		return array(
			'name' => $fieldMetadata->name,
			'table' => $fieldMetadata->table,
			'type' => $convertedType,
			'max_length' => $fieldMetadata->max_length,
			'not_null' => ($flags & MYSQLI_NOT_NULL_FLAG != 0) ? 1 : 0,
			'primary_key' => ($flags & MYSQLI_PRI_KEY_FLAG != 0) ? 1 : 0,
			'unique_key' => ($flags & MYSQLI_UNIQUE_KEY_FLAG != 0) ? 1 : 0,
			'multiple_key' => ($flags & MYSQLI_MULTIPLE_KEY_FLAG != 0) ? 1 : 0,
			'numeric' => ($flags & MYSQLI_NUM_FLAG != 0) ? 1 : 0,
			'blob' => ($flags & MYSQLI_BLOB_FLAG != 0) ? 1 : 0,
			'unsigned' => ($flags & MYSQLI_UNSIGNED_FLAG != 0) ? 1 : 0,
			'zerofill' => ($flags & MYSQLI_ZEROFILL_FLAG != 0) ? 1 : 0,
			'quotes' => self::needQuotes($fieldMetadata->type),
			'nullable' => ($flags & MYSQLI_NOT_NULL_FLAG) ? 0 : 1,
			'scale' => $fieldMetadata->max_length,
			'align' => self::getFieldAlignment($fieldMetadata->type)
		);
	}

	public static function getTableMetadata($tableName)
	{
		$columns = array();
		$instance = self::getInstance();

		foreach ($instance->getArray("DESCRIBE $tableName") as $columnInfo) {
			$name = $columnInfo['Field'];
			$typeInfo = $instance->parseTypeInfo($columnInfo['Type']);
			$columns[$name] = array(
				'name' => $name,
				'table' => $tableName,
				'type' => $typeInfo['type'],
				'length' => Util::lavnn('length', $typeInfo, ''),
				'not_null' => 0 + ($columnInfo['Null'] == 'NO'),
				'primary_key' => 0 + ($columnInfo['Key'] == 'PRI'),
				'auto_increment' => 0 + ($columnInfo['Extra'] == 'auto_increment'),
				'unique_key' => 0 + ($columnInfo['Key'] == 'UNI'),
				'multiple_key' => 0 + ($columnInfo['Key'] == 'MUL'),
				'fulltext_index' => 0 + ($columnInfo['Key'] == 'TXT'), //@TODO find the way to check it
				'numeric' => 0 + in_array($typeInfo['type'], $instance->getIntegerTypes()) + in_array($typeInfo['type'], $instance->getFloatTypes()),
				'blob' => 0 + in_array($typeInfo['type'], $instance->getTextTypes()) + in_array($typeInfo['type'], $instance->getBlobTypes()),
				'unsigned' => 0 + $typeInfo['unsigned'],
				'quotes' => 0 + Database::needQuotes($typeInfo['type']),
				'nullable' => 0 + ($columnInfo['Null'] == 'YES'),
				'scale' => Util::lavnn('length', $typeInfo, ''),
				'align' => Database::getFieldAlignment($typeInfo['type']),
				'enum' => Util::lavnn('enum', $typeInfo, ''),
				'default' => $columnInfo['Default'],
			);
		}

		return $columns;
	}

	/**
	 * Parse a type definition string that MySql returns about the field, e.g. "int(10) unsigned"
	 * @param $type
	 *
	 * @return array
	 */
	private function parseTypeInfo($type)
	{
		$output = array();
		$typeInfo = explode(' ', $type);
		if (count($typeInfo) > 0) {
			$typeInfoParts = explode('(', array_shift($typeInfo));
			if (count($typeInfoParts) > 0) {
				$output['type'] = $typeInfoParts[0];
				if (count($typeInfoParts) == 2) {
					if ($output['type'] == 'enum') {
						$output['enum'] = explode(',', str_replace(')', '', $typeInfoParts[1]));
					} else {
						$output['length'] = intval($typeInfoParts[1]);
					}
				}
			}
		}
		// if there are parenthesis after the type name, they contain either length or enum values
		// there are some useful data coming in $typeInfo that is still left after shifting out the type name
		$output['unsigned'] = 0 + in_array('unsigned', $typeInfo);

		return $output;
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
		$result = $db->query($query);
		if (!$result) {
			$error = self::$instance->lastError = $db->error;
		} else {
			$fields = $result->fetch_fields();
			foreach ($fields as $field) {
				print_r($field);
				$output[] = self::getFieldMetadata($field);
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

	public static function convertMysqliType($mysqliType)
	{
		$result = '';
		// @TODO finish 100%
		switch($mysqliType) {
			case MYSQLI_TYPE_TINY: $result = 'tinyint'; break;
			case 2: $result = 'smallint'; break;
			case MYSQLI_TYPE_LONG: $result = 'int'; break;
			case MYSQLI_TYPE_INT24: $result = 'mediumint'; break;
			case MYSQLI_TYPE_BIT: $result = 'bit'; break;
			case MYSQLI_TYPE_BLOB: $result = 'text'; break;
			case MYSQLI_TYPE_STRING: $result = 'char'; break;
			case MYSQLI_TYPE_DATE: $result = 'date'; break;
			case MYSQLI_TYPE_TIME: $result = 'time'; break;
			case MYSQLI_TYPE_DATETIME: $result = 'datetime'; break;
			case MYSQLI_TYPE_TIMESTAMP: $result = 'timestamp'; break;
			case 246 /* MYSQLI_TYPE_DECIMAL */: $result = 'decimal'; break;
			case MYSQLI_TYPE_FLOAT: $result = 'float'; break;
			case MYSQLI_TYPE_DOUBLE: $result = 'double'; break;
			case MYSQLI_TYPE_LONG_BLOB: $result = 'text'; break;
		}

		return $result;
	}

}
