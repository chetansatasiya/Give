<?php
/**
 * Customer (Donors)
 *
 * @package     Give
 * @subpackage  Admin/Customers
 * @copyright   Copyright (c) 2016, WordImpress
 * @license     https://opensource.org/licenses/gpl-license GNU Public License
 * @since       1.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Processes a donor edit.
 *
 * @since  1.0
 *
 * @param  array $args The $_POST array being passed
 *
 * @return array|bool $output Response messages
 */
function give_edit_donor( $args ) {

	$donor_edit_role = apply_filters( 'give_edit_donors_role', 'edit_give_payments' );

	if ( ! is_admin() || ! current_user_can( $donor_edit_role ) ) {
		wp_die( __( 'You do not have permission to edit this donor.', 'give' ), __( 'Error', 'give' ), array(
			'response' => 403,
		) );
	}

	if ( empty( $args ) ) {
		return false;
	}

	$donor_info = $args['customerinfo'];
	$donor_id   = (int) $args['customerinfo']['id'];
	$nonce      = $args['_wpnonce'];

	if ( ! wp_verify_nonce( $nonce, 'edit-customer' ) ) {
		wp_die( __( 'Cheatin&#8217; uh?', 'give' ), __( 'Error', 'give' ), array(
			'response' => 400,
		) );
	}

	$donor = new Give_Customer( $donor_id );

    if ( empty( $donor->id ) ) {
		return false;
	}

	$defaults = array(
		'name'    => '',
		'user_id' => 0,
	);

	$donor_info = wp_parse_args( $donor_info, $defaults );

	if ( (int) $donor_info['user_id'] !== (int) $donor->user_id ) {

		// Make sure we don't already have this user attached to a donor.
		if ( ! empty( $donor_info['user_id'] ) && false !== Give()->customers->get_customer_by( 'user_id', $donor_info['user_id'] ) ) {
			give_set_error( 'give-invalid-customer-user_id', sprintf( __( 'The User ID #%d is already associated with a different donor.', 'give' ), $donor_info['user_id'] ) );
		}

		// Make sure it's actually a user.
		$user = get_user_by( 'id', $donor_info['user_id'] );
		if ( ! empty( $donor_info['user_id'] ) && false === $user ) {
			give_set_error( 'give-invalid-user_id', sprintf( __( 'The User ID #%d does not exist. Please assign an existing user.', 'give' ), $donor_info['user_id'] ) );
		}
	}

	// Record this for later.
	$previous_user_id = $donor->user_id;

	if ( give_get_errors() ) {
		return false;
	}

	// Setup the donor address, if present.
	$address = array();
	if ( intval( $donor_info['user_id'] ) > 0 ) {

		$current_address = get_user_meta( $donor_info['user_id'], '_give_user_address', true );

		if ( false === $current_address ) {
			$address['line1']   = isset( $donor_info['line1'] ) ? $donor_info['line1'] : '';
			$address['line2']   = isset( $donor_info['line2'] ) ? $donor_info['line2'] : '';
			$address['city']    = isset( $donor_info['city'] ) ? $donor_info['city'] : '';
			$address['country'] = isset( $donor_info['country'] ) ? $donor_info['country'] : '';
			$address['zip']     = isset( $donor_info['zip'] ) ? $donor_info['zip'] : '';
			$address['state']   = isset( $donor_info['state'] ) ? $donor_info['state'] : '';
		} else {
			$current_address    = wp_parse_args( $current_address, array(
				'line1',
				'line2',
				'city',
				'zip',
				'state',
				'country',
			) );
			$address['line1']   = isset( $donor_info['line1'] ) ? $donor_info['line1'] : $current_address['line1'];
			$address['line2']   = isset( $donor_info['line2'] ) ? $donor_info['line2'] : $current_address['line2'];
			$address['city']    = isset( $donor_info['city'] ) ? $donor_info['city'] : $current_address['city'];
			$address['country'] = isset( $donor_info['country'] ) ? $donor_info['country'] : $current_address['country'];
			$address['zip']     = isset( $donor_info['zip'] ) ? $donor_info['zip'] : $current_address['zip'];
			$address['state']   = isset( $donor_info['state'] ) ? $donor_info['state'] : $current_address['state'];
		}
	}

	// Sanitize the inputs
	$donor_data            = array();
	$donor_data['name']    = strip_tags( stripslashes( $donor_info['name'] ) );
	$donor_data['user_id'] = $donor_info['user_id'];

	$donor_data = apply_filters( 'give_edit_donor_info', $donor_data, $donor_id );
	$address    = apply_filters( 'give_edit_donor_address', $address, $donor_id );

	$donor_data = array_map( 'sanitize_text_field', $donor_data );
	$address    = array_map( 'sanitize_text_field', $address );

	/**
	 * Fires before editing a donor.
	 *
	 * @since 1.0
	 *
	 * @param int $donor_id The ID of the donor.
	 * @param array $donor_data The donor data.
	 * @param array $address The donor's address.
	 */
	do_action( 'give_pre_edit_donor', $donor_id, $donor_data, $address );

	$output = array();

	if ( $donor->update( $donor_data ) ) {

		if ( ! empty( $donor->user_id ) && $donor->user_id > 0 ) {
			update_user_meta( $donor->user_id, '_give_user_address', $address );
		}

		// Update some donation meta if we need to.
		$payments_array = explode( ',', $donor->payment_ids );

		if ( $donor->user_id != $previous_user_id ) {
			foreach ( $payments_array as $payment_id ) {
				give_update_payment_meta( $payment_id, '_give_payment_user_id', $donor->user_id );
			}
		}

		$output['success']       = true;
		$donor_data              = array_merge( $donor_data, $address );
		$output['customer_info'] = $donor_data;

	} else {

		$output['success'] = false;

	}

	/**
	 * Fires after editing a donor.
	 *
	 * @since 1.0
	 *
	 * @param int $donor_id The ID of the donor.
	 * @param array $donor_data The donor data.
	 */
	do_action( 'give_post_edit_donor', $donor_id, $donor_data );

	if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
		header( 'Content-Type: application/json' );
		echo json_encode( $output );
		wp_die();
	}

	return $output;

}

