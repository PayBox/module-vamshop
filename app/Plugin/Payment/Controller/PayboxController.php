<?php
/* -----------------------------------------------------------------------------------------
   VamShop - http://vamshop.com
   -----------------------------------------------------------------------------------------
   Copyright (c) 2014 VamSoft Ltd.
   License - http://vamshop.com/license.html
   ---------------------------------------------------------------------------------------*/
App::uses('PaymentAppController', 'Payment.Controller');
include 'Paybox/PG_Signature.php';

class PayboxController extends PaymentAppController {
	public $uses = array('PaymentMethod', 'Order');
	public $module_name = 'PayBox';
	public $icon = 'paybox.png';

	public function settings ()
	{
		$this->set('data', $this->PaymentMethod->findByAlias($this->module_name));
	}

	public function install()
	{
		$new_module = array();
		$new_module['PaymentMethod']['active'] = '1';
		$new_module['PaymentMethod']['default'] = '0';
		$new_module['PaymentMethod']['name'] = Inflector::humanize($this->module_name);
		$new_module['PaymentMethod']['icon'] = $this->icon;
		$new_module['PaymentMethod']['alias'] = $this->module_name;

		$new_module['PaymentMethodValue'][0]['payment_method_id'] = $this->PaymentMethod->id;
		$new_module['PaymentMethodValue'][0]['key'] = 'merchant_id';
		$new_module['PaymentMethodValue'][0]['value'] = '';

		$new_module['PaymentMethodValue'][1]['payment_method_id'] = $this->PaymentMethod->id;
		$new_module['PaymentMethodValue'][1]['key'] = 'secret_key';
		$new_module['PaymentMethodValue'][1]['value'] = '';

		$new_module['PaymentMethodValue'][2]['payment_method_id'] = $this->PaymentMethod->id;
		$new_module['PaymentMethodValue'][2]['key'] = 'lifetime';
		$new_module['PaymentMethodValue'][2]['value'] = '';

		$new_module['PaymentMethodValue'][3]['payment_method_id'] = $this->PaymentMethod->id;
		$new_module['PaymentMethodValue'][3]['key'] = 'testmode';
		$new_module['PaymentMethodValue'][3]['value'] = '';

		$this->PaymentMethod->saveAll($new_module);

		$this->Session->setFlash(__('Module Installed'));
		$this->redirect('/payment_methods/admin/');
	}

	public function uninstall()
	{

		$module_id = $this->PaymentMethod->findByAlias($this->module_name);

		$this->PaymentMethod->delete($module_id['PaymentMethod']['id'], true);

		$this->Session->setFlash(__('Module Uninstalled'));
		$this->redirect('/payment_methods/admin/');
	}

	public function before_process ()
	{
		global $config;

		$order = $this->Order->read(null,$_SESSION['Customer']['order_id']);

		$strCurrency = $_SESSION['Customer']['currency_code'];
		if($strCurrency == 'RUR')
			$strCurrency = 'RUB';

		$strDescription = '';
		foreach($order['OrderProduct'] as $arrProduct){
			$strDescription .= $arrProduct['name'];
			if($arrProduct['quantity'] > 1)
				$strDescription .= "*".$arrProduct['quantity'];
			$strDescription .= "; ";
		}
		$arrLifeTime = $this->PaymentMethod->PaymentMethodValue->find('first', array('conditions' => array('key' => 'lifetime')));
		$arrTestMode = $this->PaymentMethod->PaymentMethodValue->find('first', array('conditions' => array('key' => 'testmode')));
		$strLifeTime = $arrLifeTime['PaymentMethodValue']['value'];
		$bTestMode = !empty($arrTestMode['PaymentMethodValue']['value']) ? 1 : 0;
		$arrMerchantId = $this->PaymentMethod->PaymentMethodValue->find('first', array('conditions' => array('key' => 'merchant_id')));
		$nMerchant_id = $arrMerchantId['PaymentMethodValue']['value'];
		$strCallBack = 'http://'.$_SERVER['HTTP_HOST'] .  BASE . '/payment/paybox/result/index.php';
		$strSuccessUrl = 'http://'.$_SERVER['HTTP_HOST'] .  BASE . '/orders/place_order/';
		$strFailUrl = 'http://'.$_SERVER['HTTP_HOST'] .  BASE . '/page/checkout' . $config['URL_EXTENSION'];

		$arrFields = array(
			'pg_merchant_id'		=> $nMerchant_id,
			'pg_order_id'			=> $_SESSION['Customer']['order_id'],
			'pg_currency'			=> $strCurrency,
			'pg_amount'				=> number_format($order['Order']['total'], 2, '.', ''),
			'pg_lifetime'			=> ($strLifeTime)?$strLifeTime*60:0,
			'pg_testing_mode'		=> $bTestMode,
			'pg_description'		=> $strDescription,
			'pg_user_ip'			=> $_SERVER['REMOTE_ADDR'],
			'pg_language'			=> $_SESSION['Customer']['language'] == 'ru' ? $_SESSION['Customer']['language'] : 'en',
			'pg_check_url'			=> $strCallBack,
			'pg_result_url'			=> $strCallBack,
			'pg_success_url'		=> $strSuccessUrl,
			'pg_failure_url'		=> $strFailUrl,
			'pg_request_method'		=> 'GET',
			'pg_salt'				=> rand(21,43433), // Параметры безопасности сообщения. Необходима генерация pg_salt и подписи сообщения.
		);

		if(!empty($order['Order']['phone'])){
			preg_match_all("/\d/", $order['Order']['phone'], $array);
			$strPhone = implode('',@$array[0]);
			if(!empty($strPhone))
				$arrFields['pg_user_phone'] = $strPhone;
		}

		if(!empty($order['Order']['email'])){
			$arrFields['pg_user_email'] = $order['Order']['email'];
			$arrFields['pg_user_contact_email'] = $order['Order']['email'];
		}

		$arrSecretKey = $this->PaymentMethod->PaymentMethodValue->find('first', array('conditions' => array('key' => 'secret_key')));
		$arrFields['pg_sig'] = PG_Signature::make('payment.php', $arrFields, $arrSecretKey['PaymentMethodValue']['value']);

		$strContent = "<form id='contentform' action='https://api.paybox.money/payment.php' method='get'>";
		foreach($arrFields as $strName => $strValue){
			$strContent .= "<input type='hidden' name='$strName' value='$strValue'>";
		}
		$strContent .= '<input type="submit" value="{lang}Process to Payment{/lang}"></form>';

		foreach($_POST AS $key => $value)
			$order['Order'][$key] = $value;

		// Get the default order status
		$default_status = $this->Order->OrderStatus->find('first', array('conditions' => array('default' => '1')));
		$order['Order']['order_status_id'] = $default_status['OrderStatus']['id'];

		// Save the order
		$this->Order->save($order);

		return $strContent;
	}

