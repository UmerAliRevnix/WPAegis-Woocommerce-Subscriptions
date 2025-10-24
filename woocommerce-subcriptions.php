<?php
/*
Plugin Name:WooCommerce Subscriptions
Plugin URI: https://github.com/UmerAliRevnix/WPAegis-Woocommerce-Subscriptions
Description: Custom plugin integrating WooCommerce subscriptions with WPAegis.
Version: 1.0
Author: Umer Ali
Author URI: https://github.com/UmerAliRevnix
License: GPLv2 or later
*/



/**
 * WooCommerce Manual Subscription System (v3)
 * Includes expiry logic, duration dropdown, conditional display, and reminder emails.
 */

// =====================================================
// 1️⃣ Add “Subscription Product” checkbox & duration dropdown
// =====================================================
add_action('woocommerce_product_options_general_product_data', function() {
    echo '<div class="options_group">';

    // Subscription checkbox
    woocommerce_wp_checkbox([
        'id' => '_is_subscription_product',
        'label' => __('Subscription Product', 'woocommerce'),
        'description' => __('Enable this product as a subscription.', 'woocommerce'),
    ]);

    // Subscription duration dropdown
    woocommerce_wp_select([
        'id' => '_subscription_duration',
        'label' => __('Subscription Duration', 'woocommerce'),
        'options' => [
            '' => __('Select Duration', 'woocommerce'),
            '1 month' => __('1 Month', 'woocommerce'),
            '1 year'  => __('1 Year', 'woocommerce'),
        ],
        'desc_tip' => true,
        'description' => __('Choose how long this subscription lasts after purchase.', 'woocommerce'),
    ]);

    echo '</div>';
});

// Save product meta
add_action('woocommerce_process_product_meta', function($post_id) {
    $is_sub = isset($_POST['_is_subscription_product']) ? 'yes' : 'no';
    update_post_meta($post_id, '_is_subscription_product', $is_sub);

    if (!empty($_POST['_subscription_duration'])) {
        update_post_meta($post_id, '_subscription_duration', sanitize_text_field($_POST['_subscription_duration']));
    }
});

// =====================================================
// 2️⃣ Set expiry date when subscription order created
// =====================================================
add_action('woocommerce_thankyou', function($order_id) {
    if (!$order_id) return;
    $order = wc_get_order($order_id);
    if (!$order) return;

    $has_subscription = false;

    foreach ($order->get_items() as $item) {
        $product = $item->get_product();

        if (get_post_meta($product->get_id(), '_is_subscription_product', true) === 'yes') {
            $has_subscription = true;

            $duration = get_post_meta($product->get_id(), '_subscription_duration', true);
            $expiry_date = ($duration === '1 month')
                ? date('Y-m-d', strtotime('+1 month'))
                : date('Y-m-d', strtotime('+1 year'));

            update_post_meta($order_id, '_subscription_expiry', $expiry_date);

            // Schedule reminder (7 days before) and expiry
            wp_schedule_single_event(strtotime('-7 days', strtotime($expiry_date)), 'send_subscription_reminder_event', [$order_id]);
            wp_schedule_single_event(strtotime($expiry_date . ' 00:00:00'), 'check_subscription_expiry_event', [$order_id]);
        }
    }

    // Remove expiry if no subscription products are found
    if (!$has_subscription) {
        delete_post_meta($order_id, '_subscription_expiry');
    }
});

