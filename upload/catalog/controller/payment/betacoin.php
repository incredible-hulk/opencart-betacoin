<?php
/*
Copyright (c) 2013 John Atkinson (jga)
*/

class ControllerPaymentBetacoin extends Controller {

    private $payment_module_name  = 'betacoin';
	protected function index() {
        $this->language->load('payment/'.$this->payment_module_name);
    	$this->data['button_betacoin_pay'] = $this->language->get('button_betacoin_pay');
    	$this->data['text_please_send'] = $this->language->get('text_please_send');
    	$this->data['text_btc_to'] = $this->language->get('text_btc_to');
    	$this->data['text_to_complete'] = $this->language->get('text_to_complete');
    	$this->data['text_click_pay'] = $this->language->get('text_click_pay');
    	$this->data['text_uri_compatible'] = $this->language->get('text_uri_compatible');
    	$this->data['text_click_here'] = $this->language->get('text_click_here');
    	$this->data['text_pre_timer'] = $this->language->get('text_pre_timer');
    	$this->data['text_post_timer'] = $this->language->get('text_post_timer');
		$this->data['text_countdown_expired'] = $this->language->get('text_countdown_expired');
    	$this->data['text_if_not_redirect'] = $this->language->get('text_if_not_redirect');
		$this->data['error_msg'] = $this->language->get('error_msg');
		$this->data['error_confirm'] = $this->language->get('error_confirm');
		$this->data['error_incomplete_pay'] = $this->language->get('error_incomplete_pay');
		$this->data['betacoin_countdown_timer'] = $this->config->get('betacoin_countdown_timer');
		$betacoin_btc_decimal = $this->config->get('betacoin_btc_decimal');
				
		$this->checkUpdate();
	
        $this->load->model('checkout/order');
		$order_id = $this->session->data['order_id'];
		$order = $this->model_checkout_order->getOrder($order_id);

		$current_default_currency = $this->config->get('config_currency');
		$this->data['betacoin_total'] = sprintf("%.".$betacoin_btc_decimal."f", round($this->currency->convert($order['total'], $current_default_currency, "BET"),$betacoin_btc_decimal));
		$this->db->query("UPDATE `" . DB_PREFIX . "order` SET betacoin_total = '" . $this->data['betacoin_total'] . "', date_modified = NOW() WHERE order_id = '" . (int)$order_id . "'");

		require_once('jsonRPCClient.php');
		
		$betacoin = new jsonRPCClient('http://'.$this->config->get('betacoin_rpc_username').':'.$this->config->get('betacoin_rpc_password').'@'.$this->config->get('betacoin_rpc_address').':'.$this->config->get('betacoin_rpc_port').'/');
		
		$this->data['error'] = false;
		try {
			$betacoin_info = $betacoin->getinfo();
		} catch (Exception $e) {
			$this->data['error'] = true;
			if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . '/template/payment/betacoin.tpl')) {
				$this->template = $this->config->get('config_template') . '/template/payment/betacoin.tpl';
			} else {
				$this->template = 'default/template/payment/betacoin.tpl';
			}	
			$this->render();
			return;
		}
		$this->data['error'] = false;
		
		$this->data['betacoin_send_address'] = $betacoin->getaccountaddress($this->config->get('betacoin_prefix').'_'.$order_id);
		$this->db->query("UPDATE `" . DB_PREFIX . "order` SET betacoin_address = '" . $this->data['betacoin_send_address'] . "', date_modified = NOW() WHERE order_id = '" . (int)$order_id . "'");
		
		if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . '/template/payment/betacoin.tpl')) {
			$this->template = $this->config->get('config_template') . '/template/payment/betacoin.tpl';
		} else {
			$this->template = 'default/template/payment/betacoin.tpl';
		}	
		
		$this->render();
	}
	
	
	public function confirm_sent() {
        $this->load->model('checkout/order');
		$order_id = $this->session->data['order_id'];
        $order = $this->model_checkout_order->getOrder($order_id);
		$current_default_currency = $this->config->get('config_currency');	
		$betacoin_btc_decimal = $this->config->get('betacoin_btc_decimal');	
		$betacoin_total = $order['betacoin_total'];
		$betacoin_address = $order['betacoin_address'];
		if(!$this->config->get('betacoin_blockchain')) {
			require_once('jsonRPCClient.php');
			$betacoin = new jsonRPCClient('http://'.$this->config->get('betacoin_rpc_username').':'.$this->config->get('betacoin_rpc_password').'@'.$this->config->get('betacoin_rpc_address').':'.$this->config->get('betacoin_rpc_port').'/');
		
			try {
				$betacoin_info = $betacoin->getinfo();
			} catch (Exception $e) {
				$this->data['error'] = true;
			}
		}

		try {
			if(!$this->config->get('betacoin_blockchain')) {
				$received_amount = $betacoin->getreceivedbyaddress($betacoin_address,0);
			}
			else {
				static $ch = null;
				$ch = curl_init();
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; Blockchain.info PHP client; '.php_uname('s').'; PHP/'.phpversion().')');
				curl_setopt($ch, CURLOPT_URL, 'http://blockchain.info/q/getreceivedbyaddress/'.$betacoin_address.'?confirmations=0');
				$res = curl_exec($ch);
				if ($res === false) throw new Exception('Could not get reply: '.curl_error($ch));
				$received_amount = $res / 100000000;
			}
			if(round((float)$received_amount,$betacoin_btc_decimal) >= round((float)$betacoin_total,$betacoin_btc_decimal)) {
				$order = $this->model_checkout_order->getOrder($order_id);
				$this->model_checkout_order->confirm($order_id, $this->config->get('betacoin_order_status_id'));
				echo "1";
			}
			else {
				echo "0";
			}
		} catch (Exception $e) {
			$this->data['error'] = true;
			echo "0";
		}
	}
	
	public function checkUpdate() {
		if (extension_loaded('curl')) {
			$data = array();
			$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "currency WHERE code = 'BET'");
						
			if(!$query->row) {
				$this->db->query("INSERT INTO " . DB_PREFIX . "currency (title, code, symbol_right, decimal_place, status) VALUES ('Betacoin', 'BET', ' BET', ".$this->config->get('betacoin_btc_decimal').", ".$this->config->get('betacoin_show_btc').")");
				$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "currency WHERE code = 'BET'");
			}
			
			$format = '%Y-%m-%d %H:%M:%S';
			$last_string = $query->row['date_modified'];
			$current_string = strftime($format);
			$last_time = strptime($last_string,$format);
			$current_time = strptime($current_string,$format);
		
			$num_seconds = 60; //every [this many] seconds, the update should run.
			
			if($last_time['tm_year'] != $current_time['tm_year']) {
				$this->runUpdate();
			}
			else if($last_time['tm_yday'] != $current_time['tm_yday']) {
				$this->runUpdate();
			}
			else if($last_time['tm_hour'] != $current_time['tm_hour']) {
				$this->runUpdate();
			}
			else if(($last_time['tm_min']*60)+$last_time['tm_sec'] + $num_seconds < ($current_time['tm_min'] * 60) + $current_time['tm_sec']) {
				$this->runUpdate();
			}
		}
	}
	
	public function runUpdate() {
		$default_currency_code = $this->config->get('config_currency');
		$path = "1/BTC". $default_currency_code . "/ticker";
		$req = array();
		
		// API settings
		$key = '';
		$secret = '';
	 
		// generate a nonce as microtime, with as-string handling to avoid problems with 32bits systems
		$mt = explode(' ', microtime());
		$req['nonce'] = $mt[1].substr($mt[0], 2, 6);
	 
		// generate the POST data string
		$post_data = http_build_query($req, '', '&');
	 
		// generate the extra headers
		$headers = array(
			'Rest-Key: '.$key,
			'Rest-Sign: '.base64_encode(hash_hmac('sha512', $post_data, base64_decode($secret), true)),
		);
	 
		// our curl handle (initialize if required)
		static $ch = null;
		if (is_null($ch)) {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; MtGox PHP client; '.php_uname('s').'; PHP/'.phpversion().')');
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
		}
		curl_setopt($ch, CURLOPT_URL, 'https://data.mtgox.com/api/'.$path);
	 
		// run the query
		$res = curl_exec($ch);
		if ($res === false) throw new Exception('Could not get reply: '.curl_error($ch));
		$dec = json_decode($res, true);
		if (!$dec) throw new Exception('Invalid data received, please make sure connection is working and requested API exists');
		$btcdata = $dec;
		//START BETACOIN CODE
		//$currency = "BTC"; //ORIG
		$currency = "BET";
		//END BETACOIN CODE
		$avg_value = $btcdata['return']['avg']['value'];
		$last_value = $btcdata['return']['last']['value'];
		
		//CHECK BETACOIN VS BITCOIN ON COINS-E
		//START BETACOIN CODE
		static $ch2 = null;
		if (is_null($ch2)) {
			$ch2 = curl_init();
			curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch2, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; MtGox PHP client; '.php_uname('s').'; PHP/'.phpversion().')');
			curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, FALSE);
		}
		curl_setopt($ch2, CURLOPT_URL, 'https://www.coins-e.com/api/v2/markets/data/');
	 
		// run the query
		$res = curl_exec($ch2);
		if ($res === false) throw new Exception('Could not get reply: '.curl_error($ch2));
		$dec = json_decode($res, true);
		if (!$dec) throw new Exception('Invalid data received, please make sure connection is working and requested API exists');
		$btcdata = $dec;
		
		if ( is_array($btcdata) && count($btcdata) > 0) {
			foreach ($btcdata as $key => $valuearr) { 
				if ( is_array($valuearr) && count($valuearr) > 0) {			   
					foreach ($valuearr as $k => $v) {
						if ($k == 'BET_BTC') {
						$avg_rate = $v['marketstat']['24h']['avg_rate'];
						$last_rate = $v['marketstat']['24h']['l'];
						}
				    	}
				}
			}
		}
		
		if($avg_rate < $last_rate) {
			$bet_value = $avg_rate;
		}
		else {
			$bet_value = $last_rate;
		}
		//SET THE CURRENT SELL VALUE
		//END BETACOIN CODE

		if ((float)$avg_value && (float)$last_value) {
			if($avg_value < $last_value) {
				$value = $avg_value;
			}
			else {
				$value = $last_value;
			}
			$value = 1/$value;
			//START BETACOIN CODE
			$value = $value/$bet_value;
			//END BETACOIN CODE
			$this->db->query("UPDATE " . DB_PREFIX . "currency SET value = '" . (float)$value . "', date_modified = '" .  $this->db->escape(date('Y-m-d H:i:s')) . "' WHERE code = '" . $this->db->escape($currency) . "'");
		}
		
		$this->db->query("UPDATE " . DB_PREFIX . "currency SET value = '1.00000', date_modified = '" .  $this->db->escape(date('Y-m-d H:i:s')) . "' WHERE code = '" . $this->db->escape($this->config->get('config_currency')) . "'");
		$this->cache->delete('currency');
	}
}
?>
