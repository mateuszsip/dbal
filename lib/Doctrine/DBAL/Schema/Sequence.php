<?php

namespace Doctrine\DBAL\Schema;

use Doctrine\DBAL\Schema\Visitor\Visitor;

/**
 * Sequence structure.
 *
 * @link   www.doctrine-project.org
 * @since  2.0
 * @author Benjamin Eberlei <kontakt@beberlei.de>
 */
class Sequence extends AbstractAsset
{
    /**
     * @var integer
     */
    protected $allocationSize = 1;

    /**
     * @var integer
     */
    protected $initialValue = 1;

    /**
     * @var integer|null
     */
    protected $cache = null;

    /**
     * @param string       $name
     * @param integer      $allocationSize
     * @param integer      $initialValue
     * @param integer|null $cache
     */
    public function __construct($name, $allocationSize = 1, $initialValue = 1, $cache = null)
    {
        $this->setName($name);
        $this->allocationSize = is_numeric($allocationSize) ? $allocationSize : 1;
        $this->initialValue = is_numeric($initialValue) ? $initialValue : 1;
        $this->cache = $cache;
    }

    /**
     * @return integer
     */
    public function getAllocationSize()
    {
        return $this->allocationSize;
    }

    /**
     * @return integer
     */
    public function getInitialValue()
    {
        return $this->initialValue;
    }

    /**
     * @return integer|null
     */
    public function getCache()
    {
        return $this->cache;
    }

    /**
     * @param integer $allocationSize
     *
     * @return \Doctrine\DBAL\Schema\Sequence
     */
    public function setAllocationSize($allocationSize)
    {
        $this->allocationSize = is_numeric($allocationSize) ? $allocationSize : 1;

        return $this;
    }

    /**
     * @param integer $initialValue
     *
     * @return \Doctrine\DBAL\Schema\Sequence
     */
    public function setInitialValue($initialValue)
    {
        $this->initialValue = is_numeric($initialValue) ? $initialValue : 1;

        return $this;
    }

    /**
     * @param integer $cache
     *
     * @return \Doctrine\DBAL\Schema\Sequence
     */
    public function setCache($cache)
    {
        $this->cache = $cache;

        return $this;
    }

    /**
     * Checks if this sequence is an autoincrement sequence for a given table.
     *
     * This is used inside the comparator to not report sequences as missing,
     * when the "from" schema implicitly creates the sequences.
     *
     * @param \Doctrine\DBAL\Schema\Table $table
     *
     * @return boolean
     */
    public function isAutoIncrementsFor(Table $table)
    {
        if ( ! $table->hasPrimaryKey()) {
            return false;
        }

        $pkColumns = $table->getPrimaryKey()->getColumns();

        if (count($pkColumns) != 1) {
            return false;
        }

        $column = $table->getColumn($pkColumns[0]);

        if ( ! $column->getAutoincrement()) {
            return false;
        }

        $sequenceName      = $this->getShortestName($table->getNamespaceName());
        $tableName         = $table->getShortestName($table->getNamespaceName());
        $tableSequenceName = sprintf('%s_%s_seq', $tableName, $column->getShortestName($table->getNamespaceName()));

        return $tableSequenceName === $sequenceName;
    }

    /**
     * @param \Doctrine\DBAL\Schema\Visitor\Visitor $visitor
     *
     * @return void
     */
    public function visit(Visitor $visitor)
    {
        $visitor->acceptSequence($this);
    }
}