// =====================================================
// 3️⃣ Handle reminder and expiry emails
// =====================================================
if (!function_exists('send_subscription_email')) {
    function send_subscription_email($order_id, $type = 'reminder') {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $mailer = WC()->mailer();
        $recipient = $order->get_billing_email();
        $expiry_date = get_post_meta($order_id, '_subscription_expiry', true);

        if ($type === 'reminder') {
            $subject = 'Your subscription will expire in 7 days';
            $message = 'Hi ' . esc_html($order->get_billing_first_name()) . ',<br><br>'
                . 'Your subscription will expire in <strong>7 days</strong>.<br>'
                . 'Please renew it to avoid interruption.<br><br>'
                . '<strong>Expiry Date:</strong> ' . esc_html($expiry_date) . '<br><br>'
                . '<a href="' . esc_url(get_renewal_url($order_id)) . '" '
                . 'style="background:#2f8f2f;color:#fff;padding:10px 20px;text-decoration:none;border-radius:6px;">Renew Subscription</a><br><br>'
                . 'Thank you,<br>' . get_bloginfo('name');
        } else {
            $subject = 'Your subscription has expired';
            $message = 'Hi ' . esc_html($order->get_billing_first_name()) . ',<br><br>'
                . 'Your subscription expired on <strong>' . esc_html($expiry_date) . '</strong>.<br>'
                . 'You can renew anytime using the button below.<br><br>'
                . '<a href="' . esc_url(get_renewal_url($order_id)) . '" '
                . 'style="background:#c33;color:#fff;padding:10px 20px;text-decoration:none;border-radius:6px;">Renew Now</a><br><br>'
                . 'Regards,<br>' . get_bloginfo('name');
        }

        $wrapped = $mailer->wrap_message($subject, $message);
        $mailer->send($recipient, $subject, $wrapped);
    }
}

// Expiry handling
add_action('check_subscription_expiry_event', function($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;
    $expiry_date = get_post_meta($order->get_id(), '_subscription_expiry', true);
    if ($expiry_date && strtotime($expiry_date) <= current_time('timestamp')) {
        $order->update_status('completed', 'Subscription expired automatically.');
        send_subscription_email($order_id, 'expired');
    }
});

// Reminder email
add_action('send_subscription_reminder_event', function($order_id) {
    send_subscription_email($order_id, 'reminder');
});

// =====================================================
// 4️⃣ Show expiry date in My Account and View Order (only if subscription)
// =====================================================
add_action('woocommerce_order_details_after_order_table', function($order) {
    $expiry_date = get_post_meta($order->get_id(), '_subscription_expiry', true);
    $has_subscription = false;

    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if ($product && get_post_meta($product->get_id(), '_is_subscription_product', true) === 'yes') {
            $has_subscription = true;
            break;
        }
    }

    if ($has_subscription && $expiry_date) {
        echo '<p><strong>Subscription Expiry Date:</strong> ' . esc_html($expiry_date) . '</p>';
    }
});

// Add expiry column in My Account > Orders
add_filter('woocommerce_my_account_my_orders_columns', function($columns) {
    $columns['subscription_expiry'] = __('Expiry Date', 'woocommerce');
    return $columns;
});

add_action('woocommerce_my_account_my_orders_column_subscription_expiry', function($order) {
    $expiry_date = get_post_meta($order->get_id(), '_subscription_expiry', true);
    $has_subscription = false;

    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if ($product && get_post_meta($product->get_id(), '_is_subscription_product', true) === 'yes') {
            $has_subscription = true;
            break;
        }
    }

    echo $has_subscription && $expiry_date ? esc_html($expiry_date) : '-';
});

// =====================================================
// 5️⃣ Renewal URL helper
// =====================================================
if (!function_exists('get_renewal_url')) {
    function get_renewal_url($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return home_url();
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product) {
                return wc_get_checkout_url() . '?add-to-cart=' . $product->get_id();
            }
        }
        return home_url();
    }
}

// =====================================================
// 6️⃣ Empty cart after successful order
// =====================================================
add_action('woocommerce_thankyou', function() {
    WC()->cart->empty_cart();
});

// =====================================================
// 7️⃣ Prevent multiple subscription products in cart
// =====================================================
add_filter('woocommerce_add_to_cart_validation', function($passed, $product_id, $quantity) {
    $is_subscription = get_post_meta($product_id, '_is_subscription_product', true) === 'yes';

    if ($is_subscription && !WC()->cart->is_empty()) {
        foreach (WC()->cart->get_cart() as $cart_item_key => $values) {
            $existing_id = $values['product_id'];
            if (get_post_meta($existing_id, '_is_subscription_product', true) === 'yes') {
                WC()->cart->remove_cart_item($cart_item_key); // Remove old sub product
            }
        }
    }
    return true;
}, 10, 3);
