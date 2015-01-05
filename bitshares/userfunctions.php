<?php

$path = getcwd();
chdir(ROOT.'..');
require 'includes/application_top.php';
chdir($path);
require 'config.php';
require 'remove_order.php';
function getOpenOrdersHelper()
{
	global $languages_id;
	$openOrderList = array();
	$sql = "select o.orders_id,  o.currency, ot.value as order_total from " . TABLE_ORDERS . " o left join " . TABLE_ORDERS_TOTAL . " ot on (o.orders_id = ot.orders_id) where ot.class = 'ot_total' and o.orders_status = '" . MODULE_PAYMENT_BITSHARES_UNPAID_STATUS_ID ."'";
	//$sql = "select orders_id, currency, order_total from ". TABLE_ORDERS." where orders_status = '" . MODULE_PAYMENT_BITSHARES_UNPAID_STATUS_ID ."'";
	$result = tep_db_query($sql);

	while ($orders_status = tep_db_fetch_array($result)) {
		$newOrder = array();
		$total = $orders_status['order_total'];
		$total = number_format((float)$total,2);		
		$newOrder['total'] = $total;
		$newOrder['currency_code'] = $orders_status['currency'];
		$newOrder['order_id'] = $orders_status['orders_id'];
		$newOrder['date_added'] = 0;
		array_push($openOrderList,$newOrder);    
	}
	
	return $openOrderList;
}
function isOrderComplete($memo, $order_id)
{
	global $accountName;
	global $hashSalt;
	$sql = "select o.orders_id,  o.currency, ot.value as order_total from " . TABLE_ORDERS . " o left join " . TABLE_ORDERS_TOTAL . " ot on (o.orders_id = ot.orders_id) where ot.class = 'ot_total' and o.orders_status = '" . MODULE_PAYMENT_BITSHARES_PAID_STATUS_ID ."'";
	//$sql = "select orders_id, currency, order_total from " .TABLE_ORDERS. " where orders_status = '" . MODULE_PAYMENT_BITSHARES_PAID_STATUS_ID ."' and orders_id = '".$order_id."'";
	$result = tep_db_query($sql);
	while ($orders_status = tep_db_fetch_array($result)) {
			$total = $orders_status['order_total'];
			$total = number_format((float)$total,2);
			$asset = btsCurrencyToAsset($orders_status['currency']);
			$hash =  btsCreateEHASH($accountName,$order_id, $total, $asset, $hashSalt);
			$memoSanity = btsCreateMemo($hash);		
			if($memoSanity === $memo)
			{	
				return TRUE;
			}
	
		
	}
	return FALSE;	
}
function doesOrderExist($memo, $order_id)
{
	global $accountName;
	global $hashSalt;
	$sql = "select o.orders_id,  o.currency, ot.value as order_total from " . TABLE_ORDERS . " o left join " . TABLE_ORDERS_TOTAL . " ot on (o.orders_id = ot.orders_id) where ot.class = 'ot_total' and o.orders_status = '" . MODULE_PAYMENT_BITSHARES_UNPAID_STATUS_ID ."' and o.orders_id = '".$order_id."'";
	//$sql = "select orders_id, currency, order_total from ". TABLE_ORDERS. " where orders_status = '" . MODULE_PAYMENT_BITSHARES_UNPAID_STATUS_ID ."' and orders_id = '".$order_id."'";
	$result = tep_db_query($sql);

	while ($orders_status = tep_db_fetch_array($result)) {
			$total = $orders_status['order_total'];
			$total = number_format((float)$total,2);
			$asset = btsCurrencyToAsset($orders_status['currency']);
			$hash =  btsCreateEHASH($accountName,$order_id, $total, $asset, $hashSalt);
			$memoSanity = btsCreateMemo($hash);			
			if($memoSanity === $memo)
			{	
				$order = array();
				$order['order_id'] = $order_id;
				$order['total'] = $total;
				$order['asset'] = $asset;
				$order['memo'] = $memo;	
				
				return $order;
			}
		
	}
	return FALSE;
}

