<?php

namespace Kasha\Model;

use Temple\Util;
use Kasha\Templar\Locator;
use Kasha\Templar\TextProcessor;
use Kasha\Model\Cache;

class Model
{
	/** @var string */
	protected $moduleName; // module name

	/** @var string */
	protected $tableName; // corresponding db entity

	/** @var array */
	protected $data = array();

	/** @var array */
	protected $fields = array();

	/** @var array */
	protected $changeBuffer = array();

	/** @var array */
	protected $lastData = array(); // the data snapshot before last insert/update/delete

	/** @var bool */
	protected $isCached = false;

	/** @var bool */
	protected $cacheOnLoad = false;

	/** @var bool */
	protected $temporal = false;

	/** @var int */
	protected $lastRowsFound = 0;

	/** @var bool */
	protected $isTrackable = true;

	/** @var bool */
	protected $allowInsertionId = false;

	/** @var bool */
	protected $allowHtml = false;

	/** @var string */
	protected $className;

	/** @var Cache */
	private $cache = null;

	/**
	 * @param $cache Cache
	 */
	public function setCache($cache)
	{
		$this->cache = $cache;
	}

	/**
	 * Returns instance of Model subclass, with actual data preloaded
	 *
	 * @param $id
	 *
	 * @return Model
	 */
	public static function getByID($id)
	{
		$className = get_called_class();
		/** @var $oModel Model */
		$oModel = new $className;

		return $oModel->load($id);
	}

	/**
	 * Returns instance of Model [for database table] or its subclass (if table name was not provided), with revision data preloaded
	 *
	 * @param $id
	 * @param \DateTime $revisionDate
	 *
	 * @return Model
	 */
	public static function getByRevision($id, \DateTime $revisionDate)
	{
		$className = get_called_class();
		$oModel = new $className;

		/** @var $oModel Model */
		return $oModel->load($id, $revisionDate);
	}

	public function __construct($tableName = '')
	{
		$this->setTableName($tableName);
	}

	/**
	 * Binds model with underlying db entity.
	 *  NB! When using setTableName in Model subclass, do not forget other setters!
	 *
	 * @param $tableName
	 */
	protected function setTableName($tableName)
	{
		$this->tableName = $tableName;
	}

	/**
	 * @param $moduleName string
	 *
	 * @return Model
	 */
	public function setModuleName($moduleName)
	{
		$this->moduleName = $moduleName;

		return $this;
	}

	/**
	 * Creates a copy of current Model object (also in the backend!). PHP4 compatible
	 *
	 * @param bool $asObject
	 *
	 * @return int|Model
	 */
	public function copy(
		$asObject = false
	) {
		$id = $this->insert($this->data);

		return $asObject ? self::getByID($id) : $id;
	}

	/**
	 * Returns true if model describes a temporal object
	 *
	 * @return bool
	 */
	public function isTemporal()
	{
		return $this->temporal;
	}

	/**
	 * Sets temporal flags on or off depending on the need.
	 *  Good usage is to set it to off when creating test amounts of data using scripts
	 *  (to keep memory usage as low as possible)
	 *
	 * @param bool $isTemporal
	 */
	public function setTemporal($isTemporal)
	{
		$this->temporal = $isTemporal;
	}

	/**
	 * Returns true if model describes a localizable object
	 *
	 * @return bool
	 */
	public function isLocalisable()
	{
		return $this->has('i18n');
	}

	/**
	 * @param boolean $isTrackable
	 */
	public function setIsTrackable($isTrackable)
	{
		$this->isTrackable = $isTrackable;
	}

	/**
	 * @return boolean
	 */
	public function getIsTrackable()
	{
		return $this->isTrackable;
	}

	/**
	 * @param boolean $allowInsertionId
	 */
	public function setAllowInsertionId($allowInsertionId)
	{
		$this->allowInsertionId = $allowInsertionId;
	}

	/**
	 * @return boolean
	 */
	public function getAllowInsertionId()
	{
		return $this->allowInsertionId;
	}

	/**
	 * @param boolean $allowHtml
	 */
	public function setAllowHtml($allowHtml)
	{
		$this->allowHtml = $allowHtml;
	}

