<?php 
/*
Copyright (c) 2013 John Atkinson (jga)
*/

class ModelPaymentBetacoin extends Model {
  	public function getMethod($address) {
		$this->load->language('payment/betacoin');
		
		if ($this->config->get('betacoin_status')) {
        	$status = TRUE;
		} else {
			$status = FALSE;
		}
		
		$method_data = array();
	
		if ($status) {  
      		$method_data = array( 
        		'code'         	=> 'betacoin',
        		'title'      	=> $this->language->get('text_title'),
				'sort_order' 	=> $this->config->get('betacoin_sort_order'),
      		);
    	}
   
    	return $method_data;
  	}
}
?>
