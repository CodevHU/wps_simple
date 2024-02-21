<?php
require_once("Callbacks.php");
require_once("Config.php");
require_once("Db.php");
require_once("Email.php");
require_once("Gateway.php");
require_once("IPN.php");
require_once("Order.php");
require_once("Refund.php");
require_once("Settings.php");
require_once("Status.php");
require_once("Update.php");
require_once('src/SimplePayV21.php');

require_once('emails/class-wc-email-status-modified-prepared-for-shipment.php');
require_once('emails/class-wc-email-status-modified-delivery-in-progress.php');
require_once('emails/class-wc-email-status-modified-shipped.php');

if ( class_exists( 'WC_Payment_Gateway' ) ) {

	class WC_Gateway_SimplePay_WPS extends WC_Payment_Gateway {
		private static $actionsInited;
		private static $instance;

		public function __construct() {

			$currency = strtolower(get_woocommerce_currency());
			$allowed_currency = array('huf', 'eur', 'usd');
			if(!in_array($currency, $allowed_currency)) {
				$currency = 'huf';
			}

			$this->id                 = Config::getSlug(); // payment gateway plugin ID
			$this->icon               = Config::getIcon(); // URL of the icon that will be displayed on checkout page near your gateway name
			$this->has_fields         = true; // in case you need a custom credit card form
			$this->method_title       = Config::getMethodTitle();
			$this->method_description = Config::getMethodDescription(); // will be displayed on the options page

			$this->supports = array(
				'products'
			);

			$this->form_fields = Settings::initFormFields();

			$this->init_settings();
			$this->title          = $this->get_option( 'title' ) . (( $this->get_option( $currency . '_sandbox' ) == 'yes' ) ? (' '.__('(TEST MODE)', 'wps-woocommerce-simplepay-payment-gateway')):'');
			$this->description    = $this->get_option( 'description' );
			$this->enabled        = $this->get_option( 'enabled' );
			$this->addicon        = $this->get_option( 'addicon' );
			$this->logger         = 'yes' === $this->get_option( 'logger' );
			$this->log_path       = Config::getLogPath();
			$this->privacy_policy = $this->get_option( 'privacy_policy' );
			$this->twoStep        = 'yes' === $this->get_option( 'twoStep' );
			$this->huf_merchant   = ( $this->get_option( 'huf_sandbox' ) == 'yes' ) ? $this->get_option( 'huf_merchant_test' ) : $this->get_option( 'huf_merchant' );
			$this->huf_secret_key = ( $this->get_option( 'huf_sandbox' ) == 'yes' ) ? $this->get_option( 'huf_secret_key_test' ) : $this->get_option( 'huf_secret_key' );
			$this->eur_merchant   = ( $this->get_option( 'eur_sandbox' ) == 'yes' ) ? $this->get_option( 'eur_merchant_test' ) : $this->get_option( 'eur_merchant' );
			$this->eur_secret_key = ( $this->get_option( 'eur_sandbox' ) == 'yes' ) ? $this->get_option( 'eur_secret_key_test' ) : $this->get_option( 'eur_secret_key' );
			$this->usd_merchant   = ( $this->get_option( 'usd_sandbox' ) == 'yes' ) ? $this->get_option( 'usd_merchant_test' ) : $this->get_option( 'usd_merchant' );
			$this->usd_secret_key = ( $this->get_option( 'usd_sandbox' ) == 'yes' ) ? $this->get_option( 'usd_secret_key_test' ) : $this->get_option( 'usd_secret_key' );
			$this->get_data       = ( isset( $_GET['r'] ) && isset( $_GET['s'] ) ) ? [
				'r' => $_GET['r'],
				's' => $_GET['s']
			] : [];
			$this->post_data      = $_POST;
			$this->server_data    = $_SERVER;
			$this->autochallenge  = true; //3DS - in case of unsuccessful payment with registered card run automatic challange

			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array(
				$this,
				'process_admin_options'
			) );
		}

		public static function getInstance() {
			if ( null === self::$instance ) {
				self::$instance = new WC_Gateway_SimplePay_WPS();
			}

			return self::$instance;
		}

		public static function init() {

			add_action( 'woocommerce_order_refunded', array( 'Refund', 'process' ), 10, 2 );

			if ( isset( $_GET['r'] ) && isset( $_GET['s'] ) ) {

				$result = Gateway::getResult( $_GET['r'] );

				if ( ! is_null( $result ) && array_key_exists( 'o', $result ) ) {
					if(class_exists('WC_Seq_Order_Number')) {
						$order_id = wc_sequential_order_numbers()->find_order_by_order_number( $result['o'] );

					} else {
						$order_id = $result['o'];
					}
					
					$order = new WC_Order($order_id); 

					$trx    = new WPS\SimplePayBack;
					$config = Config::getToSimplePay( $order );
					$trx->addConfig( $config );

					if ( isset( $_REQUEST['r'] ) && isset( $_REQUEST['s'] ) ) {

						if ( $trx->isBackSignatureCheck( $_REQUEST['r'], $_REQUEST['s'] ) ) {
							$params                   = '';
							$countparams              = 0;
							$getParams                = $_GET;
							$getParams['simplepay_s'] = $getParams['s'];
							unset( $getParams['s'] );
							foreach ( $getParams as $key => $value ) {
								$params .= ( $countparams == 0 ? '?' : '&' ) . $key . '=' . urlencode( $value );
								$countparams ++;
							}
							$redirect = Config::getUrl( true ) . $params;
							header( 'Location: ' . $redirect );
							exit;
						}
					}
				}
			} elseif ( isset( $_GET['r'] ) && isset( $_GET['simplepay_s'] ) ) {
				add_filter( 'woocommerce_thankyou', array( 'Callbacks', 'processing' ), 10, 1 );
				add_filter( 'woocommerce_endpoint_order-received_title', array( 'Callbacks', 'checkTitle' ), 10, 1 );
				add_filter( 'woocommerce_thankyou_order_received_text', array( 'Callbacks', 'checkContent' ), 10, 2 );
			}
			self::initActions();
		}

		private static function initActions() {
			if ( null === self::$actionsInited ) {
				self::$actionsInited = true;

				$textdomain    = Config::getTextDomain();
				$textdomainURL = Config::getTextDomainToURL();
				$basename      = Config::getPluginBasename();

				load_plugin_textdomain( $textdomain, false, $textdomain . '/languages/' );

				add_action( 'rest_api_init', array( 'IPN', 'register' ) );
				add_action( $textdomainURL . '_event', array( 'IPN', 'checkStatus' ), 10, 1 );
				add_filter( 'site_transient_update_plugins', array( 'Update', 'push' ), 10, 1 );
				add_filter( 'plugins_api', array( 'Update', 'info' ), 20, 3 );
				add_action( 'upgrader_process_complete', array( 'Update', 'after' ), 10, 2 );

				add_action( 'wp_ajax_ajaxGetStatus', array( "IPN", "ajaxGetStatus" ) );
				add_action( 'wp_ajax_nopriv_ajaxGetStatus', array( "IPN", "ajaxGetStatus" ) );

				add_action( 'wp_enqueue_scripts', array( "Plugin", "addScripts" ) );

				add_action( 'woocommerce_order_actions', array( 'Plugin', 'TwoFactorAddAction' ) );
				add_action( 'woocommerce_order_action_' . Plugin::ACTION_FINISH_TWO_FACTOR, array( 'Callbacks', 'finishTwoFactor' ) );
				add_action( 'wp_footer', array( 'Plugin', 'footerContent' ) );
				add_action( 'init', array( 'Order', 'register_custom_order_statuses' ) );
				add_filter( 'woocommerce_payment_gateways', array( 'Gateway', 'add' ) );
				add_filter( 'woocommerce_gateway_icon', array( 'Plugin', 'addIconToGatewayTitle' ), 10, 2 );
				add_filter( 'woocommerce_gateway_description', array( 'Plugin', 'addPrivacyPolicyToGatewayDescription' ), 25, 2 );
				add_filter( 'plugin_action_links_' . $basename, array( 'Plugin', 'addPluginLink' ) );

				add_filter( 'wc_order_statuses', array( 'Order', 'add_custom_order_statuses_to_order_statuses' ) );

				add_action( 'woocommerce_order_status_' . Order::CUSTOM_ORDER_STATUS_PREPARED_FOR_SHIPMENT, array( WC(), 'send_transactional_email' ), 10, 1 );
				add_filter( 'bulk_actions-edit-shop_order', array( 'Status', 'bulkActions' ) );
				add_action( 'admin_action_mark_' . Order::CUSTOM_ORDER_STATUS_PREPARED_FOR_SHIPMENT, array( 'Status', 'bulkActionHandlerPreparedForShipment' ) );
				add_action( 'admin_action_mark_' . Order::CUSTOM_ORDER_STATUS_DELIVERY_IN_PROGRESS, array( 'Status', 'bulkActionHandlerDeliveryInProgress') );
				add_action( 'admin_action_mark_' . Order::CUSTOM_ORDER_STATUS_SHIPPED, array( 'Status', 'bulkActionHandlerShipped' ) );
				add_action( 'admin_notices', array( 'Status', 'bulkActionNotices' ) );

				add_filter( 'woocommerce_email_classes', array( 'Email', 'register_email' ), 10, 1 );
				add_filter( 'woocommerce_email_actions', array( 'Email', 'add_woocommerce_email_actions' ) );

				$mailer = WC()->mailer();
				add_action( 'woocommerce_order_status_' . Order::CUSTOM_ORDER_STATUS_PREPARED_FOR_SHIPMENT_WOWC, array( 'WC_Email_Status_Modified_Prepared_For_Shipment', 'trigger' ), 10, 1 );
				add_action( 'woocommerce_order_status_' . Order::CUSTOM_ORDER_STATUS_DELIVERY_IN_PROGRESS_WOWC, array( 'WC_Email_Status_Modified_Delivery_In_Progress', 'trigger' ), 10, 1 );
				add_action( 'woocommerce_order_status_' . Order::CUSTOM_ORDER_STATUS_SHIPPED_WOWC, array( 'WC_Email_Status_Modified_Shipped', 'trigger' ), 10, 1 );
				add_action( 'woocommerce_order_status_changed', array( 'Email', 'send_default_emails_force' ), 99, 3 );

				add_shortcode( 'wps_simplepay_gateway_thankyou', array( 'Plugin', 'shortcode_wps_simplepay_gateway_thankyou' ) );
			}
		}

		public function process_payment( $order_id ) {

			if ( ! isset( $_GET['r'] ) && ! isset( $_GET['simplepay_s'] ) ) {
				$order         = Config::getOrder( $order_id );
				$currency      = Config::getOrderCurrency( $order );
				$config        = Config::getToSimplePay( $order );
				$config['URL'] = Config::getCallbackURL( $order_id );

				$items = $order->get_items( array( 'line_item' ) );

				$language = Config::language();

				$order->update_status( 'on-hold', __( 'Online payment was started. Waiting for status answer...', Config::getTextDomain() ) );

				Callbacks::addOrderToTableIPN( $order_id );
				if ( Config::getTwoStep() == false ) {
					Callbacks::cron( true );
				}

				$redirect = Gateway::start( $order, $config, $items, $currency, $language );

				return array(
					'result'   => 'success',
					'redirect' => $redirect,
				);
			}
		}
	}
}