	/**
	 * @return boolean
	 */
	public function getAllowHtml()
	{
		return $this->allowHtml;
	}

	/**
	 * Returns true if object contains some data
	 *
	 * @return bool
	 */
	public function isValid()
	{
		return isset($this->data['id']);
	}

	/**
	 * Invalidates an object (clears its data) and returns empty result
	 *
	 * @return Model
	 */
	public function invalidate()
	{
		$this->data = array();
		$this->isCached = false;

		return $this;
	}

	/**
	 * Invalidates cached version of model data (if that existed)
	 */
	public function invalidateCache()
	{
		if ($this->isCached && !is_null($this->cache)) {
			$this->cache->deleteModelData($this->tableName, $this->getID());
			$this->isCached = false; // force to re-regenerate cache when new snapshot is requested by getData()
		}
	}

	/**
	 * Returns the table name for this model
	 *
	 * @return string
	 */
	public function getTableName()
	{
		return $this->tableName;
	}

	/**
	 * Returns ID by which this object is stored in the database
	 *
	 * @return int
	 */
	public function getID()
	{
		return array_key_exists('id', $this->data) ? $this->data['id'] : -1;
	}

	/**
	 * Loads data into the object from the database, searching it by id
	 *
	 * @param $id
	 * @param \DateTime $revisionDate
	 *
	 * @throws \Exception
	 * @return Model
	 */
	public function load(
		$id,
		\DateTime $revisionDate = null
	) {
		if (!is_null($revisionDate) && $this->isTemporal()) {
			throw new \Exception(__METHOD__ . ' ' . ' @TODO implement fetching by revision');
		} else {
			if ($this->isCached && Util::lavnn('id', $this->data, 0) != 0) {
				// nothing to do, data is already loaded
			} else {
				// first, to ask from the cache
				//$cached = Cache::getModelItem($this, $id);
				$cached = false; // @TODO! Make compatible cache objects
				if (!$cached) {
					// load from the database
					$sqlFileName = get_class($this) . '_GetDetails';
					if (Locator::moduleFileExists($this->moduleName, "sql/$sqlFileName.sql")) {
						// tries to get details about the object using custom query, written for this model specifically
						$query = TextProcessor::doSqlTemplate($this->moduleName, $sqlFileName, array('id' => $id));
					} else {
						// uses standard query that is valid for any model
						$sqlParams = array('tableName' => $this->tableName, 'id' => $id);
						$query = TextProcessor::doText(file_get_contents(__DIR__ . "/Templates/GetDetails.sql"), $sqlParams);
					}
					$this->data = Database::getInstance()->getRow($query);
					// IMPORTANT do not save model data to cache at this stage by default.
					//  If done so, subclasses would miss info set in getData(). We require explicit cacheOnLoad = true for this
					if ($this->cacheOnLoad) {
						//Cache::setModelItem($this, $this->data);
						$this->isCached = true;
					}
				} else {
					// use cached version
					$this->data = $cached;
					$this->isCached = true;
				}
			}
		}

		return $this;
	}

	/**
	 * Loads data into the object from the given array
	 *
	 * @param array $data
	 *
	 * @return Model
	 */
	public function loadData(
		array $data = array()
	) {
		$this->data = $data;

		return $this;
	}

	/**
	 * Checks if model has a given field
	 *
	 * @param $field
	 *
	 * @return bool
	 */
	public function has($field)
	{
		return array_key_exists($field, $this->data);
	}

	/**
	 * Getter for any field.
	 * If value of the field was changed by set() method, changed value is returned even though it is not yet saved into database
	 *
	 * @param $field
	 * @param $default
	 *
	 * @return null
	 */
	public function get(
		$field,
        $default = null
	) {
		return array_key_exists($field, $this->changeBuffer) ?
			$this->changeBuffer[$field] :
			array_key_exists($field, $this->data) ?
				$this->data[$field] :
                $default;
	}

	/**
	 * Setter for any field that exists in data (object should be loaded before use)
	 *
	 * @param $field
	 * @param $value
	 */
	public function set(
		$field,
		$value
	) {
		if (array_key_exists($field, $this->changeBuffer)) {
			$this->changeBuffer[$field] = $value;
		}
	}

