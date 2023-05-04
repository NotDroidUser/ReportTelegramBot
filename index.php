<?php

/*
Plugin Name: Telegram woocomerce/forminator report bot (noconfig)
Plugin URI: https://github.com/NotDroidUser/ReportTelegramBot
Description: A Plugin for report all orders to telegram.
Version: 1.0
Author: NotADroidUser
Author URI: https://github.com/NotDroidUser/
License: MIT License
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

include( plugin_dir_path( __FILE__ ) . "/src/ApiInterface.php" );
include( plugin_dir_path( __FILE__ ) . "/src/Api.php" );
include( plugin_dir_path( __FILE__ ) . "/src/Client.php" );
include( plugin_dir_path( __FILE__ ) . "/src/EasyVars.php" );

use TuriBot\Client;

function jal_install() {
	global $wpdb;
	$table_name      = $wpdb->prefix . "reportTool";
	$charset_collate = $wpdb->get_charset_collate();
	$version         = "1.0";

	$sql = "CREATE TABLE $table_name (
	  id mediumint(9) NOT NULL AUTO_INCREMENT,
	  orderid mediumint(9) NOT NULL UNIQUE,
	  PRIMARY KEY (id)
	) $charset_collate;";

	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
	dbDelta( $sql );
	add_option( $table_name . "_version", $version );
}


function notified( $orderid ): bool {
	global $wpdb;
	$table_name = $wpdb->prefix . "reportTool";
	$sql        = "Select orderid from $table_name where orderid='$orderid';";
	$results    = $wpdb->get_results( $sql );
	if ( is_array( $results ) && sizeof( $results ) > 0 ) {
		return true;
	} else {
		$wpdb->insert( $table_name, array(
			"orderid" => $orderid
		) );

		return false;
	}
}

register_activation_hook( __FILE__, "jal_install" );


function get_bot_token() {
	return "0000:xxxxxxxxxx";
}

function get_debug_group(): int {
	return - 00000;
}

function get_group(): int {
	return - 00000;
}

function get_secundary_group(): int {
	return - 00000;
}

class TelegramBot {
	private Client $client;

	/**
	 * @param $group int
	 *
	 * @return void
	 */
	function __construct() {
		$this->client = new Client( get_bot_token() );
	}

	function send_message( string $message ) {
		$this->client->sendMessage( get_group(), $message, "HTML" );
	}

	function send_debug_message( string $message ) {
		$this->client->sendMessage( get_debug_group(), $message, "HTML" );
	}

	function send_image( string $message, string $image ) {
		$this->client->sendPhoto( get_secundary_group(), $image, $message, "HTML" );
	}
}

add_action( 'user_register', 'vtb_my_user_register' );
add_action( 'woocommerce_cancelled_order', 'vtb_my_cancelled' );
add_action( 'woocommerce_thankyou', 'vtb_my_tank' );
add_action( 'forminator_form_after_save_entry', "vtb_form_send_completed" );
add_action( 'template_redirect', 'vtb_checkout_no_prod' );
add_action( 'transition_post_status', 'add_edit_product', 10, 3 );

//add_action( 'woocommerce_new_product', 'vtb_my_prod_notifier',10, 2 );

function vtb_my_user_register( $user_id ) {
	$telegram_bot = new TelegramBot();
	$userdata     = get_userdata( $user_id );
	if ( ! empty( $userdata->user_firstname ) ) {
		$telegram_bot->send_message( "El usuario $userdata->user_firstname se ha registrado con el correo $userdata->user_email" );
	} else {
		$telegram_bot->send_message( "Nuevo usuario registrado con el correo $userdata->user_email" );
	}

}