add_action( 'give_edit-customer', 'give_edit_donor', 10, 1 );

/**
 * Save a customer note being added
 *
 * @since  1.0
 *
 * @param  array $args The $_POST array being passed.
 *
 * @return int         The Note ID that was saved, or 0 if nothing was saved
 */
function give_customer_save_note( $args ) {

	$customer_view_role = apply_filters( 'give_view_customers_role', 'view_give_reports' );

	if ( ! is_admin() || ! current_user_can( $customer_view_role ) ) {
		wp_die( esc_html__( 'You do not have permission to edit this donor.', 'give' ), esc_html__( 'Error', 'give' ), array(
			'response' => 403,
		) );
	}

	if ( empty( $args ) ) {
		return;
	}

	$customer_note = trim( sanitize_text_field( $args['customer_note'] ) );
	$customer_id   = (int) $args['customer_id'];
	$nonce         = $args['add_customer_note_nonce'];

	if ( ! wp_verify_nonce( $nonce, 'add-customer-note' ) ) {
		wp_die( esc_html__( 'Cheatin&#8217; uh?', 'give' ), esc_html__( 'Error', 'give' ), array(
			'response' => 400,
		) );
	}

	if ( empty( $customer_note ) ) {
		give_set_error( 'empty-customer-note', esc_html__( 'A note is required.', 'give' ) );
	}

	if ( give_get_errors() ) {
		return;
	}

	$customer = new Give_Customer( $customer_id );
	$new_note = $customer->add_note( $customer_note );

	/**
	 * Fires before inserting customer note.
	 *
	 * @since 1.0
	 *
	 * @param int $customer_id The ID of the customer.
	 * @param string $new_note Note content.
	 */
	do_action( 'give_pre_insert_customer_note', $customer_id, $new_note );

	if ( ! empty( $new_note ) && ! empty( $customer->id ) ) {

		ob_start();
		?>
		<div class="customer-note-wrapper dashboard-comment-wrap comment-item">
			<span class="note-content-wrap">
				<?php echo stripslashes( $new_note ); ?>
			</span>
		</div>
		<?php
		$output = ob_get_contents();
		ob_end_clean();

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			echo $output;
			exit;
		}

		return $new_note;

	}

	return false;

}

add_action( 'give_add-customer-note', 'give_customer_save_note', 10, 1 );