	/**
	 * Returns search results as array of objects
	 *
	 * @param array $searchParams
	 *
	 * @return array
	 */
	public function search(
		array $searchParams = array()
	) {
		$output = array();
		foreach ($this->getList($searchParams) as $data) {
			$className = get_called_class();
			/** @var $instance Model */
			$instance = new $className();
			$instance->loadData($data);
			$output[] = $instance;
		}

		return $output;
	}

	/**
	 * Checks if rows matching the conditions exist in the model
	 *
	 * @param array $searchParams array( 'joinClause', 'whereClause' )
	 *
	 * @return bool
	 */
	public function exists(
		array $searchParams = array()
	) {
		$db = Database::getInstance();

		$sqlFileName = get_class($this) . '_Exists';
		$where = $this->prepareWhere($searchParams, $this->getMetadata(), __FUNCTION__);
		$sqlParams = array(
			'tableName' => $this->tableName,
			'whereClause' => count($where) ? ('WHERE ' . join(' AND ', $where)) : ''
		);
		if (Locator::moduleFileExists($this->moduleName, "sql/$sqlFileName.sql")) {
			// We can override default search behaviour with custom SQL
			$query = TextProcessor::doSqlTemplate($this->moduleName, $sqlFileName, $searchParams);
		} else {
			$query = TextProcessor::doText(file_get_contents(__DIR__ . "/Templates/Exists.sql"), $sqlParams);
		}
		$existsResults = $db->getRow($query);

		return Util::lavnn('cnt', $existsResults, 0) > 0;
	}

	/**
	 * Returns search results as array of associative arrays
	 *
	 * @param array $searchParams
	 * @param int $limit
	 * @param string $ordering
	 *
	 * @return array
	 */
	public function getList(
		array $searchParams = array(),
		$limit = null,
		$ordering = ''
	) {
		$output = array();

		$columns = $this->getMetadata();
		$sqlFileName = get_called_class() . '_Search';
		if ($this->moduleName != '' && Locator::sqlTemplateFileExists($this->moduleName, "sql/$sqlFileName.sql")) {
			// We can override default search behaviour with custom SQL
			$output = Database::getInstance()->sql2array($this->moduleName, $sqlFileName, $searchParams);
		} else {
			$where = array();
			foreach ($searchParams as $key => $value) {
				if (is_null($value)) {
					$where[] = "$key IS NULL";
				} elseif (array_key_exists($key, $columns)) {
					$where[] = "$key = " . $this->prepareValue($value, $columns[$key]);
				} else {
					// @TODO Report unexpected column name
				}
			}
			$sqlParams = array(
				'tableName' => $this->tableName,
				'joinClause' => '',
				'whereClause' => count($where) ? 'WHERE ' . join(' AND ', $where) : '',
				'limitClause' => ($limit != null) ? 'LIMIT ' . $limit : ''
			);
			if ($ordering != '') {
				$sqlParams['orderClause'] = 'ORDER BY ' . $ordering;
			}
			$query = TextProcessor::doText(file_get_contents(__DIR__ . "/Templates/Search.sql"), $sqlParams);

			$output = Database::getInstance()->getArray($query);
			$this->lastRowsFound = Database::getInstance()->affectedRows;
		}

		return $output;
	}

	/**
	 * Return the first row that matches given criteria
	 *
	 * @param array $searchParams
	 *
	 * @return array|mixed
	 */
	public function getRow(
		array $searchParams = array()
	) {
		$results = $this->getList($searchParams, 1);

		return (count($results) > 0) ? array_shift($results) : array();
	}

	/**
	 * Returns whatever data is currently stored in the Model
	 *
	 * @return array
	 */
	public function getData()
	{
		return $this->data;
	}

    public static function translateData(&$data, $langCode) {
        if (!isset($data['_i18n_']) && isset($data['i18n'])) {
            // @TODO make JSON operation safe
            $data['_i18n_'] = json_decode($data['i18n'], true);
        }
        $i18n = Util::lavnn('_i18n_', $data, array());
        if (count($i18n) > 0) {
            if ($langCode != '' && array_key_exists($langCode, $data['_i18n_'])) {
                foreach($data['_i18n_'][$langCode] as $fieldName => $fieldValue) {
                    $data[$fieldName] = $fieldValue;
                }
            }
        }
    }

