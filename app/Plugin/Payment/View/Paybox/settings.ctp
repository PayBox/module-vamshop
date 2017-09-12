<?php
/* -----------------------------------------------------------------------------------------
   VamShop - http://vamshop.com
   -----------------------------------------------------------------------------------------
   Copyright (c) 2014 VamSoft Ltd.
   License - http://vamshop.com/license.html
   ---------------------------------------------------------------------------------------*/

echo $this->Form->input('paybox.merchant_id', array(
	'label' => __d('paybox','Merchant id'),
	'type' => 'text',
	'value' => $data['PaymentMethodValue'][0]['value']
	));
	
echo $this->Form->input('paybox.secret_key', array(
	'label' => __d('paybox','Secret key'),
	'type' => 'text',
	'value' => $data['PaymentMethodValue'][1]['value']
	));

echo $this->Form->input('paybox.lifetime', array(
	'label' => __d('paybox','Life time'),
	'type' => 'text',
	'value' => $data['PaymentMethodValue'][2]['value']
	));

echo $this->Form->input('paybox.testmode', array(
	'label' => __d('paybox','Demo mode'),
	'type' => 'select',
	'options' => array('1'=>'test', '0'=>'production'),
	'value' => $data['PaymentMethodValue'][3]['value']
	));
?>