/**
 * Delete a customer
 *
 * @since  1.0
 *
 * @param  array $args The $_POST array being passed
 *
 * @return int Whether it was a successful deletion
 */
function give_customer_delete( $args ) {

	$customer_edit_role = apply_filters( 'give_edit_donors_role', 'edit_give_payments' );

	if ( ! is_admin() || ! current_user_can( $customer_edit_role ) ) {
		wp_die( esc_html__( 'You do not have permission to delete donors.', 'give' ), esc_html__( 'Error', 'give' ), array(
			'response' => 403,
		) );
	}

	if ( empty( $args ) ) {
		return;
	}

	$customer_id = (int) $args['customer_id'];
	$confirm     = ! empty( $args['give-customer-delete-confirm'] ) ? true : false;
	$remove_data = ! empty( $args['give-customer-delete-records'] ) ? true : false;
	$nonce       = $args['_wpnonce'];

	if ( ! wp_verify_nonce( $nonce, 'delete-customer' ) ) {
		wp_die( esc_html__( 'Cheatin&#8217; uh?', 'give' ), esc_html__( 'Error', 'give' ), array(
			'response' => 400,
		) );
	}

	if ( ! $confirm ) {
		give_set_error( 'customer-delete-no-confirm', esc_html__( 'Please confirm you want to delete this donor.', 'give' ) );
	}

	if ( give_get_errors() ) {
		wp_redirect( admin_url( 'edit.php?post_type=give_forms&page=give-donors&view=overview&id=' . $customer_id ) );
		exit;
	}

	$customer = new Give_Customer( $customer_id );

	/**
	 * Fires before deleting customer.
	 *
	 * @since 1.0
	 *
	 * @param int $customer_id The ID of the customer.
	 * @param bool $confirm Delete confirmation.
	 * @param bool $remove_data Records delete confirmation.
	 */
	do_action( 'give_pre_delete_customer', $customer_id, $confirm, $remove_data );

	if ( $customer->id > 0 ) {

		$payments_array = explode( ',', $customer->payment_ids );
		$success        = Give()->customers->delete( $customer->id );

		if ( $success ) {

			if ( $remove_data ) {

				// Remove all donations, logs, etc
				foreach ( $payments_array as $payment_id ) {
					give_delete_purchase( $payment_id );
				}
			} else {

				// Just set the donations to customer_id of 0
				foreach ( $payments_array as $payment_id ) {
					give_update_payment_meta( $payment_id, '_give_payment_customer_id', 0 );
				}
			}

			$redirect = admin_url( 'edit.php?post_type=give_forms&page=give-donors&give-message=customer-deleted' );

		} else {

			give_set_error( 'give-donor-delete-failed', esc_html__( 'Error deleting donor.', 'give' ) );
			$redirect = admin_url( 'edit.php?post_type=give_forms&page=give-donors&view=delete&id=' . $customer_id );

		}
	} else {

		give_set_error( 'give-customer-delete-invalid-id', esc_html__( 'Invalid Donor ID.', 'give' ) );
		$redirect = admin_url( 'edit.php?post_type=give_forms&page=give-donors' );

	}

	wp_redirect( $redirect );
	exit;

}

add_action( 'give_delete-customer', 'give_customer_delete', 10, 1 );

/**
 * Disconnect a user ID from a donor
 *
 * @since  1.0
 *
 * @param  array $args Array of arguments.
 *
 * @return bool|array        If the disconnect was successful.
 */