function vtb_my_tank( int $order_id ) {
	if ( ! notified( $order_id ) ) {
		$telegram_bot = new TelegramBot();
		$order        = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		$order_items = $order->get_items( apply_filters( 'woocommerce_purchase_order_item_types', 'line_item' ) );
		//	$downloads             = $order->get_downloadable_items();
		//	$show_downloads        = $order->has_downloadable_item() && $order->is_download_permitted();
		$message = "ID de WooComerce $order_id\n\n";

		$array_keys = array_keys( WC()->checkout()->get_checkout_fields() ["billing"] );
		$values     = WC()->checkout()->get_checkout_fields()["billing"];

		foreach ( $array_keys as $key ) {
			if ( empty( $order->get_meta( $key ) ) ) {
				$message = $message . "<b>" . ( $values[ $key ]["label"] . ":</b> " . $order->get_base_data()["billing"][ str_replace( "billing_", "", $key ) ] . "\n" );
			} else {
				$message = $message . "<b>" . ( $values[ $key ]["label"] . ":</b> " . $order->get_meta( $key ) . "\n" );
			}
		}

		$message = $message . "\n<b>Pedido</b>:\n\n";

		$total = 0;
		foreach ( $order_items as $order_item ) {
			$message = $message . $order_item->get_name() . " x " . $order_item->get_quantity() . "     " . $order_item->get_data()["subtotal"] . "$\n";
			$total   += $order_item->get_data()["subtotal"];
		}


		$message = $message . "Costos de envío:    " . $order->calculate_shipping() . "$\n";

		$message = $message . "\n";
		$total   += $order->calculate_shipping();

		$message = $message . make_bold( "Total:    " ) . $total . "$\n";
		$message = $message . "\n";

		if ( ! empty( $order->get_user() ) ) {
			if ( ! empty( $order->get_user()->user_email ) ) {
				$message = $message . "<b>" . "Es un usuario, con correo: " . $order->get_user()->user_email . "</b>\n";
			}
		}

		$message = $message . $order->get_customer_ip_address() . "\n";
		$message = $message . $order->get_customer_user_agent() . "\n";
		if ( $order->get_customer_note() ) {
			$message = $message . $order->get_customer_note() . "\n";
		}
		$message = $message . "\n <b>Fecha de pedido:</b>" . $order->get_date_created();
		$telegram_bot->send_message( $message );
	}
}

function vtb_my_cancelled( int $order_id ) {
	$telegram_bot = new TelegramBot();
	$order        = wc_get_order( $order_id );

	if ( ! $order ) {
		return;
	}

	$order_items = $order->get_items( apply_filters( 'woocommerce_purchase_order_item_types', 'line_item' ) );
	//	$downloads             = $order->get_downloadable_items();
	//	$show_downloads        = $order->has_downloadable_item() && $order->is_download_permitted();

	$message = "<b>La orden $order_id ha sido cancelada </b>\n\n";

	$array_keys = array_keys( WC()->checkout()->get_checkout_fields() ["billing"] );
	$values     = WC()->checkout()->get_checkout_fields()["billing"];

	foreach ( $array_keys as $key ) {
		if ( empty( $order->get_meta( $key ) ) ) {
			$message = $message . "<b>" . ( $values[ $key ]["label"] . ":</b> " . $order->get_base_data()["billing"][ str_replace( "billing_", "", $key ) ] . "\n" );
		} else {
			$message = $message . "<b>" . ( $values[ $key ]["label"] . ":</b> " . $order->get_meta( $key ) . "\n" );
		}
	}

	$message = $message . "\n<b>Pedido</b>:\n\n";

	foreach ( $order_items as $order_item ) {
		$message = $message . $order_item->get_name() . "x" . $order_item->get_quantity() . " " . $order_item->get_data()["subtotal"] . "\n";
	}
	$message = $message . "\n";

	if ( ! empty( $order->get_user() ) ) {
		if ( ! empty( $order->get_user()->user_email ) ) {
			$message = $message . "<b>" . "Es un usuario, con correo: " . $order->get_user()->user_email . "</b>\n";
		}
	}

	$message = $message . $order->get_customer_ip_address() . "\n";
	$message = $message . $order->get_customer_user_agent() . "\n";

	if ( $order->get_customer_note() ) {
		$message = $message . $order->get_customer_note() . "\n";
	}

	$message = $message . "\n <b>Fecha de pedido:</b>" . $order->get_date_created();

	$telegram_bot->send_message( $message );
}

function make_bold( string $text ): string {
	return "<b>" . $text . "</b>";
}

