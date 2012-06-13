<?php
/**
 * Fontis CommWeb Extension
 *
 * This extension connects to the Commonwealth Bank's CommWeb payment gateway.
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com you will be sent a copy immediately.
 *
 * @category   Fontis
 * @package    Fontis_CommWeb
 * @author     Chris Norton
 * @copyright  Copyright (c) 2008 Fontis Pty. Ltd. (http://www.fontis.com.au)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
 
class Fontis_CommWeb_Model_CommWeb extends Mage_Payment_Model_Method_Cc
{

    protected $_code  = 'commWeb';

    protected $_isGateway               = true;
    protected $_canAuthorize            = false;
    protected $_canCapture              = true;
    protected $_canCapturePartial       = false;
    protected $_canRefund               = false;
    protected $_canVoid                 = false;
    protected $_canUseInternal          = true;
    protected $_canUseCheckout          = true;
    protected $_canUseForMultishipping  = true;
    protected $_canSaveCc               = false;
    
    // Credit Card URLs
    const CC_URL_LIVE = 'https://migs.mastercard.com.au/vpcdps';    
    
    const STATUS_APPROVED = 'Approved';

	const PAYMENT_ACTION_AUTH_CAPTURE = 'authorize_capture';
	const PAYMENT_ACTION_AUTH = 'authorize';

	/**
	 * Returns the URL to send requests to. This changes depending on whether
	 * the extension is set to testing mode or not.
	 */
	public function getGatewayUrl()
	{
		return self::CC_URL_LIVE;
	}
	
	public function getDebug()
	{
		return Mage::getStoreConfig('payment/commWeb/debug');
	}
	
	public function getLogPath()
	{
		return Mage::getBaseDir() . '/var/log/commWeb.log';
	}
	
	/**
	 * Returns the MerchantID as set in the configuration. Note that, if test
	 * mode is active then "TEST" will be prepended to the ID.
	 */
	public function getUsername()
	{
		if(Mage::getStoreConfig('payment/commWeb/test'))
		{
			return 'TEST' . Mage::getStoreConfig('payment/commWeb/username');
		}
		else
		{
			return Mage::getStoreConfig('payment/commWeb/username');
		}
	}
	
	public function getPassword()
	{
		return Mage::getStoreConfig('payment/commWeb/password');
	}

	/**
	 *
	 */
	public function validate()
    {
    	if($this->getDebug())
		{
	    	$writer = new Zend_Log_Writer_Stream($this->getLogPath());
			$logger = new Zend_Log($writer);
			$logger->info("entering validate()");
		}
		
        parent::validate();
        $paymentInfo = $this->getInfoInstance();
        if ($paymentInfo instanceof Mage_Sales_Model_Order_Payment) {
            $currency_code = $paymentInfo->getOrder()->getBaseCurrencyCode();
        } else {
            $currency_code = $paymentInfo->getQuote()->getBaseCurrencyCode();
        }
        return $this;
    }

	public function authorize(Varien_Object $payment, $amount)
	{
		if($this->getDebug())
		{
			$writer = new Zend_Log_Writer_Stream($this->getLogPath());
			$logger = new Zend_Log($writer);
			$logger->info("entering authorize()");
		}
	}
	
	/**
	 *
	 */
	public function capture(Varien_Object $payment, $amount)
	{
		if($this->getDebug())
		{
			$writer = new Zend_Log_Writer_Stream($this->getLogPath());
			$logger = new Zend_Log($writer);
			$logger->info("entering capture()");
		}
	
		$this->setAmount($amount)
			->setPayment($payment);

		$result = $this->_call($payment);
		
		if($this->getDebug()) { $logger->info(var_export($result, TRUE)); }

		if($result === false)
		{
			$e = $this->getError();
			if (isset($e['message'])) {
				$message = Mage::helper('commWeb')->__('There has been an error processing your payment.') . $e['message'];
			} else {
				$message = Mage::helper('commWeb')->__('There has been an error processing your payment. Please try later or contact us for help.');
			}
			Mage::throwException($message);
		}
		else
		{
			// Check if there is a gateway error
			if ($result['vpc_TxnResponseCode'] == "0")
			{
				$payment->setStatus(self::STATUS_APPROVED)
					->setLastTransId($this->getTransactionId());
			}
			else
			{
				Mage::throwException("Error code " . $result['vpc_TxnResponseCode'] . ": " . urldecode($result['vpc_Message']));
			}
		}
		return $this;
	}

	/**
	 *
	 */
	protected function _call(Varien_Object $payment)
	{
		if($this->getDebug())
		{
			$writer = new Zend_Log_Writer_Stream($this->getLogPath());
			$logger = new Zend_Log($writer);
			$logger->info("entering _call()");
		}
		
		// Generate any needed values
		
		// Create expiry dat in format "YY/MM"
		$date_expiry = substr($payment->getCcExpYear(), 2, 2) . str_pad($payment->getCcExpMonth(), 2, '0', STR_PAD_LEFT);
		
		// Most currency have two minor units (e.g. cents) and thus need to be
		// multiplied by 100 to get the correct number to send.
		$amount = $this->getAmount() * 100;
		
		// Several currencies do not have minor units and thus should not be
		// multiplied.
		if($payment->getOrder()->getBaseCurrencyCode() == 'JPY' ||
		   $payment->getOrder()->getBaseCurrencyCode() == 'ITL' ||
		   $payment->getOrder()->getBaseCurrencyCode() == 'GRD')
		{
			$amount = $amount / 100;
		}
		
		if($this->getDebug())
		{
			$logger->info( var_export($payment->getOrder()->getData(), TRUE) );
		}
		
		// Build the XML request
		$request = array();
		$request['vpc_Version'] = '1';
		$request['vpc_Command'] = 'pay';
		$request['vpc_MerchTxnRef'] = $payment->getOrder()->getIncrementId();
		$request['vpc_Merchant'] = htmlentities($this->getUsername());
		$request['vpc_OrderInfo'] = $payment->getOrder()->getIncrementId();
		$request['vpc_CardNum'] = htmlentities($payment->getCcNumber());
		$request['vpc_CardExp'] = htmlentities($date_expiry);
		
		$request['vpc_CardSecurityCode'] = htmlentities($payment->getCcCid());
		
		$request['vpc_AccessCode'] = htmlentities($this->getPassword()); 
		$request['vpc_Amount'] = htmlentities($amount);
		
		// DEBUG
		if($this->getDebug()) { $logger->info(print_r($request, true)); }
		
		$postRequestData = '';
		$amp = '';
		foreach($request as $key => $value) {
			if(!empty($value)) {
				$postRequestData .= $amp . urlencode($key) . '=' . urlencode($value);
				$amp = '&';
			}
		}
		
		// Send the data via HTTP POST and get the response
		$http = new Varien_Http_Adapter_Curl();
		$http->setConfig(array('timeout' => 30));		
		$http->write(Zend_Http_Client::POST, $this->getGatewayUrl(), '1.1', array(), $postRequestData);
		
		$response = $http->read();
		
		if ($http->getErrno()) {
			$http->close();
			$this->setError(array(
				'message' => $http->getError()
			));
			return false;
		}
		
		// Close the connection
		$http->close();
		
		// DEBUG
		if($this->getDebug()) { 
			$logger->info($response); 
		}
		
		// Strip out header tags
        $response = preg_split('/^\r?$/m', $response, 2);
        $response = trim($response[1]);
		
		// Fill out the results
		$result = array();
		$pieces = explode('&', $response);
		foreach($pieces as $piece) {
			$tokens = explode('=', $piece);
			$result[$tokens[0]] = $tokens[1];
		}
		
		if($this->getDebug()) { 
			$logger->info(print_r($result, true));
		}
			
		return $result;
	}
}