function give_disconnect_donor_user_id( $args ) {

	$donor_edit_role = apply_filters( 'give_edit_donors_role', 'edit_give_payments' );

	if ( ! is_admin() || ! current_user_can( $donor_edit_role ) ) {
		wp_die( __( 'You do not have permission to edit this donor.', 'give' ), __( 'Error', 'give' ), array(
			'response' => 403,
		) );
	}

	if ( empty( $args ) ) {
		return false;
	}

	$donor_id = (int) $args['customer_id'];

	$nonce       = $args['_wpnonce'];

	if ( ! wp_verify_nonce( $nonce, 'edit-customer' ) ) {
		wp_die( __( 'Cheatin&#8217; uh?', 'give' ), __( 'Error', 'give' ), array(
			'response' => 400,
		) );
	}

	$donor = new Give_Customer( $donor_id );
	if ( empty( $donor->id ) ) {
		return false;
	}

	$user_id = $donor->user_id;

	/**
	 * Fires before disconnecting user ID from a donor.
	 *
	 * @since 1.0
	 *
	 * @param int $donor_id The ID of the donor.
	 * @param int $user_id The ID of the user.
	 */
	do_action( 'give_pre_donor_disconnect_user_id', $donor_id, $user_id );

	$output        = array();
	$donor_args = array(
		'user_id' => 0,
	);

	if ( $donor->update( $donor_args ) ) {
		global $wpdb;

		if ( ! empty( $donor->payment_ids ) ) {
			$wpdb->query( "UPDATE $wpdb->postmeta SET meta_value = 0 WHERE meta_key = '_give_payment_user_id' AND post_id IN ( $donor->payment_ids )" );
		}

		$output['success'] = true;

	} else {

		$output['success'] = false;
		give_set_error( 'give-disconnect-user-fail', __( 'Failed to disconnect user from donor.', 'give' ) );
	}

	/**
	 * Fires after disconnecting user ID from a donor.
	 *
	 * @since 1.0
	 *
	 * @param int $donor_id The ID of the donor.
	 */
	do_action( 'give_post_donor_disconnect_user_id', $donor_id );

	if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
		header( 'Content-Type: application/json' );
		echo json_encode( $output );
		wp_die();
	}

	return $output;

}

add_action( 'give_disconnect-userid', 'give_disconnect_donor_user_id', 10, 1 );

/**
 * Add an email address to the donor from within the admin and log a donor note
 *
 * @since  1.7
 *
 * @param  array $args Array of arguments: nonce, customer id, and email address
 *
 * @return mixed        If DOING_AJAX echos out JSON, otherwise returns array of success (bool) and message (string)
 */
function give_add_donor_email( $args ) {
	$donor_edit_role = apply_filters( 'give_edit_donors_role', 'edit_give_payments' );

	if ( ! is_admin() || ! current_user_can( $donor_edit_role ) ) {
		wp_die( __( 'You do not have permission to edit this donor.', 'edit' ) );
	}

	$output = array();
	if ( empty( $args ) || empty( $args['email'] ) || empty( $args['customer_id'] ) ) {
		$output['success'] = false;
		if ( empty( $args['email'] ) ) {
			$output['message'] = __( 'Email address is required.', 'give' );
		} elseif ( empty( $args['customer_id'] ) ) {
			$output['message'] = __( 'Donor ID is required.', 'give' );
		} else {
			$output['message'] = __( 'An error has occurred. Please try again.', 'give' );
		}
	} elseif ( ! wp_verify_nonce( $args['_wpnonce'], 'give_add_donor_email' ) ) {
		$output = array(
			'success' => false,
			'message' => esc_html__( 'Nonce verification failed.', 'give' ),
		);
	} elseif ( ! is_email( $args['email'] ) ) {
		$output = array(
			'success' => false,
			'message' => esc_html__( 'Invalid email.', 'give' ),
		);
	} else {
		$email       = sanitize_email( $args['email'] );
		$customer_id = (int) $args['customer_id'];
		$primary     = 'true' === $args['primary'] ? true : false;
		$customer    = new Give_Customer( $customer_id );
		if ( false === $customer->add_email( $email, $primary ) ) {
			if ( in_array( $email, $customer->emails ) ) {
				$output = array(
					'success' => false,
					'message' => __( 'Email already associated with this donor.', 'give' ),
				);
			} else {
				$output = array(
					'success' => false,
					'message' => __( 'Email address is already associated with another donor.', 'give' ),
				);
			}
		} else {
			$redirect = admin_url( 'edit.php?post_type=give_forms&page=give-donors&view=overview&id=' . $customer_id . '&give-message=email-added' );
			$output   = array(
				'success'  => true,
				'message'  => __( 'Email successfully added to donor.', 'give' ),
				'redirect' => $redirect,
			);

			$user          = wp_get_current_user();
			$user_login    = ! empty( $user->user_login ) ? $user->user_login : __( 'System', 'give' );
			$customer_note = sprintf( __( 'Email address %1$s added by %2$s', 'give' ), $email, $user_login );
			$customer->add_note( $customer_note );

			if ( $primary ) {
				$customer_note = sprintf( __( 'Email address %1$s set as primary by %2$s', 'give' ), $email, $user_login );
				$customer->add_note( $customer_note );
			}
		}
	}// End if().

	do_action( 'give_post_add_customer_email', $customer_id, $args );

	if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
		header( 'Content-Type: application/json' );
		echo json_encode( $output );
		wp_die();
	}

	return $output;
}