	/**
	 * Returns normal set of metadata for the table that is set in the constructor
	 *
	 * @return array|bool|mixed
	 */
	public function getMetadata()
	{
		// Try to get the model from the cache
		$model = Cache::get($this->tableName);
		if (!$model) {
			// Get the metadata and store it in the cache
			$model = $this->getTableMetadata($this->tableName);
			if (count($model) > 0) {
				Cache::set('metadata/' . $this->tableName, $model);
			} else {
				// @TODO report if needed
			}
		}

		return $model;
	}



	/**
	 * Checks if field (given as $columnInfo from model's metadata) is localisable.
	 *
	 * @param $columnInfo
	 *
	 * @return bool
	 */
	public function isFieldLocalisable($columnInfo)
	{
		$dbInstance = Database::getInstance();
		return in_array($columnInfo['type'], $dbInstance->getStringTypes()) || in_array($columnInfo['type'], $dbInstance->getTextTypes());
	}

	/**
	 * Returns an array of all localisable field names
	 *
	 * @return array
	 */
	public function listLocalisableFields()
	{
		$fields = array();
		foreach($this->getMetadata() as $columnInfo) {
			if ($this->isFieldLocalisable($columnInfo)) {
				$fields[] = $columnInfo['name'];
			}
		}

		return $fields;
	}

	/**
	 * Returns all localised texts for currently loaded row and in current session language
	 *
	 * @return mixed
	 */
	public function getCurrentLocalisation()
	{
		$localisations = $this->getLocalisations();

		return Util::lavnn($_SESSION['language']['code'], $localisations, array());
	}

	/**
	 * Returns all localisations for currently loaded row
	 *
	 * @return array
	 */
	public function getLocalisations()
	{
		$localisations = array();

		if ($this->has('i18n')) {
			$localisationJson = $this->get('i18n');
			if ($localisationJson != '') {
				//@TODO make this code safer - check for json decode result
				$localisations = json_decode($localisationJson, true);
			}
		}

		return $localisations;
	}



	public function getLocalisationDigest()
	{
		$localisations = array();
		foreach($this->getLocalisations() as $languageCode => $fields) {
			$translated = array();
			$untranslated = array();
			foreach($fields as $fieldName => $fieldValue) {
				if (trim($fieldValue) != '') {
					$translated[] = $fieldName;
				} else {
					$untranslated[] = $fieldName;
				}
			}
			$localisations[] = array(
				'language' => $languageCode,
				'translated' => join(' ', $translated),
				'untranslated' => join(' ', $untranslated),
			);
		}

		return $localisations;
	}

	/**
	 * Returns set of metadata for any query.
	 *  Should not be used whenever possible, because mysql reflection functions in PHP are deprecated and not reliable.
	 *
	 * @param $query
	 * @deprecated
	 *
	 * @return array
	 */
	protected function getMetadataForQuery($query)
	{
		$columns = array();
		foreach (Database::getQueryMetadata($query) as $c) {
			$c['params'] = array(); # TODO: find a way to pass parameters to reflected columns
			$columns[$c['name']] = $c;
		}

		return $columns;
	}