	public function after_process()
	{
	}


	public function result()
	{
		$arrRequest = array();
		if(!empty($_POST))
			$arrRequest = $_POST;
		else
			$arrRequest = $_GET;

		$arrSecretKey = $this->PaymentMethod->PaymentMethodValue->find('first', array('conditions' => array('key' => 'secret_key')));
		$thisScriptName = PG_Signature::getOurScriptName();
		if (empty($arrRequest['pg_sig']) || !PG_Signature::check($arrRequest['pg_sig'], $thisScriptName, $arrRequest, $arrSecretKey['PaymentMethodValue']['value']))
			die("Wrong signature");

		$order = $this->Order->read(null,$arrRequest['pg_order_id']);

		if(!isset($arrRequest['pg_result'])){
			$bCheckResult = 0;
			if(empty($order))
				$error_desc = "Товар не доступен.";
			elseif($arrRequest['pg_amount'] != number_format($order["Order"]['total'], 2, '.', ''))
				$error_desc = "Неверная сумма";
			else
				$bCheckResult = 1;

			$arrResponse['pg_salt']              = $arrRequest['pg_salt']; // в ответе необходимо указывать тот же pg_salt, что и в запросе
			$arrResponse['pg_status']            = $bCheckResult ? 'ok' : 'error';
			$arrResponse['pg_error_description'] = $bCheckResult ?  ""  : $error_desc;
			$arrResponse['pg_sig']				 = PG_Signature::make($thisScriptName, $arrResponse, $arrSecretKey['PaymentMethodValue']['value']);

			$objResponse = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><response/>');
			$objResponse->addChild('pg_salt', $arrResponse['pg_salt']);
			$objResponse->addChild('pg_status', $arrResponse['pg_status']);
			$objResponse->addChild('pg_error_description', $arrResponse['pg_error_description']);
			$objResponse->addChild('pg_sig', $arrResponse['pg_sig']);

		}
		else{
			$bResult = 0;
			if(empty($order))
				$strResponseDescription = "Товар не доступен.";
			elseif($arrRequest['pg_amount'] != number_format($order["Order"]['total'], 2, '.', ''))
				$strResponseDescription = "Неверная сумма";
			else {
				$bResult = 1;
				$strResponseStatus = 'ok';
				$strResponseDescription = "Запрос принял";
				// поставить в удачный
				if($arrRequest['pg_result'] == 1){
					$payment_method = $this->PaymentMethod->find('first', array('conditions' => array('alias' => $this->module_name)));
					$order_data = $this->Order->find('first', array('conditions' => array('Order.id' => $arrRequest['pg_order_id'])));
					$order_data['Order']['order_status_id'] = $payment_method['PaymentMethod']['order_status_id'];
					$this->Order->save($order_data);
				}
			}
			if(!$bResult)
				if($arrRequest['pg_can_reject'] == 1)
					$strResponseStatus = 'rejected';
				else
					$strResponseStatus = 'error';

			$objResponse = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><response/>');
			$objResponse->addChild('pg_salt', $arrRequest['pg_salt']); // в ответе необходимо указывать тот же pg_salt, что и в запросе
			$objResponse->addChild('pg_status', $strResponseStatus);
			$objResponse->addChild('pg_description', $strResponseDescription);
			$objResponse->addChild('pg_sig', PG_Signature::makeXML($thisScriptName, $objResponse, $arrSecretKey['PaymentMethodValue']['value']));
		}

		header("Content-type: text/xml");
		echo $objResponse->asXML();
		die();
	}

}

?>