function completeOrderUser($memo, $order_id)
{
	global $baseURL;
	global $accountName;
	global $rpcUser;
	global $rpcPass;
	global $rpcPort;
	global $demoMode;
	global $hashSalt;
	$orderArray = getOrder($memo, $order_id);
	if(count($orderArray) <= 0)
	{
	  $ret = array();
	  $ret['error'] = 'Could not find this order in the system, please review the Order ID and Memo';
	  return $ret;
	}

	if ($orderArray[0]['order_id'] !== $order_id) {
		$ret = array();
		$ret['error'] = 'Invalid Order ID';
		return $ret;
	}
	$demo = FALSE;
	if($demoMode === "1" || $demoMode === 1 || $demoMode === TRUE || $demoMode === "true")
	{
		$demo = TRUE;
	}
	$response = btsVerifyOpenOrders($orderArray, $accountName, $rpcUser, $rpcPass, $rpcPort, $hashSalt, $demo);

	if(array_key_exists('error', $response))
	{
	  $ret = array();
	  $ret['error'] = 'Could not verify order. Please try again';
	  return $ret;
	}
	$ret = array();	
	$ret['url'] = $baseURL;
	foreach ($response as $responseOrder) {
		switch($responseOrder['status'])
		{
			case 'complete':
			case 'overpayment':   
				$transid = $responseOrder['trx_id'];			
				tep_db_query("update " . TABLE_ORDERS . " set orders_status = '" . MODULE_PAYMENT_BITSHARES_PAID_STATUS_ID . "', last_modified = now() where orders_id = '" . $responseOrder['order_id'] . "'");
				$sql_data_array = array('orders_id' => $responseOrder['order_id'],
                              'orders_status_id' => MODULE_PAYMENT_BITSHARES_PAID_STATUS_ID,
                              'date_added' => 'now()',
                              'customer_notified' => '0',
                              'comments' => 'Order Processed! [Transaction ID: ' . $transid . ']');
				tep_db_perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);
				$ret['url'] = $baseURL.'index.php?main_page=checkout_success';				
				break;		
			default:
				break;	    
		}		 
	}
	
	return $ret;
}
function cancelOrderUser($memo, $order_id)
{
	global $baseURL;
	$response = array();
	$response['url'] = $baseURL;
	
	$orderArray = getOrder($memo, $order_id);
	
	if(count($orderArray) <= 0)
	{
	  return $response;
	}
	if ($orderArray[0]['order_id'] !== $order_id)
	{
	  return $response;
	}
    # update order status to reflect processed status:
    tep_db_query("update " . TABLE_ORDERS . " set orders_status = '" . MODULE_PAYMENT_BITSHARES_UNPAID_STATUS_ID . "', last_modified = now() where orders_id = '" . $order_id . "'");
    # update order status history:
    $sql_data_array = array('orders_id' => $order_id,
                              'orders_status_id' => MODULE_PAYMENT_BITSHARES_UNPAID_STATUS_ID,
                              'date_added' => 'now()',
                              'customer_notified' => '0',
                              'comments' => 'Order cancelled by user');
    tep_db_perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);
      	
      	
    if(function_exists('tep_remove_order'))
    {
        tep_remove_order($order_id, $restock = true);
    }
        
	$response['url'] = $baseURL;
	return $response;
}
function cronJobUser()
{
	global $cronToken;
	global $baseURL;
	global $accountName;
	global $rpcUser;
	global $rpcPass;
	global $rpcPort;
	global $demoMode;
	global $hashSalt;

	$orderArray = getOpenOrdersHelper();
	if(count($orderArray) <= 0)
	{
	  $ret = array();
	  $ret['error'] = 'No open orders found!';
	  return $ret;
	}

	$demo = FALSE;
	if($demoMode === "1" || $demoMode === 1 || $demoMode === TRUE || $demoMode === "true")
	{
		$demo = TRUE;
	}
	$response = btsVerifyOpenOrders($orderArray, $accountName, $rpcUser, $rpcPass, $rpcPort, $hashSalt, $demo);

	if(array_key_exists('error', $response))
	{
	  $ret = array();
	  $ret['error'] = 'Could not verify order. Please try again';
	  return $ret;
	}	
	foreach ($response as $responseOrder) {
		switch($responseOrder['status'])
		{
			case 'complete':    	
			case 'overpayment':
				$transid = $responseOrder['trx_id'];			
				tep_db_query("update " . TABLE_ORDERS . " set orders_status = '" . MODULE_PAYMENT_BITSHARES_PAID_STATUS_ID . "', last_modified = now() where orders_id = '" . $responseOrder['order_id'] . "'");
				$sql_data_array = array('orders_id' => $responseOrder['order_id'],
                              'orders_status_id' => MODULE_PAYMENT_BITSHARES_PAID_STATUS_ID,
                              'date_added' => 'now()',
                              'customer_notified' => '0',
                              'comments' => 'Order Processed! [Transaction ID: ' . $transid . ']');
				tep_db_perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);
				break; 
			default:
				break;	    
		}	 
	}

	return $response;	
}
function createOrderUser()
{

	global $accountName;
	global $hashSalt;
	$order_id    = $_REQUEST['order_id'];
	$asset = btsCurrencyToAsset($_REQUEST['code']);
	$total = number_format((float)$_REQUEST['total'],2);
	
	$hash =  btsCreateEHASH($accountName,$order_id, $total, $asset, $hashSalt);
	$memo = btsCreateMemo($hash);
	$ret = array(
		'accountName'     => $accountName,
		'order_id'     => $order_id,
		'memo'     => $memo
	);	
	
	return $ret;	
}

?>