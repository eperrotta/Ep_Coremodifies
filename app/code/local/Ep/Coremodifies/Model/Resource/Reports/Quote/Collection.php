<?php

/**
 * EP Coremodifies - Basic Magento module aiming to collect all the bugfixes affecting Magento 1
 *
 * Mage_Reports_Model_Resource_Quote_Collection has a bug when displaying the abandoned cart report
 * since it assumes the customer middle name being mandatory.
 * Changing the join type to joinLeft on the cust_fname column the report displays all the carts where
 * the Middle name wasn't fulfilled.
 *
 * @category Mage
 * @package  Ep_Coremodifies
 * @author   Enzo Perrotta
 * @since    2016-11-06
 */
class Ep_Coremodifies_Model_Resource_Reports_Quote_Collection extends Mage_Reports_Model_Resource_Quote_Collection
{

	/**
	 * Add customer data
	 *
	 * @param unknown_type $filter
	 * @return Mage_Reports_Model_Resource_Quote_Collection
	 */
	public function addCustomerData($filter = null)
	{
		$customerEntity = Mage::getResourceSingleton('customer/customer');
		$attrFirstname = $customerEntity->getAttribute('firstname');
		$attrFirstnameId = (int)$attrFirstname->getAttributeId();
		$attrFirstnameTableName = $attrFirstname->getBackend()->getTable();

		$attrLastname = $customerEntity->getAttribute('lastname');
		$attrLastnameId = (int)$attrLastname->getAttributeId();
		$attrLastnameTableName = $attrLastname->getBackend()->getTable();

		$attrMiddlename = $customerEntity->getAttribute('middlename');
		$attrMiddlenameId = (int)$attrMiddlename->getAttributeId();
		$attrMiddlenameTableName = $attrMiddlename->getBackend()->getTable();

		$attrEmail = $customerEntity->getAttribute('email');
		$attrEmailTableName = $attrEmail->getBackend()->getTable();

		$adapter = $this->getSelect()->getAdapter();
		$customerName = $adapter->getConcatSql(array('cust_fname.value', 'cust_mname.value', 'cust_lname.value',), ' ');
		$this->getSelect()
			->joinInner(
				array('cust_email' => $attrEmailTableName),
				'cust_email.entity_id = main_table.customer_id',
				array('email' => 'cust_email.email')
			)
			->joinInner(
				array('cust_fname' => $attrFirstnameTableName),
				implode(' AND ', array(
					'cust_fname.entity_id = main_table.customer_id',
					$adapter->quoteInto('cust_fname.attribute_id = ?', (int)$attrFirstnameId),
				)),
				array('firstname' => 'cust_fname.value')
			)
			->joinLeft(
				array('cust_mname' => $attrMiddlenameTableName),
				implode(' AND ', array(
					'cust_mname.entity_id = main_table.customer_id',
					$adapter->quoteInto('cust_mname.attribute_id = ?', (int)$attrMiddlenameId),
				)),
				array('middlename' => 'cust_mname.value')
			)
			->joinInner(
				array('cust_lname' => $attrLastnameTableName),
				implode(' AND ', array(
					'cust_lname.entity_id = main_table.customer_id',
					$adapter->quoteInto('cust_lname.attribute_id = ?', (int)$attrLastnameId)
				)),
				array(
					'lastname' => 'cust_lname.value',
					'customer_name' => $customerName
				)
			);

		$this->_joinedFields['customer_name'] = $customerName;
		$this->_joinedFields['email'] = 'cust_email.email';

		if ($filter) {
			if (isset($filter['customer_name'])) {
				$likeExpr = '%' . $filter['customer_name'] . '%';
				$this->getSelect()->where($this->_joinedFields['customer_name'] . ' LIKE ?', $likeExpr);
			}
			if (isset($filter['email'])) {
				$likeExpr = '%' . $filter['email'] . '%';
				$this->getSelect()->where($this->_joinedFields['email'] . ' LIKE ?', $likeExpr);
			}
		}

		return $this;
	}

}