add_action( 'give_add_donor_email', 'give_add_donor_email', 10, 1 );


/**
 * Remove an email address to the donor from within the admin and log a donor note
 * and redirect back to the donor interface for feedback
 *
 * @since  1.7
 * @return bool|null
 */
function give_remove_donor_email() {
	if ( empty( $_GET['id'] ) || ! is_numeric( $_GET['id'] ) ) {
		return false;
	}
	if ( empty( $_GET['email'] ) || ! is_email( $_GET['email'] ) ) {
		return false;
	}
	if ( empty( $_GET['_wpnonce'] ) ) {
		return false;
	}

	$nonce = $_GET['_wpnonce'];
	if ( ! wp_verify_nonce( $nonce, 'give-remove-donor-email' ) ) {
		wp_die( esc_html__( 'Nonce verification failed', 'give' ), esc_html__( 'Error', 'give' ), array(
			'response' => 403,
		) );
	}

	$customer = new Give_Customer( $_GET['id'] );
	if ( $customer->remove_email( $_GET['email'] ) ) {
		$url           = add_query_arg( 'give-message', 'email-removed', admin_url( 'edit.php?post_type=give_forms&page=give-donors&view=overview&id=' . $customer->id ) );
		$user          = wp_get_current_user();
		$user_login    = ! empty( $user->user_login ) ? $user->user_login : esc_html__( 'System', 'give' );
		$customer_note = sprintf( __( 'Email address %1$s removed by %2$s', 'give' ), $_GET['email'], $user_login );
		$customer->add_note( $customer_note );
	} else {
		$url = add_query_arg( 'give-message', 'email-remove-failed', admin_url( 'edit.php?post_type=give_forms&page=give-donors&view=overview&id=' . $customer->id ) );
	}

	wp_safe_redirect( $url );
	exit;
}

add_action( 'give_remove_donor_email', 'give_remove_donor_email', 10 );


/**
 * Set an email address as the primary for a donor from within the admin and log a donor note
 * and redirect back to the donor interface for feedback
 *
 * @since  1.7
 * @return bool|null
 */
function give_set_donor_primary_email() {
	if ( empty( $_GET['id'] ) || ! is_numeric( $_GET['id'] ) ) {
		return false;
	}

	if ( empty( $_GET['email'] ) || ! is_email( $_GET['email'] ) ) {
		return false;
	}

	if ( empty( $_GET['_wpnonce'] ) ) {
		return false;
	}

	$nonce = $_GET['_wpnonce'];

	if ( ! wp_verify_nonce( $nonce, 'give-set-donor-primary-email' ) ) {
		wp_die( esc_html__( 'Nonce verification failed', 'give' ), esc_html__( 'Error', 'give' ), array(
			'response' => 403,
		) );
	}

	$donor = new Give_Customer( $_GET['id'] );

	if ( $donor->set_primary_email( $_GET['email'] ) ) {
		$url        = add_query_arg( 'give-message', 'primary-email-updated', admin_url( 'edit.php?post_type=give_forms&page=give-donors&view=overview&id=' . $donor->id ) );
		$user       = wp_get_current_user();
		$user_login = ! empty( $user->user_login ) ? $user->user_login : esc_html__( 'System', 'give' );
		$donor_note = sprintf( __( 'Email address %1$s set as primary by %2$s', 'give' ), $_GET['email'], $user_login );

		$donor->add_note( $donor_note );
	} else {
		$url = add_query_arg( 'give-message', 'primary-email-failed', admin_url( 'edit.php?post_type=give_forms&page=give-donors&view=overview&id=' . $donor->id ) );
	}

	wp_safe_redirect( $url );
	exit;
}

add_action( 'give_set_donor_primary_email', 'give_set_donor_primary_email', 10 );
