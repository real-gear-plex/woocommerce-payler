<?php 
/*
  Plugin Name: Payler Payment Gateway
  Plugin URI: 
  Description: Allows you to use Payler payment gateway with the WooCommerce plugin.
  Version: 1.0
  Author: Payler
  Author URI: https://payler.com
 */

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
} 

function supported_currencies() {
	return array('RUB', 'USD', 'EUR', 'GBP', 'PLN', 'TJS', 'KGS');
}

function payler_currency_symbol( $currency_symbol, $currency ) {
	switch ($currency) {
		case 'RUB':
			$result = 'р.';
			break;
		case 'USD':
			$result = '$';
			break;
		case 'EUR':
			$result = '€';
			break;
		case 'GBP':
			$result = '£';
			break;
		case 'PLN':
			$result = 'zł';
			break;
		case 'TJS':
			$result = 'ЅМ';
			break;
		case 'KGS':
			$result = 'сом';
			break;
		default:
			return $currency_symbol;
	}

    return $result;
}

function payler_currency( $currencies ) {
    $currencies['RUB'] = 'Russian Roubles';
	$currencies['USD'] = 'United States (US) dollar';
	$currencies['EUR'] = 'Euro';
    $currencies['GBP'] = 'Pound sterling';
	$currencies['PLN'] = 'Polish złoty';
	$currencies['TJS'] = 'Tajikistani somoni';
	$currencies['KGS'] = 'Kyrgyzstani som';
    return $currencies;
}

add_filter( 'woocommerce_currency_symbol', 'payler_currency_symbol', 10, 2 );
add_filter( 'woocommerce_currencies', 'payler_currency', 10, 1 );

/* Add a custom payment class to WC */

