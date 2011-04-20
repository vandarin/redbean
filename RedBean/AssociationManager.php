<?php
/**
 * RedBean Association
 * @file				RedBean/AssociationManager.php
 * @description	Manages simple bean associations.
 *
 * @author			Gabor de Mooij
 * @license			BSD
 *
 * (c) G.J.G.T. (Gabor) de Mooij
 * This source file is subject to the BSD/GPLv2 License that is bundled
 * with this source code in the file license.txt.
 */
class RedBean_AssociationManager extends RedBean_CompatManager {

	/**
	 * Specify what database systems are supported by this class.
	 * @var array $databaseSpecs
	 */
	protected $supportedSystems = array(
			  RedBean_CompatManager::C_SYSTEM_MYSQL => "5",
			  RedBean_CompatManager::C_SYSTEM_SQLITE=>"3",
			  RedBean_CompatManager::C_SYSTEM_POSTGRESQL=>"8"
	);

	/**
	 * @var RedBean_OODB
	 */
	protected $oodb;

	/**
	 * @var RedBean_Adapter_DBAdapter
	 */
	protected $adapter;

	/**
	 * @var RedBean_QueryWriter
	 */
	protected $writer;


	/**
	 * Constructor
	 * 
	 * @param RedBean_ToolBox $tools toolbox
	 */
	public function __construct( RedBean_ToolBox $tools ) {
		$this->oodb = $tools->getRedBean();
		$this->adapter = $tools->getDatabaseAdapter();
		$this->writer = $tools->getWriter();
	}
	/**
	 * Creates a table name based on a types array.
	 *
	 * @param array $types types
	 *
	 * @return string $table table
	 */
	public function getTable( $types ) {
		sort($types);
		return ( implode("_", $types) );
	}
	/**
	 * Associates two beans with eachother.
	 *
	 * @param RedBean_OODBBean $bean1 bean1
	 * @param RedBean_OODBBean $bean2 bean2
	 */
	public function associate(RedBean_OODBBean $bean1, RedBean_OODBBean $bean2) {
		$table = $this->getTable( array($bean1->getMeta("type") , $bean2->getMeta("type")) );
		$bean = $this->oodb->dispense($table);
		return $this->associateBeans( $bean1, $bean2, $bean );
	}


	/**
	 * Associates a pair of beans. This method associates two beans, no matter
	 * what types.
	 *
	 * @param RedBean_OODBBean $bean1 first bean
	 * @param RedBean_OODBBean $bean2 second bean
	 * @param RedBean_OODBBean $bean  base bean
	 *
	 * @return mixed $id either the link ID or null
	 */
	protected function associateBeans(RedBean_OODBBean $bean1, RedBean_OODBBean $bean2, RedBean_OODBBean $bean) {

		$idfield1 = $this->writer->getIDField($bean1->getMeta("type"));
		$idfield2 = $this->writer->getIDField($bean2->getMeta("type"));

		$property1 = $bean1->getMeta("type") . "_id";
		$property2 = $bean2->getMeta("type") . "_id";
		if ($property1==$property2) $property2 = $bean2->getMeta("type")."2_id";
		$bean->setMeta( "buildcommand.unique" , array( array( $property1, $property2 )));
		$this->oodb->store($bean1);
		$this->oodb->store($bean2);
		$bean->$property1 = $bean1->$idfield1;
		$bean->$property2 = $bean2->$idfield2;
		try {
			return $this->oodb->store( $bean );
		}
		catch(RedBean_Exception_SQL $e) {
			if (!$this->writer->sqlStateIn($e->getSQLState(),
			array(
			RedBean_QueryWriter::C_SQLSTATE_INTEGRITY_CONSTRAINT_VIOLATION
			))) throw $e;
		}
	}