function vtb_form_send_completed( int $formid ) {

	Forminator_API::initialize();
	$telegram_bot = new TelegramBot();
	$form         = Forminator_API::get_form( $formid );
	$fields       = $form->get_fields();
	$entry        = Forminator_API::get_form_entries( $formid )[0];
	$message      = make_bold( $form->name ) . " debug id #" . $entry->entry_id . "\n";
	$first        = false;
	$last         = false;

	foreach ( $fields as $field ) {
		$slug = $field->slug;
		if ( ! empty( $entry->get_meta( $slug ) ) ) {
			$message = $message . make_bold( $form->get_field( $slug )["field_label"] . ": " )
			           . $entry->get_meta( $slug ) . "\n";
		}

		if ( $form->get_field( $slug )["field_label"] == "Fecha de recepcion del auto" ) {
			$first = $entry->get_meta( $slug );
		}
		if ( $form->get_field( $slug )["field_label"] == "Fecha de devolucion del auto" ) {
			$last = $entry->get_meta( $slug );
		}
	}
	if ( $first && $last ) {
		// Creates DateTime objects
		$datetime1 = date_create( $first );
		$datetime2 = date_create( $last );

		// Calculates the difference between DateTime objects
		$interval = date_diff( $datetime1, $datetime2 );

		// Printing result in years & months format
		$message = $message . make_bold( "Cantidad de dias rentado: " ) . $interval->days . "\n";
	}
	//send time
	$json_decode = json_decode( wp_remote_retrieve_body( wp_remote_get( "http://worldtimeapi.org/api/timezone/America/Havana" ) ) );
	$date        = date( DATE_COOKIE );

	if ( isset( $json_decode ) ) {
		$date = $json_decode->datetime;
	}

	if ($form->name=="checkout"){
		$cart=WC()->cart;
		if ($cart!=null){
			$cart->check_cart_items();
			WC()->cart->check_cart_coupons();
			if ($cart->get_cart_contents_total()==0){
				Forminator_API::delete_entry($formid,$entry->entry_id);
				wp_redirect( get_permalink(998) );
				error_log("algo");
				die("El carrito esta vacio");
			}
			$contents=$cart->get_cart_contents();
			error_log("carrito");
			if ($contents!=null){
				$message.= "\n".make_bold("Pedido:")."\n\n";
				foreach ($contents as $i){
					$name=$i["data"]->name;
					$cant=$i["quantity"];
					$total=$i["line_subtotal"];
					$message = $message . $name . " x " . $cant . "      " . $total . "USD\n";
				}

				$message .= "\nTotal: " . $cart->get_cart_contents_total() . "USD\n\n";
			}
		}
		WC()->cart->empty_cart();
	}
	$message = $message . "\n" . "$date" . "\n" . $entry->get_meta( "_forminator_user_ip" );
	if (strlen($message)>1020){
		$telegram_bot->send_message( substr($message,0,1020));
		$telegram_bot->send_message( substr($message,1020));
	}
	else {
		$telegram_bot->send_message( $message );
	}
}

function vtb_checkout_no_prod() {

	if ( WC()->cart->get_cart_contents_total()==0 && is_page( 2722 ) )
	{
		wp_redirect( get_permalink( 998 ) );
	}

}
function add_edit_product( string $new_status, string $old_status, WP_Post $post ) {
	$telegram_bot = new TelegramBot();
	$telegram_bot->send_debug_message($post->ID.
	                                  "\npost type:".$post->post_type.
	                                  "\nold status:".$old_status.
	                                  "\nnew status:". $new_status.
	                                  "\npost:".$post->post_title);
	if ( 'product' == $post->post_type && 'publish' !== $old_status && 'publish' == $new_status ) {
		vtb_my_prod_notifier( $post->ID );
	}
	else if ($post->post_type=='post' && 'publish' !== $old_status && 'publish' == $new_status){
		vtb_my_post_notifier( $post );
	}
}

function vtb_my_prod_notifier( int $id ) {
	$telegram_bot = new TelegramBot();
	$product      = wc_get_product( $id );
	if ( ! is_object( $product ) ) {
		return;
	}
	$image = wp_get_attachment_image_src( $product->get_image_id() )[0];
	$link = $product->get_permalink();
	$message = make_bold( "Nuevo producto:\n" . $product->get_name() )
	           . make_bold( "\nPrecio: " ) . $product->get_price() .
	           make_bold( "\n\n Ver en nuestra pagina: \n" ) . " <a href=\"$link\">$link</a> \n";
	$telegram_bot->send_image( $message, $image );
}