	/**
	 * Updates an object in the database.
	 * Passed changes are normally added to the ones accumulated in set() calls.
	 * When $ignorePreviousChanges is set to true, previously accumulated changes are ignored
	 * Returns id of the object if everything is fine, and -1 if update was cancelled or failed
	 *
	 * @param array $changes
	 * @param bool $ignorePreviousChanges
	 *
	 * @throws \Exception
	 *
	 * @return int
	 */
	public function update(
		$changes = array(),
		$ignorePreviousChanges = false
	) {
		// Check UPDATE privilege for this model
		if (!$this->checkAccess('update')) {
			return -1;
		}

		$db = Database::getInstance();

		// Revise the set of changed fields
		if ($ignorePreviousChanges) {
			if (count($changes) == 0) {
				throw new \Exception('Attempt to invalidate previous changes without providing new ones');
			}
			$this->changeBuffer = array();
		}
		if (count($changes) == 0) {
			//@TODO decide what to do when there are no changes. Needs to skip most of the code below
			//@TODO then also port to insert() and delete()
		}

		foreach ($changes as $field => $value) {
			$this->changeBuffer[$field] = $value;
		}

		// Get table description
		$columns = $this->getMetadata();

		// Check that ID is set
		if (!isset($this->data['id'])) {
			//$r->addWarning(get_class($this) . "->update() did not find an ID field");

			return -1;
		}
		$id = $this->data['id'];

		// Set automatically updated and editor fields if they exist on the model
		if (array_key_exists('updated', $columns)) {
			$this->changeBuffer['updated'] = gmdate('Y-m-d H:i:s', time());
		}
		if (array_key_exists('editor', $columns)) {
			//@TODO make it model setting (being able to redefine editor)
			if (!isset($this->changeBuffer['editor'])) {
				$this->changeBuffer['editor'] = Util::lavnn('id', Util::lavnn('user', $_SESSION, array()), 0);
			}
		}

		// Prepare fields for an update statement according to their type
		$fields = array(); $delta = array();
		foreach ($this->changeBuffer as $key => $value) {
			if ($key == 'id')  {
				if( $value != $id) {
					//print_r($this->changeBuffer);
					throw new \Exception('Attempt to provide different id for update function');
				}
				// otherwise, just ignore id field
			} elseif ($key == 'created') {
				// @TODO report if needed
			} elseif (array_key_exists($key, $columns)) {
				$preparedValue = $this->prepareValue($value, $columns[$key]);
				$fields[] = "$key = $preparedValue";
				$delta[$key] = is_null($value) ? null : $preparedValue;
			} else {
				// @TODO report if needed
			}
		}

		$sqlParams = array(
			'tableName' => $this->tableName,
			'fields' => join(', ', $fields),
			'id' => $id
		);
		$query = TextProcessor::doText(file_get_contents(__DIR__ . "/Templates/Update.sql"), $sqlParams);

		$this->lastData = $this->data;
		$id = $db->runQuery($query) > 0 ? $id : -1;
		$this->data = array_merge($this->lastData, $delta);

		// If update was successful and table is temporal, save current version
		if ($this->temporal && $id > 0) {
			$snapshot = $this->load($id);
			// @TODO copy History class
//			History::getInstance()->save($this->tableName , $id, 'update', $delta, $snapshot->getData());
		}

		// Run any post processing code that might be defined in the subclass
		$this->postProcess('update', $id);

		// Clean the change buffer (NB! do it at the very end to ensure that postProcess knows the changes)
		$this->changeBuffer = array();

		return $id;
	}