	/**
	 * Experimental function, dont use yet...
	 * @param RedBean_OODBBean $bean
	 * @param array $otherBeans
	 * @return
	 */
	public function syncAssoc( RedBean_OODBBean $bean, $otherBeans = array()) {
		if (!count($otherBeans)) return;
		$firstBean = reset($otherBeans);
		$type = $firstBean->getMeta("type");
		$idfield = $this->writer->getIDField($firstBean);
		$relBeans = $this->oodb->batch($type, $this->related($bean, $type) );
		foreach($relBeans as $relBean) {
			$found=0;
			foreach($otherBeans as $k=>$otherBean) {
				if ($relBean->$idfield == $otherBean->$idfield) {
					//it's the same bean! has it changed?
					$relBeanSig = (implode(",",$relBean->export()));
					$otherBeanSig = implode(",",$otherBean->export());
					if ($relBeanSig==$otherBeanSig) {
						//nope not changed
						$found=1;
						unset($otherBeans[$k]);
					}
					else {
						//yupz, changed, store new bean..
						$this->oodb->store($otherBean);
						$found=1;
						unset($otherBeans[$k]);
					}
				}
			}
			if (!$found) {
				//bean is gone!
				$this->unassociate($relBean,$bean);
				$this->oodb->trash($relBean);
			}
		}
		foreach($otherBeans as $otherBean) {
			$this->associate($otherBean, $bean);
		}
	}

	
	/**
	 * @throws RedBean_Exception_SQL
	 * @param RedBean_OODBBean $bean
	 * @param  $type
	 * @param bool $getLinks
	 * @param bool $sql
	 * @return array
	 */
	public function related( RedBean_OODBBean $bean, $type, $getLinks=false, $sql=false) {
	$table = $this->getTable( array($bean->getMeta("type") , $type) );
		$idfield = $this->writer->getIDField($bean->getMeta("type"));
		if ($type==$bean->getMeta("type")) {// echo "<b>CROSS</b>";
			$type .= "2";
			$cross = 1;
		}
		else $cross=0;
		if (!$getLinks) $targetproperty = $type."_id"; else $targetproperty="id";

		$property = $bean->getMeta("type")."_id";
		try {



				$sqlFetchKeys = $this->writer->selectRecord(
					  $table,
					  array( $property => array( $bean->$idfield ) ),
					  $sql,
					  false

				);
				$sqlResult = array();
				foreach( $sqlFetchKeys as $row ) {
					$sqlResult[] = $row[$targetproperty];
				}
				if ($cross) {
					$sqlFetchKeys2 = $this->writer->selectRecord(
							  $table,
							  array( $targetproperty => array( $bean->$idfield ) ),
							  $sql,
							  false
					);
					foreach( $sqlFetchKeys2 as $row ) {
						$sqlResult[] = $row[$property];
					}
				}
			

			return $sqlResult; //or returns rows in case of $sql != empty

		}catch(RedBean_Exception_SQL $e) {

			if (!$this->writer->sqlStateIn($e->getSQLState(),
			array(
			RedBean_QueryWriter::C_SQLSTATE_NO_SUCH_COLUMN,
			RedBean_QueryWriter::C_SQLSTATE_NO_SUCH_TABLE)
			)) throw $e;

			return array();
		}
	}

