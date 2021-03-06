<?php

$path = getcwd();
chdir(ROOT.'..');
require 'includes/application_top.php';
chdir($path);
require 'config.php';
require 'remove_order.php';
function getOpenOrdersUser()
{
	global $languages_id;
	$openOrderList = array();
	$sql = "select o.orders_id,  o.currency, ot.value, o.date_added as order_total from " . TABLE_ORDERS . " o left join " . TABLE_ORDERS_TOTAL . " ot on (o.orders_id = ot.orders_id) where ot.class = 'ot_total' and o.orders_status = '" . MODULE_PAYMENT_BITSHARES_UNPAID_STATUS_ID ."'";
	//$sql = "select orders_id, currency, order_total from ". TABLE_ORDERS." where orders_status = '" . MODULE_PAYMENT_BITSHARES_UNPAID_STATUS_ID ."'";
	$result = tep_db_query($sql);

	while ($orders_status = tep_db_fetch_array($result)) {
		$newOrder = array();
		$total = $orders_status['order_total'];
		$total = number_format((float)$total,2);		
		$newOrder['total'] = $total;
		$newOrder['asset'] = $orders_status['currency'];
		$newOrder['order_id'] = $orders_status['orders_id'];
		$newOrder['date_added'] = $orders_status['date_added'];
		array_push($openOrderList,$newOrder);    
	}
	
	return $openOrderList;
}
function isOrderCompleteUser($memo, $order_id)
{
	$sql = "select o.orders_id,  o.currency, ot.value as order_total from " . TABLE_ORDERS . " o left join " . TABLE_ORDERS_TOTAL . " ot on (o.orders_id = ot.orders_id) where ot.class = 'ot_total' and o.orders_status = '" . MODULE_PAYMENT_BITSHARES_PAID_STATUS_ID ."' and o.orders_id = '".$order_id."'";
	
	$result = tep_db_query($sql);
	while ($orders_status = tep_db_fetch_array($result)) {
			$total = $orders_status['order_total'];
			$total = number_format((float)$total,2);
			$asset = btsCurrencyToAsset($orders_status['currency']);
			$hash =  btsCreateEHASH(accountName,$order_id, $total, $asset, hashSalt);
			$memoSanity = btsCreateMemo($hash);		
			if($memoSanity === $memo)
			{	
				return TRUE;
			}
	
		
	}
	return FALSE;	
}
function doesOrderExistUser($memo, $order_id)
{

	$sql = "select o.orders_id,  o.currency, ot.value, o.date_added as order_total from " . TABLE_ORDERS . " o left join " . TABLE_ORDERS_TOTAL . " ot on (o.orders_id = ot.orders_id) where ot.class = 'ot_total' and o.orders_status = '" . MODULE_PAYMENT_BITSHARES_UNPAID_STATUS_ID ."' and o.orders_id = '".$order_id."'";
	
	$result = tep_db_query($sql);

	while ($orders_status = tep_db_fetch_array($result)) {
			$total = $orders_status['order_total'];
			$total = number_format((float)$total,2);
			$asset = btsCurrencyToAsset($orders_status['currency']);
			$hash =  btsCreateEHASH(accountName,$order_id, $total, $asset, hashSalt);
			$memoSanity = btsCreateMemo($hash);			
			if($memoSanity === $memo)
			{	
				$order = array();
				$order['order_id'] = $order_id;
				$order['total'] = $total;
				$order['asset'] = $asset;
				$order['memo'] = $memo;	
				$order['date_added'] = $orders_status['date_added'];
				return $order;
			}
		
	}
	return FALSE;
}

function completeOrderUser($order)
{
	
	$response = array();
	$transid = $order['trx_id'];			
	$res = tep_db_query("update " . TABLE_ORDERS . " set orders_status = '" . MODULE_PAYMENT_BITSHARES_PAID_STATUS_ID . "', last_modified = now() where orders_id = '" . $order['order_id'] . "'");
	if(!$res)
	{
		$response['error'] = 'Could not update order status to complete';
		return $response;
	} 	
	$sql_data_array = array('orders_id' => $order['order_id'],
                        'orders_status_id' => MODULE_PAYMENT_BITSHARES_PAID_STATUS_ID,
                        'date_added' => 'now()',
                        'customer_notified' => '0',
                        'comments' => 'Order Processed! [Transaction ID: ' . $transid . ']');
	tep_db_perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);
	$response['url'] = baseURL.'index.php?main_page=checkout_success';
	return $response;
}
function cancelOrderUser($order)
{
	
	$response = array();
	
	
  # update order status to reflect processed status:
  $res  = tep_db_query("update " . TABLE_ORDERS . " set orders_status = '" . MODULE_PAYMENT_BITSHARES_UNPAID_STATUS_ID . "', last_modified = now() where orders_id = '" . $order['order_id'] . "'");
	if(!$res)
	{
		$response['error'] = 'Could not update order status to cancelled';
		return $response;
	}   
  # update order status history:
  $sql_data_array = array('orders_id' => $order['order_id'],
                            'orders_status_id' => MODULE_PAYMENT_BITSHARES_UNPAID_STATUS_ID,
                            'date_added' => 'now()',
                            'customer_notified' => '0',
                            'comments' => 'Order cancelled by user');
  tep_db_perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);
    	
    	
  if(function_exists('tep_remove_order'))
  {
      tep_remove_order($order['order_id'], $restock = true);
  }
        
	$response['url'] = baseURL;
	return $response;
}
function cronJobUser()
{
	return 'Success!';	
}
function createOrderUser()
{

	$order_id    = $_REQUEST['order_id'];
	$asset = btsCurrencyToAsset($_REQUEST['code']);
	$total = number_format((float)$_REQUEST['total'],2);
	
	$hash =  btsCreateEHASH(accountName,$order_id, $total, $asset, hashSalt);
	$memo = btsCreateMemo($hash);
	$ret = array(
		'accountName'     => accountName,
		'order_id'     => $order_id,
		'memo'     => $memo
	);	
	
	return $ret;	
}

?>