	/**
	 * Insert a new row into the database.
	 * Passed changes are normally added to the ones accumulated in set() calls.
	 * When $ignorePreviousChanges is set to true, previously accumulated changes are ignored
	 * On success, returns id of newly inserted row, on failure returns -1
	 *
	 * @param array $changes
	 * @param bool $ignorePreviousChanges
	 *
	 * @throws \Exception
	 *
	 * @return int
	 */
	public function insert(
		$changes = array(),
		$ignorePreviousChanges = false
	) {
		// Check INSERT privilege for this model
		if (!$this->checkAccess('insert')) {
			return -1;
		}

		// Revise the set of changed fields
		if ($ignorePreviousChanges) {
			if (count($changes) == 0) {
				throw new \Exception('Attempt to invalidate previous changes without providing new ones');
			}
			$this->changeBuffer = array();
		}

		foreach ($changes as $field => $value) {
			$this->changeBuffer[$field] = $value;
		}

		// Ignore any id that model user might have passed
		if (array_key_exists('id', $this->changeBuffer)) {
			unset($this->changeBuffer['id']);
		}

		// Get table description
		$columns = $this->getMetadata();

		// Set automatically created, updated and editor fields if they exist on the model
		$datetime = gmdate('Y-m-d H:i:s', time());
		if (array_key_exists('created', $columns)) {
			$this->changeBuffer['created'] = $datetime;
		}
		if (array_key_exists('updated', $columns)) {
			$this->changeBuffer['updated'] = $datetime;
		}
		if (array_key_exists('editor', $columns)) {
			//@TODO make it model setting (being able to redefine editor)
			if (!isset($this->changeBuffer['editor'])) {
				$this->changeBuffer['editor'] = Util::lavnn('id', Util::lavnn('user', $_SESSION, array()), 0);
			}
		}

		// Prepare field names and values for INSERT statement
		$fieldNames = $fieldValues = $delta = array();
		foreach ($this->changeBuffer as $key => $value) {
			if (array_key_exists($key, $columns)) {
				$fieldNames[] = $key;
				$preparedValue = $this->prepareValue($value, $columns[$key]);
				$fieldValues[] = $preparedValue;
				$delta[$key] = is_null($value) ? null : $preparedValue;
			} else {
				// @TODO report if needed
			}
		}

		// Check that all required fields are known
		foreach ($columns as $column) {
			if (!array_key_exists($column['name'], $this->changeBuffer)) {
				// If field is not nullable, either add default value to SQL or throw an error
				//@TODO
			}
		}

		// Execute query and get the new ID
		$sqlParams = array(
			'tableName' => $this->tableName,
			'fields' => join(', ', $fieldNames),
			'values' => join(', ', $fieldValues)
		);
		$query = TextProcessor::doText(file_get_contents(__DIR__ . "/Templates/Insert.sql"), $sqlParams);

		$this->lastData = array();
		$db = Database::getInstance();
		$id = $db->runInsertQuery($query);

		// If insert was successful and table is temporal, save current version
		$snapshot = $this->load($id);
		if ($this->temporal && $id > 0) {
			// @TODO copy History class
			//History::getInstance()->save($this->tableName, $id, 'insert', $delta, $snapshot->getData());
		}

		// Run any post processing code that might be defined in the subclass
		$this->postProcess('insert', $id);

		return $id;
	}

	/**
	 * Deletes an object from the database.
	 * On success, returns id of deleted row, on failure returns -1
	 *
	 * @param null $id
	 *
	 * @return int
	 */
	public function delete(
		$id = null
	) {
		$output = -1;

		// Check DELETE privilege for this model
		if (!$this->checkAccess('delete')) {
			return -1;
		}

		$db = Database::getInstance();

		if (is_null($id)) {
			$id = $this->get('id');
		}

		if (!is_null($id)) {
			// save the last known data snapshot - might be used in postProcess
			$this->lastData = $this->data;
			// Execute query
			$sqlParams = array('tableName' => $this->tableName, 'id' => $id);
			$query = TextProcessor::doText(file_get_contents(__DIR__ . "/Templates/Delete.sql"), $sqlParams);
			$affectedRows = $db->runQuery($query);

			// If delete was successful and table is temporal, save current version
			if ($this->temporal && $affectedRows > 0) {
				// @TODO copy History class
//				History::getInstance()->save($this->tableName, $id, 'delete', array(), array());
			}

			// Run any post processing code that might be defined in the subclass
			if ($affectedRows > 0) {
				$output = $id;
				$this->postProcess('delete', $id);
			}
		}

		return $output;
	}

	/**
	 * Prepares a value of a column in SQL query, taking into consideration nullability and requirement of quotes
	 *
	 * @param $value
	 * @param $columnData
	 *
	 * @return string
	 */
	public function prepareValue(
		$value,
		$columnData
	) {
		$dbInstance = Database::getInstance();
		if ($columnData['nullable'] && is_null($value)) {
			return 'NULL';
		} elseif(in_array($columnData['type'], $dbInstance->getDateTypes()) && $value == '') {
			return 'NULL';
		} elseif ($columnData['quotes']) {
			// sanitize all types from html - if markup is needed, model consumer needs to escape/unescape the values.
			if (!$this->allowHtml) {
				$value = strip_tags($value);
			}
			return "'" . str_replace("'", "''", $value) . "'";
		} elseif(in_array($columnData['type'], $dbInstance->getFloatTypes())) {
			return 0.0 + floatval(str_replace(',', '.', $value));
		} else {
			return 0 + intval(str_replace(',', '', $value));
		}
	}

	public function checkAccess($action) {
		// TODO implement some common mechanism to check model access
		return true;
	}