add_action('plugins_loaded', 'woocommerce_payler', 0);
function woocommerce_payler() {
	if (!class_exists('WC_Payment_Gateway')) {
		return; // if the WC payment gateway class is not available, do nothing
	}
	if(class_exists('WC_PAYLER')) {
		return;
	}
class WC_PAYLER extends WC_Payment_Gateway{
	public function __construct() {
		
		$plugin_dir = plugin_dir_url(__FILE__);

		global $woocommerce;

		$this->id = 'payler';
		$this->icon = apply_filters('woocommerce_payler_icon', ''.$plugin_dir.'payler.png');
		$this->has_fields = false;
        $this->produrl = 'https://secure.payler.com/gapi/';
		$this->testurl = 'https://sandbox.payler.com/gapi/';

		// Load the settings
		$this->init_form_fields();
		$this->init_settings();

		// Define user set variables
		$this->title = $this->get_option('title');
		$this->payler_key = $this->get_option('payler_key');
		$this->testmode = $this->get_option('testmode');
		$this->session_type = $this->get_option('session_type');
		$this->description = $this->get_option('description');
		$this->instructions = $this->get_option('instructions');

		// Actions
		add_action('valid-payler-standard-ipn-reques', array($this, 'successful_request') );
		add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));

		// Save options
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		// Payment listener/API hook
		add_action('woocommerce_api_wc_' . $this->id, array($this, 'check_ipn_response'));

		if (!$this->is_valid_for_use()) {
			$this->enabled = false;
		}
	}
	
	/**
	 * Check if this gateway is enabled and available in the user's country
	 */
	function is_valid_for_use(){
		if (!in_array(get_option('woocommerce_currency'), supported_currencies())) {
			return false;
		}
		return true;
	}
	
	/**
	* Admin Panel Options 
	* - Options for bits like 'title' and availability on a country-by-country basis
	**/
	public function admin_options() {
		?>
		<h3><?php _e('PAYLER', 'woocommerce'); ?></h3>
		<p><?php _e('Настройка приема электронных платежей через PAYLER.', 'woocommerce'); ?></p>

	  <?php if ( $this->is_valid_for_use() ) : ?>

		<table class="form-table">

		<?php    	
    			// Generate the HTML For the settings form.
    			$this->generate_settings_html();
    ?>
    </table><!--/.form-table-->
    		
    <?php else : ?>
		<div class="inline error"><p><strong><?php _e('Шлюз отключен', 'woocommerce'); ?></strong>: <?php _e('Payler не поддерживает валюты вашего магазина.', 'woocommerce' ); ?></p></div>
		<?php
			endif;

    } // End admin_options()

  /**
  * Initialise Gateway Settings Form Fields
  *
  * @access public
  * @return void
  */
	function init_form_fields(){
		$this->form_fields = array(
				'enabled' => array(
					'title' => __('Включен?', 'woocommerce'),
					'type' => 'checkbox',
					'label' => __('Включен', 'woocommerce'),
					'default' => 'yes'
				),
				'title' => array(
					'title' => __('Название', 'woocommerce'),
					'type' => 'text', 
					'description' => __( 'Это название, которое пользователь видит во время оплаты.', 'woocommerce' ), 
					'default' => __('PAYLER', 'woocommerce')
				),
				'payler_key' => array(
					'title' => __('Платежный ключ', 'woocommerce'),
					'type' => 'text',
					'description' => __('Введите платежный ключ, выданный сотрудниками Payler', 'woocommerce'),
					'default' => ''
				),
				'testmode' => array(
					'title' => __('Тестовый режим?', 'woocommerce'),
					'type' => 'checkbox', 
					'label' => __('Включен', 'woocommerce'),
					'description' => __('Использовать тестовый аккаунт. Внимание: платежные ключи для тестового и боевого аккаунта различаются!', 'woocommerce'),
					'default' => 'no'
				),
				'session_type' => array(
					'title' => __('Тип платежной сессии', 'woocommerce'),
					'type' => 'select', 
					'label' => __('Тип платежной сессии', 'woocommerce'),
					'description' => __('При двухстадийной оплате денежные средства только блокируются на карте, после чего менеджер через Личный кабинет Payler должен подтвердить платеж', 'woocommerce'),
					'options' => array(
						'OneStep' => 'Одностадийная оплата',
						'TwoStep' => 'Двухстадийная оплата'
					),
					'default' => 'Одностадийная оплата'
				),
				'description' => array(
					'title' => __( 'Описание', 'woocommerce' ),
					'type' => 'textarea',
					'description' => __( 'Описанием метода оплаты, которое клиент будет видеть на сайте', 'woocommerce' ),
					'default' => 'Оплата с помощью Payler.'
				),
				'instructions' => array(
					'title' => __( 'Инструкции', 'woocommerce' ),
					'type' => 'textarea',
					'description' => __( 'Дополнительная информация, которую пользователь увидит после успешной оплаты.', 'woocommerce' ),
					'default' => ''
				)
			);
	}

	/**
	* There are no payment fields for sprypay, but we want to show the description if set.
	**/
	function payment_fields(){
		if ($this->description) {
			echo wpautop(wptexturize($this->description));
		}
	}
	
	private function get_payler_url() {
		return $this->testmode == 'yes' ? $this->testurl : $this->produrl;
	}

	private function send_request($method, $request_data) {
		$headers = array(
			'Content-type: application/x-www-form-urlencoded',
			'Cache-Control: no-cache',
			'charset="utf-8"',
		);

        $payler_url = $this->get_payler_url();
        
		$data = http_build_query($request_data);
		$options = array (
			CURLOPT_URL => $payler_url . $method,
			CURLOPT_POST => 1,
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_TIMEOUT => 45,
			CURLOPT_VERBOSE => 0,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_POSTFIELDS => $data,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_SSL_VERIFYPEER => 1,
			CURLOPT_SSL_VERIFYPEER => 6,
		);
         
		$ch = curl_init();
		curl_setopt_array($ch, $options);
		$json = curl_exec($ch);
		if ($json === false) {
			die ('Curl error: ' . curl_error($ch) . '<br>');
		} else {
			$payler_result = json_decode($json, TRUE);
			curl_close($ch);
			return $payler_result;
		}

		return array('error' => 'Unexpected error');
	}

	/**
	* Generate the dibs button link
	**/
	public function generate_form($order_id){
		global $woocommerce;

		$order = new WC_Order( $order_id );
		
		$data = array(
			'key'		=> $this->payler_key,
			'type'		=> $this->session_type,
			'currency'  => $order->get_currency(),
			'order_id'	=> $order->id.'|'.time(),
			'amount'	=> $order->order_total * 100,
			'userdata'  => $order->order_key
		);

		$payler_result = $this->send_request('StartSession', $data);

		if(isset($payler_result['session_id'])) {
			$order->add_order_note('PaylerOderID:'.$data['order_id']);
			return
				'<form action="' . $this->get_payler_url() . 'Pay" method="POST" id="payler_payment_form">'."\n".
				'<input type="submit" class="button alt" id="submit_payler_payment_form" value="'.__('Оплатить', 'woocommerce').'" /> <a class="button cancel" href="'.$woocommerce->cart->get_cart_url().'">'.__('Отказаться от оплаты & вернуться в корзину', 'woocommerce').'</a>'."\n".
				'<input type="hidden" value="' . $payler_result['session_id'] . '" name="session_id">'.
				'</form>';
		}

		$order->add_order_note('Ошибка при оплате через Payler: не удалось создать платежную сессию. OrderID:'.$data['order_id']);

		return
			'<label>Не удалось начать оплату через Payler. Пожалуйста, сообщите об этом администратору</label><br>'.
			' <a class="button cancel" href="'.$woocommerce->cart->get_cart_url().'">'.__('Вернуться в корзину', 'woocommerce').'</a>'."\n".
			'<input type="hidden" value="' . $payler_result['session_id'] . '" name="session_id">'.
			'</form>';
	}
	
	/**
	 * Process the payment and return the result
	 **/
	
	function process_payment($order_id){
		$order = new WC_Order($order_id);

		return array(
			'result' => 'success',
			'redirect'	=> add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(woocommerce_get_page_id('pay'))))
		);
	}
	
	/**
	* receipt_page
	**/
	function receipt_page($order){
		echo '<p>'.__('Спасибо за Ваш заказ, пожалуйста, нажмите кнопку ниже, чтобы заплатить.', 'woocommerce').'</p>';
		echo $this->generate_form($order);
	}
	
	
	/**
	* Check Response
	**/
	function check_ipn_response() {
		global $woocommerce;

		$payler_order_id = $_GET['order_id'];

		if (empty($payler_order_id)) {
			$payler_order_id = $_POST['order_id'];
		}

		$data = array(
			'key'		=> $this->payler_key,
			'order_id'  => $payler_order_id
		);
		
		$payler_status = $this->send_request('GetAdvancedStatus', $data);
	        
		$payler_edit_order_id = substr($payler_status['order_id'], 0, strpos($payler_status['order_id'], '|'));
		$our_edit_order_id    = substr($payler_order_id, 0, strpos($payler_order_id, '|'));

		$order = new WC_Order($payler_edit_order_id);

		if (array_key_exists('error', $payler_status)) {
			$text_status = $payler_status['error']['message'];
			$order->update_status('failed', __('Заказ не оплачен: '. $text_status, 'woocommerce'));
			wp_redirect($order->get_cancel_order_url());
				exit;
		}

		if ($our_edit_order_id == $payler_edit_order_id) {
			if ($payler_status['amount'] != $order->order_total * 100 || $payler_status['currency'] != $order->get_currency()) {
				$text_status = $payler_status['currency'] != $order->get_currency() ? 'валюта заказа не совпадает с валютой оплаты' : 'сумма заказа не совпадает с суммой оплаты';
			    $order->update_status('failed', __('Заказ не оплачен, '. $text_status, 'woocommerce'));
			    wp_redirect($order->get_cancel_order_url());
		            exit;
			} else if ($payler_status['status'] == 'Charged' || $payler_status['status'] == 'Authorized') {
				$text_status = $payler_status['status'] == 'Charged' ? 'Заказ успешно оплачен' : 'Денежные средства по заказу успешно заблокированы и ожидают подтверждения';
			    $order->update_status('processing', __($text_status, 'woocommerce'));
			    WC()->cart->empty_cart();
			    wp_redirect( $this->get_return_url( $order ) );
		    } else {
		    	$order = new WC_Order($payler_edit_order_id);
			    $order->update_status('failed', __('Заказ не оплачен', 'woocommerce'));
			    wp_redirect($order->get_cancel_order_url());
		            exit;
		    }
		}
	}
}

/**
 * Add the gateway to WooCommerce
 **/
function add_payler_gateway($methods){
	$methods[] = 'WC_PAYLER';
	return $methods;
}


add_filter('woocommerce_payment_gateways', 'add_payler_gateway');
}
?>