	/**
	 * Breaks the association between two beans
	 *
	 * @param RedBean_OODBBean $bean1 first bean
	 * @param RedBean_OODBBean $bean2 second bean
	 */
	public function unassociate(RedBean_OODBBean $bean1, RedBean_OODBBean $bean2, $fast=null) {

		$this->oodb->store($bean1);
		$this->oodb->store($bean2);
		$table = $this->getTable( array($bean1->getMeta("type") , $bean2->getMeta("type")) );
		$idfield1 = $this->writer->getIDField($bean1->getMeta("type"));
		$idfield2 = $this->writer->getIDField($bean2->getMeta("type"));
		$type = $bean1->getMeta("type");
		if ($type==$bean2->getMeta("type")) { //echo "<b>CROSS</b>";
			$type .= "2";
			$cross = 1;
		}
		else $cross = 0;
		$property1 = $type."_id";
		$property2 = $bean2->getMeta("type")."_id";
		$value1 = (int) $bean1->$idfield1;
		$value2 = (int) $bean2->$idfield2;


		try {

			//$rows = $this->writer->selectAssocRec($table,$property1,$property2,$value1,$value2);
			$rows = $this->writer->selectRecord($table,array(
				$property1 => array($value1), $property2=>array($value2)),null,$fast
			);
			if ($cross) {
				$rows2 = $this->writer->selectRecord($table,array(
				$property2 => array($value1), $property1=>array($value2)),null,$fast
				);
				if ($fast) return;
				$rows = array_merge($rows,$rows2);
			}
			if ($fast) return;
			$beans = $this->oodb->convertToBeans($table,$rows);
			foreach($beans as $link) {
				$this->oodb->trash($link);
			}
		}catch(RedBean_Exception_SQL $e) {
			if (!$this->writer->sqlStateIn($e->getSQLState(),
			array(
			RedBean_QueryWriter::C_SQLSTATE_NO_SUCH_COLUMN,
			RedBean_QueryWriter::C_SQLSTATE_NO_SUCH_TABLE)
			)) throw $e;
		}

		return;


		try {
			$this->writer->deleteByCrit($table,array($property1=>$value1,$property2=>$value2));
			if ($cross) {
				$this->writer->deleteByCrit($table,array($property2=>$value1,$property1=>$value2));
			}
		}catch(RedBean_Exception_SQL $e) {
			if (!$this->writer->sqlStateIn($e->getSQLState(),
			array(
			RedBean_QueryWriter::C_SQLSTATE_NO_SUCH_COLUMN,
			RedBean_QueryWriter::C_SQLSTATE_NO_SUCH_TABLE)
			)) throw $e;
		}
	}
	/**
	 * Removes all relations for a bean
	 *
	 * @param RedBean_OODBBean $bean bean
	 * @param string           $type type
	 */
	public function clearRelations(RedBean_OODBBean $bean, $type) {
		$this->oodb->store($bean);
		$table = $this->getTable( array($bean->getMeta("type") , $type) );
		$idfield = $this->writer->getIDField($bean->getMeta("type"));
		if ($type==$bean->getMeta("type")) {
			$property2 = $type."2_id";
			$cross = 1;
		}
		else $cross = 0;
		$property = $bean->getMeta("type")."_id";
		try {
			$this->writer->selectRecord( $table, array($property=>array($bean->$idfield)),null,true);

			if ($cross) {
				$this->writer->selectRecord( $table, array($property2=>array($bean->$idfield)),null,true);

			}

			//$this->writer->deleteByCrit($table,array($property=>$bean->$idfield));
			//if ($cross) {
			//	$this->writer->deleteByCrit($table,array($property2=>$bean->$idfield));
			//}
		}catch(RedBean_Exception_SQL $e) {
			if (!$this->writer->sqlStateIn($e->getSQLState(),
			array(
			RedBean_QueryWriter::C_SQLSTATE_NO_SUCH_COLUMN,
			RedBean_QueryWriter::C_SQLSTATE_NO_SUCH_TABLE)
			)) throw $e;
		}
	}
	/**
	 * @deprecated
	 * Creates a 1 to Many Association
	 * If the association fails it throws an exception.
	 * @throws RedBean_Exception_SQL $failedToEnforce1toN
	 *
	 * @param RedBean_OODBBean $bean1 bean1
	 * @param RedBean_OODBBean $bean2 bean2
	 *
	 * @return RedBean_AssociationManager $chainable chainable
	 */
	public function set1toNAssoc(RedBean_OODBBean $bean1, RedBean_OODBBean $bean2) {
		$type = $bean1->getMeta("type");
		$this->clearRelations($bean2, $type);
		$this->associate($bean1, $bean2);
		if (count( $this->related($bean2, $type) )===1) {
			return $this;
		}
		else {
			throw new RedBean_Exception_SQL("Failed to enforce 1toN Relation for $type ");
		}
	}

}