	/**
	 * Returns result of select query in one of supported format
	 *
	 * @param array $searchParameters
	 * @param array $options
	 *
	 * @return array
	 */
	public function select(
		$searchParameters = array(),
		$options = array()
	) {
		$format = Util::lavnn('format', $options, 'array');
		$sorting = Util::lavnn('sort', $options, '');

		$sqlFileName = get_class($this) . '_Search';

		if (Locator::moduleFileExists($this->moduleName, "sql/$sqlFileName.sql")) {
			// We can override default search behaviour with custom SQL
			$query = TextProcessor::doSqlTemplate($this->moduleName, $sqlFileName, $searchParameters);
		} else {
			$where = $this->prepareWhere($searchParameters, $this->getMetadata(), __METHOD__);
			$sqlParams = array(
				'tableName' => $this->tableName,
				'whereClause' => count($where) ? ('WHERE ' . join(' AND ', $where)) : '',
				'orderClause' => ($sorting != '') ? ('ORDER BY ' . $sorting) : ''
			);
			$query = TextProcessor::doText(file_get_contents(__DIR__ . "/Templates/Search.sql"), $sqlParams);
		}

		return $this->selectByQuery($query, $format);
	}

	public function selectByQuery($query)
	{
		$db = Database::getInstance();

		return $db->getArray($query);
	}

	/**
	 * @param array $ids
	 * @param string $format
	 *
	 * @return array
	 */
	public function selectByIds($ids, $format = 'array')
	{
		$sqlParams = array(
			'tableName' => $this->tableName,
			'whereClause' => 'WHERE id IN (' . join(', ', $ids) . ')'
		);
		$query = TextProcessor::doText(file_get_contents(__DIR__ . "/Templates/Search.sql"), $sqlParams);

		return $this->selectByQuery($query, $format);
	}

	protected function prepareWhere($conditions, $columns)
	{
		$where = array();
		foreach ($conditions as $key => $value) {
			if (is_null($value)) {
				$where[] = "$key IS NULL";
			} elseif (array_key_exists($key, $columns)) {
				$where[] = "$key = " . $this->prepareValue($value, $columns[$key]);
			} else {
				// @TODO report if needed
			}
		}

		return $where;
	}

	public function getRandomIds($recordCount, $searchParameters = array())
	{
		$where = $this->prepareWhere($searchParameters, $this->getMetadata(), __METHOD__);
		$sqlParams = array(
			'tableName' => $this->tableName,
			'recordCount' => $recordCount,
			'whereClause' => count($where) > 0 ? 'WHERE ' . join(' AND ', $where) : ''
		);
		$query = TextProcessor::doText(file_get_contents(__DIR__ . "/Templates/GetRandomIds.sql"), $sqlParams);

		return Database::getInstance()->getColumn($query, 'id');
	}

	public function selectRandom($count, $searchParameters = array(), $format = 'array')
	{
		return $this->selectByIds($this->getRandomIds($count, $searchParameters), $format);
	}

	public function dumpDebug()
	{
		print_r(array('data' => $this->data), 1);
	}

	/**
	 * Increases view counter for the model if it has needed fields
	 *
	 * @return int|resource
	 */
	public function countVisit()
	{
		$result = 0;
		if ($this->has('cnt_viewed') && $this->has('last_viewed')) {
			$sqlParams = array(
				'objectType' => $this->tableName,
				'objectId' => $this->getID()
			);
			$query = TextProcessor::doText(file_get_contents(__DIR__ . "/Templates/IncreaseViewCounter.sql"), $sqlParams);

			$result = mysql_query($query);
		}

		return $result;
	}

	/**
	 * function to be overloaded by all models that want special handling on load
	 * */
	protected function onLoad($id) {
		return;
	}

	//region postprocessing

	public function postProcess($operation, $id)
	{
		switch ($operation) {
			case 'insert':
				self::onInsert($id);
				break;
			case 'update':
				self::onUpdate($id);
				break;
			case 'delete':
				self::onDelete($id);
				break;
		}
	}

	public function onInsert($id)
	{

	}

	public function onUpdate($id)
	{

	}

	public function onDelete($id)
	{

	}

	//endregion

}
