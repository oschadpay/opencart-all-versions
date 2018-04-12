<?php

class ControllerPaymentOplata extends Controller
{

	protected $RESPONCE_SUCCESS = 'success';
    protected $RESPONCE_FAIL = 'failure';
    protected $ORDER_SEPARATOR = '_';
    protected $SIGNATURE_SEPARATOR = '|';
    protected $ORDER_APPROVED = 'approved';
    protected $ORDER_DECLINED = 'declined';
	protected $ORDER_EXPIRED = 'expired';
	protected $ORDER_PROCESSING = 'processing';

    public function index()
    {
        $this->language->load('payment/oplata');
        $order_id = $this->session->data['order_id'];
        $this->load->model('checkout/order');
        
        $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
        $backref = $this->url->link('payment/oplata/response', '', 'SSL');
        $callback = $this->url->link('payment/oplata/callback', '', 'SSL');
        $desc = $order_id;
        if (($this->config->get('oplata_currency'))) {
            $oplata_currency = $this->config->get('oplata_currency');
        } else {
            $oplata_currency = $this->currency->getCode();
        }
        $oplata_args = array(
			'order_id' => $order_id . $this->ORDER_SEPARATOR . time(),
            'merchant_id' => trim($this->config->get('oplata_merchant')),
            'order_desc' => $desc,
            'amount' => round($order_info['total'] * $order_info['currency_value'] * 100),
            'currency' => $oplata_currency,
            'response_url' => $backref,
            'server_callback_url' => $callback,
            'lang' => $this->config->get('oplata_language'),
            'sender_email' => trim($order_info['email'])
        );

        $oplata_args['signature'] = $this->getSignature($oplata_args, trim($this->config->get('oplata_secretkey')));
        $url = $this->generateOschadpayUrl($oplata_args);
		
        $data['oschadpay_data'] = $url;
        $data['button_confirm'] = $this->language->get('button_confirm');
        if (version_compare(VERSION, '2.1.0.2', '>')) {
            if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . '/template/payment/oplata.tpl')) {
                return $this->load->view($this->config->get('config_template') . '/template/payment/oplata.tpl', $data);
            } else {
                return $this->load->view('/payment/oplata.tpl', $data);
            }
        } else {
            if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . '/template/payment/oplata.tpl')) {
                return $this->load->view($this->config->get('config_template') . '/template/payment/oplata.tpl', $data);
            } else {
                return $this->load->view('default/template/payment/oplata.tpl', $data);
            }
        }
    }

    public function response()
    {
        $this->language->load('payment/oplata');
		$this->load->model('checkout/order');
        $options = array(
            'merchant' => $this->config->get('oplata_merchant'),
            'secretkey' => $this->config->get('oplata_secretkey')
        );

        $paymentInfo = $this->isPaymentValid($options, $this->request->post);
		$this->cart->clear();
        if ($paymentInfo === true && $this->request->post['order_status'] != $this->ORDER_DECLINED) {
			$backref = $this->url->link('checkout/success', '', 'SSL');
			$this->response->redirect($backref);	
        } else {
            if ($this->request->post['order_status'] == $this->ORDER_DECLINED) {
                $this->session->data ['oplata_error'] = $this->language->get('error_oplata') . ' ' . $this->request->post['response_description'] . '. ' . $this->language->get('error_kod') . $this->request->post['response_code'];
                $this->response->redirect($this->url->link('checkout/confirm', '', 'SSL'));
            }
            $this->session->data ['oplata_error'] = $this->language->get('error_oplata') . ' ' . $this->request->post['response_description'] . '. ' . $this->language->get('error_kod') . $this->request->post['response_code'];
            $this->response->redirect($this->url->link('checkout/confirm', '', 'SSL'));
        }
    }

    public function callback()
    {
		if (empty($this->request->post)) {
            $callback = json_decode(file_get_contents("php://input"));
            if (empty($callback)) {
                die();
            }
            $this->request->post = array();
            foreach ($callback as $key => $val) {
                $this->request->post[$key] = $val;
            }
        }
		
        $this->language->load('payment/oplata');
	
        $options = array(
            'merchant' => $this->config->get('oplata_merchant'),
            'secretkey' => $this->config->get('oplata_secretkey')
        );

        $paymentInfo = $this->isPaymentValid($options, $this->request->post);
		
		list($order_id,) = explode($this->ORDER_SEPARATOR, $this->request->post['order_id']);
        $this->load->model('checkout/order');
        $order_info = $this->model_checkout_order->getOrder($order_id);
        $total = round($order_info['total'] * $order_info['currency_value'] * 100);
		
        if ($paymentInfo === true) {

            if ($this->request->post['order_status'] == $this->ORDER_APPROVED and $total == $this->request->post['amount']) {
                $comment = "Oschadpay payment id : " . $this->request->post['payment_id'];
                $this->model_checkout_order->addOrderHistory($order_id, $this->config->get('oplata_order_status_id'), $comment, $notify = true, $override = false);
                die('Ok');
            } else if ($this->request->post['order_status'] == $this->ORDER_PROCESSING){
                $comment = "Oschadpay payment id : " . $this->request->post['payment_id'] . $paymentInfo;
                $this->model_checkout_order->addOrderHistory($order_id, $this->config->get('oplata_order_process_status_id'), $comment, $notify = false, $override = false);
                die($paymentInfo);
            } else if ($this->request->post['order_status'] == $this->ORDER_DECLINED or $this->request->post['order_status'] == $this->ORDER_EXPIRED){
				$comment = "Payment cancelled";
				$this->model_checkout_order->addOrderHistory($order_id, $this->config->get('oplata_order_cancelled_status_id'), $comment, $notify = false, $override = false);
			}
        }
    }

    public function isPaymentValid($oplataSettings, $response)
    {
        $this->language->load('payment/oplata');
        if ($oplataSettings['merchant'] != $response['merchant_id']) {
            return $this->language->get('error_merchant');
        }

        $responseSignature = $response['signature'];
        if (isset($response['response_signature_string'])) {
            unset($response['response_signature_string']);
        }
        if (isset($response['signature'])) {
            unset($response['signature']);
        }
        if (self::getSignature($response, $oplataSettings['secretkey']) != $responseSignature) {
            return $this->language->get('error_signature');
        }
        return true;
    }

    public function getSignature($data, $password, $encoded = true)
    {
        $data = array_filter($data, function ($var) {
            return $var !== '' && $var !== null;
        });
        ksort($data);

        $str = $password;
        foreach ($data as $k => $v) {
            $str .= $this->SIGNATURE_SEPARATOR . $v;
        }

        if ($encoded) {
            return sha1($str);
        } else {
            return $str;
        }
    }
	public function generateOschadpayUrl($oplata_args) {
		$ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.oschadpay.com.ua/api/checkout/url/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-type: application/json'));
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array('request' => $oplata_args)));
        $result = json_decode(curl_exec($ch));
        if ($result->response->response_status == 'failure') {
            $out = array('result' => false,
                'message' => $result->response->error_message);
        } else {
            $out = array('result' => true,
                'url' => $result->response->checkout_url);
        }
		return $out;
	}
}

?>