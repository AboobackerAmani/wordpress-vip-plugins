<?php

/* thePlatform Video Manager Wordpress Plugin
  Copyright (C) 2013-2015 thePlatform, LLC

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License as published by
  the Free Software Foundation; either version 2 of the License, or
  (at your option) any later version.

  This program is distributed in the hope that it will be useful,
  but WITHOUT ANY WARRANTY; without even the implied warranty of
  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  GNU General Public License for more details.

  You should have received a copy of the GNU General Public License along
  with this program; if not, write to the Free Software Foundation, Inc.,
  51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA. */


if ( ! isset( $account ) ) {
	$account = get_option( TP_ACCOUNT_OPTIONS_KEY );
}

/**
 * This class is responsible for uploading and publishing Media to MPX
 */
class ThePlatform_Proxy {

	function __construct() {
		if ( is_admin() ) {
			add_action( 'wp_ajax_publish_media', array( $this, 'publish_media' ) );
			add_action( 'wp_ajax_revoke_media', array( $this, 'revoke_media' ) );
		}
	}

	private function check_nonce_and_permissions( $action = "" ) {
		if ( empty( $action ) ) {
			check_admin_referer( 'theplatform-ajax-nonce' );
		} else {
			check_admin_referer( 'theplatform-ajax-nonce-' . $action );
		}

		$tp_uploader_cap = apply_filters( TP_UPLOADER_CAP, TP_UPLOADER_DEFAULT_CAP );
		if ( ! current_user_can( $tp_uploader_cap ) ) {
			wp_die( 'You do not have sufficient permissions to modify MPX Media' );
		}
	}

	private function check_theplatform_proxy_response( $response, $returnsValue = false ) {

		// Check if we got an error back and return it
		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $response->get_error_message() );
		}

		if ( isset( $response['data'] ) && $response['data'] === false ) {
			wp_send_json_error( $response['status']['http_code'] );
		}

		$responseBody = wp_remote_retrieve_body( $response );

		// This AJAX call should not return a value, in this case we send a json error with the body to the UI
		if ( ! $returnsValue && ! empty( $responseBody ) ) {
			wp_send_json_error( theplatform_decode_json_from_server( $response, false ) );
		}

		$parsedResponse = theplatform_decode_json_from_server( $response, false );

		wp_send_json_success( $parsedResponse );
	}

	private function proxy_http_request( $data = array() ) {
		$method = strtolower( $_POST['method'] );
		$url    = $_POST['url'];

		if ( isset( $_POST['cookie_name'] ) ) {
			$data['cookies'] = array(
				new WP_Http_Cookie(
					array(
						'name'  => $_POST['cookie_name'],
						'value' => $_POST['cookie_value']
					)
				)
			);
		}
		switch ( $method ) {
			case 'put':
				$response = ThePlatform_API_HTTP::put( $url, $data );
				break;
			case 'get':
				$response = ThePlatform_API_HTTP::get( $url );
				break;
			case 'post':
				$response = ThePlatform_API_HTTP::post( $url, $data );
				break;
			default:
				$response = array();
				break;
		}

		return $response;
	}

	/**
	 * Publish an uploaded media asset using the 'Wordpress' profile
	 * @return mixed JSON response or instance of WP_Error
	 */
	public function publish_media() {
		$this->check_nonce_and_permissions( $_POST['action'] );

		if ( $_POST['profile'] === 'wp_tp_none' ) {
			wp_send_json_success( "No Publishing Profile Selected" );
		}

		if ( ! isset( $_POST['token]'] ) ) {
			$tp_api = new ThePlatform_API();
			$token  = $tp_api->mpx_signin();
		} else {
			$token = $_POST['token]'];
		}

		$profileId = $_POST['profile'];
		$mediaId   = $_POST['mediaId'];

		$publishUrl = TP_API_PUBLISH_BASE_URL;
		$publishUrl .= '&token=' . urlencode( $token );
		$publishUrl .= '&account=' . urlencode( $_POST['account'] );
		$publishUrl .= '&_mediaId=' . urlencode( $mediaId );
		$publishUrl .= '&_profileId=' . urlencode( $profileId );

		$response = ThePlatform_API_HTTP::get( esc_url_raw( $publishUrl ), array( "timeout" => 120 ) );

		$this->check_theplatform_proxy_response( $response, true );
	}

	/**
	 * Publish an uploaded media asset using the 'Wordpress' profile
	 * @return mixed JSON response or instance of WP_Error
	 */
	public function revoke_media() {
		$this->check_nonce_and_permissions( $_POST['action'] );

		if ( ! isset( $_POST['token]'] ) ) {
			$tp_api = new ThePlatform_API();
			$token  = $tp_api->mpx_signin();
		} else {
			$token = $_POST['token]'];
		}

		$profileId = $_POST['profile'];
		$mediaId   = $_POST['mediaId'];

		$publishUrl = TP_API_REVOKE_BASE_URL;
		$publishUrl .= '&token=' . urlencode( $token );
		$publishUrl .= '&account=' . urlencode( $_POST['account'] );
		$publishUrl .= '&_mediaId=' . urlencode( $mediaId );
		$publishUrl .= '&_profileId=' . urlencode( $profileId );

		$response = ThePlatform_API_HTTP::get( esc_url_raw( $publishUrl ), array( "timeout" => 120 ) );

		$this->check_theplatform_proxy_response( $response, true );
	}
}

new ThePlatform_Proxy();