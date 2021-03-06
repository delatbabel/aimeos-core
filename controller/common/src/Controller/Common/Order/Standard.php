<?php

/**
 * @copyright Metaways Infosystems GmbH, 2014
 * @license LGPLv3, http://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2015
 * @package Controller
 * @subpackage Common
 */


namespace Aimeos\Controller\Common\Order;


/**
 * Common order controller methods.
 *
 * @package Controller
 * @subpackage Common
 */
class Standard
	implements \Aimeos\Controller\Common\Order\Iface
{
	private $context;


	/**
	 * Initializes the object.
	 *
	 * @param \Aimeos\MShop\Context\Item\Iface $context
	 */
	public function __construct( \Aimeos\MShop\Context\Item\Iface $context )
	{
		$this->context = $context;
	}


	/**
	 * Blocks the resources listed in the order.
	 *
	 * Every order contains resources like products or redeemed coupon codes
	 * that must be blocked so they can't be used by another customer in a
	 * later order. This method reduces the the stock level of products, the
	 * counts of coupon codes and others.
	 *
	 * It's save to call this method multiple times for one order. In this case,
	 * the actions will be executed only once. All subsequent calls will do
	 * nothing as long as the resources haven't been unblocked in the meantime.
	 *
	 * You can also block and unblock resources several times. Please keep in
	 * mind that unblocked resources may be reused by other orders in the
	 * meantime. This can lead to an oversell of products!
	 *
	 * @param \Aimeos\MShop\Order\Item\Iface $orderItem Order item object
	 */
	public function block( \Aimeos\MShop\Order\Item\Iface $orderItem )
	{
		$this->updateStatus( $orderItem, \Aimeos\MShop\Order\Item\Status\Base::STOCK_UPDATE, 1, -1 );
		$this->updateStatus( $orderItem, \Aimeos\MShop\Order\Item\Status\Base::COUPON_UPDATE, 1, -1 );
	}


	/**
	 * Frees the resources listed in the order.
	 *
	 * If customers created orders but didn't pay for them, the blocked resources
	 * like products and redeemed coupon codes must be unblocked so they can be
	 * ordered again or used by other customers. This method increased the stock
	 * level of products, the counts of coupon codes and others.
	 *
	 * It's save to call this method multiple times for one order. In this case,
	 * the actions will be executed only once. All subsequent calls will do
	 * nothing as long as the resources haven't been blocked in the meantime.
	 *
	 * You can also unblock and block resources several times. Please keep in
	 * mind that unblocked resources may be reused by other orders in the
	 * meantime. This can lead to an oversell of products!
	 *
	 * @param \Aimeos\MShop\Order\Item\Iface $orderItem Order item object
	 */
	public function unblock( \Aimeos\MShop\Order\Item\Iface $orderItem )
	{
		$this->updateStatus( $orderItem, \Aimeos\MShop\Order\Item\Status\Base::STOCK_UPDATE, 0, +1 );
		$this->updateStatus( $orderItem, \Aimeos\MShop\Order\Item\Status\Base::COUPON_UPDATE, 0, +1 );
	}


	/**
	 * Blocks or frees the resources listed in the order if necessary.
	 *
	 * After payment status updates, the resources like products or coupon
	 * codes listed in the order must be blocked or unblocked. This method
	 * cares about executing the appropriate action depending on the payment
	 * status.
	 *
	 * It's save to call this method multiple times for one order. In this case,
	 * the actions will be executed only once. All subsequent calls will do
	 * nothing as long as the payment status hasn't changed in the meantime.
	 *
	 * @param \Aimeos\MShop\Order\Item\Iface $orderItem Order item object
	 */
	public function update( \Aimeos\MShop\Order\Item\Iface $orderItem )
	{
		switch( $orderItem->getPaymentStatus() )
		{
			case \Aimeos\MShop\Order\Item\Base::PAY_DELETED:
			case \Aimeos\MShop\Order\Item\Base::PAY_CANCELED:
			case \Aimeos\MShop\Order\Item\Base::PAY_REFUSED:
			case \Aimeos\MShop\Order\Item\Base::PAY_REFUND:
				$this->unblock( $orderItem );
				break;

			case \Aimeos\MShop\Order\Item\Base::PAY_PENDING:
			case \Aimeos\MShop\Order\Item\Base::PAY_AUTHORIZED:
			case \Aimeos\MShop\Order\Item\Base::PAY_RECEIVED:
				$this->block( $orderItem );
				break;
		}
	}


	/**
	 * Adds a new status record to the order with the type and value.
	 *
	 * @param string $parentid Order ID
	 * @param string $type Status type
	 * @param string $value Status value
	 */
	protected function addStatusItem( $parentid, $type, $value )
	{
		$manager = \Aimeos\MShop\Factory::createManager( $this->getContext(), 'order/status' );

		$item = $manager->createItem();
		$item->setParentId( $parentid );
		$item->setType( $type );
		$item->setValue( $value );

		$manager->saveItem( $item );
	}


	/**
	 * Returns the product articles and their bundle IDs for the given article ID
	 *
	 * @param string $prodId Product ID of the article whose stock level changed
	 * @return array Associative list of article IDs as keys and the list of bundle IDs as values
	 */
	protected function getBundleMap( $prodId )
	{
		$productManager = \Aimeos\MShop\Factory::createManager( $this->context, 'product' );

		$search = $productManager->createSearch();
		$expr = array(
			$search->compare( '==', 'product.type.code', 'bundle' ),
			$search->compare( '==', 'product.lists.domain', 'product' ),
			$search->compare( '==', 'product.lists.refid', $prodId ),
			$search->compare( '==', 'product.lists.type.code', 'default' ),
		);
		$search->setConditions( $search->combine( '&&', $expr ) );
		$search->setSlice( 0, 0x7fffffff );

		return $productManager->searchItems( $search, array( 'product' ) );

		foreach( $bundleItems as $bundleId => $bundleItem )
		{
			foreach( $bundleItem->getRefItems( 'product', null, 'default' ) as $id => $item ) {
				$bundleMap[$id][] = $bundleId;
			}
		}
	}


	/**
	 * Returns the context item object.
	 *
	 * @return \Aimeos\MShop\Context\Item\Iface Context item object
	 */
	protected function getContext()
	{
		return $this->context;
	}


	/**
	 * Returns the last status item for the given order ID.
	 *
	 * @param string $parentid Order ID
	 * @return \Aimeos\MShop\Order\Item\Status\Iface|false Order status item or false if no item is available
	 */
	protected function getLastStatusItem( $parentid, $type )
	{
		$manager = \Aimeos\MShop\Factory::createManager( $this->getContext(), 'order/status' );

		$search = $manager->createSearch();
		$expr = array(
			$search->compare( '==', 'order.status.parentid', $parentid ),
			$search->compare( '==', 'order.status.type', $type ),
			$search->compare( '!=', 'order.status.value', '' ),
		);
		$search->setConditions( $search->combine( '&&', $expr ) );
		$search->setSortations( array( $search->sort( '-', 'order.status.ctime' ) ) );
		$search->setSlice( 0, 1 );

		$result = $manager->searchItems( $search );

		return reset( $result );
	}


	/**
	 * Returns the stock items for the given product IDs
	 *
	 * @param array $prodIds List of product IDs
	 * @param string $whcode Warehouse code the stock items must belong to
	 * @return \Aimeos\MShop\Product\Item\Stock\Iface[] Associative list of stock IDs as keys and stock items as values
	 */
	protected function getStockItems( array $prodIds, $whcode )
	{
		$stockManager = \Aimeos\MShop\Factory::createManager( $this->context, 'product/stock' );

		$search = $stockManager->createSearch();
		$expr = array(
			$search->compare( '==', 'product.stock.parentid', $prodIds ),
			$search->compare( '==', 'product.stock.warehouse.code', $whcode ),
		);
		$search->setConditions( $search->combine( '&&', $expr ) );
		$search->setSlice( 0, 0x7fffffff );

		return $stockManager->searchItems( $search );
	}


	/**
	 * Increases or decreses the coupon code counts referenced in the order by the given value.
	 *
	 * @param \Aimeos\MShop\Order\Item\Iface $orderItem Order item object
	 * @param integer $how Positive or negative integer number for increasing or decreasing the coupon count
	 */
	protected function updateCoupons( \Aimeos\MShop\Order\Item\Iface $orderItem, $how = +1 )
	{
		$context = $this->getContext();
		$manager = \Aimeos\MShop\Factory::createManager( $context, 'order/base/coupon' );
		$couponCodeManager = \Aimeos\MShop\Factory::createManager( $context, 'coupon/code' );

		$search = $manager->createSearch();
		$search->setConditions( $search->compare( '==', 'order.base.coupon.baseid', $orderItem->getBaseId() ) );

		$start = 0;

		$couponCodeManager->begin();

		try
		{
			do
			{
				$items = $manager->searchItems( $search );

				foreach( $items as $item ) {
					$couponCodeManager->increase( $item->getCode(), $how * 1 );
				}

				$count = count( $items );
				$start += $count;
				$search->setSlice( $start );
			}
			while( $count >= $search->getSliceSize() );

			$couponCodeManager->commit();
		}
		catch( \Exception $e )
		{
			$couponCodeManager->rollback();
			throw $e;
		}
	}


	/**
	 * Increases or decreases the stock level or the coupon code count for referenced items of the given order.
	 *
	 * @param \Aimeos\MShop\Order\Item\Iface $orderItem Order item object
	 * @param string $type Constant from \Aimeos\MShop\Order\Item\Status\Base, e.g. STOCK_UPDATE or COUPON_UPDATE
	 * @param string $status New status value stored along with the order item
	 * @param integer $value Number to increse or decrease the stock level or coupon code count
	 */
	protected function updateStatus( \Aimeos\MShop\Order\Item\Iface $orderItem, $type, $status, $value )
	{
		$statusItem = $this->getLastStatusItem( $orderItem->getId(), $type );

		if( $statusItem !== false && $statusItem->getValue() == $status ) {
			return;
		}

		if( $type == \Aimeos\MShop\Order\Item\Status\Base::STOCK_UPDATE ) {
			$this->updateStock( $orderItem, $value );
		} elseif( $type == \Aimeos\MShop\Order\Item\Status\Base::COUPON_UPDATE ) {
			$this->updateCoupons( $orderItem, $value );
		}

		$this->addStatusItem( $orderItem->getId(), $type, $status );
	}


	/**
	 * Increases or decreses the stock levels of the products referenced in the order by the given value.
	 *
	 * @param \Aimeos\MShop\Order\Item\Iface $orderItem Order item object
	 * @param integer $how Positive or negative integer number for increasing or decreasing the stock levels
	 */
	protected function updateStock( \Aimeos\MShop\Order\Item\Iface $orderItem, $how = +1 )
	{
		$context = $this->getContext();
		$stockManager = \Aimeos\MShop\Factory::createManager( $context, 'product/stock' );
		$manager = \Aimeos\MShop\Factory::createManager( $context, 'order/base/product' );

		$search = $manager->createSearch();
		$search->setConditions( $search->compare( '==', 'order.base.product.baseid', $orderItem->getBaseId() ) );

		$start = 0;

		$stockManager->begin();

		try
		{
			do
			{
				$items = $manager->searchItems( $search );

				foreach( $items as $item )
				{
					$stockManager->increase( $item->getProductCode(), $item->getWarehouseCode(), $how * $item->getQuantity() );

					switch( $item->getType() ) {
						case 'default':
							$this->updateStockBundle( $item->getProductId(), $item->getWarehouseCode() ); break;
						case 'select':
							$this->updateStockSelection( $item->getProductId(), $item->getWarehouseCode() ); break;
					}
				}

				$count = count( $items );
				$start += $count;
				$search->setSlice( $start );
			}
			while( $count >= $search->getSliceSize() );

			$stockManager->commit();
		}
		catch( \Exception $e )
		{
			$stockManager->rollback();
			throw $e;
		}
	}


	/**
	 * Updates the stock levels of bundles for a specific warehouse
	 *
	 * @param string $prodId Unique product ID
	 * @param string $whcode Unique warehouse code
	 */
	protected function updateStockBundle( $prodId, $whcode )
	{
		if( ( $bundleMap = $this->getBundleMap( $prodId ) ) === array() ) {
			return;
		}


		$bundleIds = $stock = array();

		foreach( $this->getStockItems( array_keys( $bundleMap ), $whcode ) as $stockItem )
		{
			if( isset( $bundleMap[$stockItem->getParentId()] ) && $stockItem->getStockLevel() !== null )
			{
				foreach( $bundleMap[$stockItem->getParentId()] as $bundleId )
				{
					if( isset( $stock[$bundleId] ) ) {
						$stock[$bundleId] = min( $stock[$bundleId], $stockItem->getStockLevel() );
					} else {
						$stock[$bundleId] = $stockItem->getStockLevel();
					}

					$bundleIds[$bundleId] = null;
				}
			}
		}

		if( empty( $stock ) ) {
			return;
		}


		$stockManager = \Aimeos\MShop\Factory::createManager( $this->context, 'product/stock' );

		foreach( $this->getStockItems( array_keys( $bundleIds ), $whcode ) as $item )
		{
			if( isset( $stock[$item->getParentId()] ) )
			{
				$item->setStockLevel( $stock[$item->getParentId()] );
				$stockManager->saveItem( $item );
			}
		}
	}


	/**
	 * Updates the stock levels of selection products for a specific warehouse
	 *
	 * @param string $prodId Unique product ID
	 * @param string $whcode Unique warehouse code
	 */
	protected function updateStockSelection( $prodId, $whcode )
	{
		$productManager = \Aimeos\MShop\Factory::createManager( $this->context, 'product' );
		$stockManager = \Aimeos\MShop\Factory::createManager( $this->context, 'product/stock' );

		$sum = 0; $selStockItem = null;
		$productItem = $productManager->getItem( $prodId, array( 'product' ) );

		$prodIds = array_keys( $productItem->getRefItems( 'product', 'default', 'default' ) );
		$prodIds[] = $prodId;

		foreach( $this->getStockItems( $prodIds, $whcode ) as $stockItem )
		{
			if( $stockItem->getParentId() == $prodId ) {
				$selStockItem = $stockItem;
			}

			$stock = $stockItem->getStockLevel();

			if( $stock === null ) {
				$sum = null;
			} elseif( $sum !== null && $stock > 0 ) {
				$sum += $stock;
			}
		}

		if( $selStockItem === null )
		{
			$whManager = \Aimeos\MShop\Factory::createManager( $this->context, 'product/stock/warehouse' );
			$whid = $whManager->findItem( $whcode )->getId();

			$selStockItem = $stockManager->createItem();
			$selStockItem->setWarehouseId( $whid );
			$selStockItem->setParentId( $prodId );
		}

		$selStockItem->setStockLevel( $sum );
		$stockManager->saveItem( $selStockItem, false );
	}
}
