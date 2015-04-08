<?php

namespace Kasha\Model;

use Kasha\Templar\TextProcessor;

trait DatabaseTrait
{
	/**
	 * Preview the SQL statement.
	 * Essentially, it is a shortcut to a function that prepares SQL statement from the template
	 *
	 * @param $moduleName
	 * @param $templateName
	 * @param array $params
	 *
	 * @return string
	 */
	public function preview(
		$moduleName,
		$templateName,
		$params = array()
	)
	{
		return TextProcessor::doSqlTemplate($moduleName, $templateName, $params);
	}

	/**
	 * Returns result (as an array) of SELECT query stored in the given template
	 *
	 * @param $moduleName
	 * @param $templateName
	 * @param array $params
	 *
	 * @return array
	 */
	public function sql2array(
		$moduleName,
		$templateName,
		$params = array()
	)
	{
		$query = TextProcessor::doSqlTemplate($moduleName, $templateName, $params);

		return $this->getArray($query);
	}

	/**
	 * Returns first result of SELECT query stored in the given template
	 *
	 * @param $moduleName
	 * @param $templateName
	 * @param array $params
	 *
	 * @return array
	 */
	public function sql2row(
		$moduleName,
		$templateName,
		$params = array()
	)
	{
		$query = TextProcessor::doSqlTemplate($moduleName, $templateName, $params);

		return $this->getRow($query);
	}

	/**
	 * Returns result of any INSERT/DELETE/UPDATE query stored in the template.
	 * Use it when particular operation is not that important, otherwise resort to insert()/update()/delete()
	 *
	 * @param $moduleName
	 * @param $templateName
	 * @param array $params
	 *
	 * @return int
	 */
	public function runsql(
		$moduleName,
		$templateName,
		$params = array()
	)
	{
		$query = TextProcessor::doSqlTemplate($moduleName, $templateName, $params);

		return $this->runQuery($query);
	}

	/**
	 * Returns result of UPDATE query stored in the template
	 *
	 * @param $moduleName
	 * @param $templateName
	 * @param array $params
	 *
	 * @return int
	 */
	public function update(
		$moduleName,
		$templateName,
		$params = array()
	)
	{
		$query = TextProcessor::doSqlTemplate($moduleName, $templateName, $params);

		return $this->runQuery($query);
	}

	/**
	 * Returns result of DELETE query stored in the template
	 *
	 * @param $moduleName
	 * @param $templateName
	 * @param array $params
	 *
	 * @return int
	 */
	public function delete(
		$moduleName,
		$templateName,
		$params = array()
	)
	{
		$query = TextProcessor::doSqlTemplate($moduleName, $templateName, $params);

		return $this->runQuery($query);
	}


}
