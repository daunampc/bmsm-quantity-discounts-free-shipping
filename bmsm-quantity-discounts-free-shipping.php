<?php
/**
 * Plugin Name: BMSM Quantity Discounts + Free Shipping
 * Description: Dynamic WooCommerce quantity discount tiers with a custom Free Shipping method when cart quantity reaches the configured threshold.
 * Version: 1.6.0
 * Author: ChatGPT
 * License: GPL-2.0-or-later
 * Text Domain: bmsm-qdfs
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

final class BMSM_QDFS_Plugin {
    const OPTION_KEY = 'bmsm_qdfs_settings';
    const VERSION = '1.6.0';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'handle_save_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'));

        add_action('woocommerce_cart_calculate_fees', array($this, 'apply_quantity_discount'), 20, 1);
        add_filter('woocommerce_package_rates', array($this, 'control_shipping_rates'), 9999, 2);
        add_action('woocommerce_before_cart', array($this, 'show_notice'));
        add_action('woocommerce_before_checkout_form', array($this, 'show_notice'));
    }

    public static function defaults() {
        return array(
            'enable_discounts' => 'yes',
            'tiers' => array(
                array(
                    'enabled' => 'yes',
                    'qty' => 2,
                    'discount' => 5,
                    'label' => 'Buy 2 Save 5%',
                ),
                array(
                    'enabled' => 'yes',
                    'qty' => 3,
                    'discount' => 7,
                    'label' => 'Buy 3 Save 7%',
                ),
            ),
            'enable_free_shipping' => 'yes',
            'free_shipping_qty' => 4,
            'free_shipping_label' => 'Free Shipping',
            'keep_other_shipping_methods' => 'yes',
            'hide_existing_free_shipping_until_eligible' => 'yes',
            'free_shipping_only_mode' => 'yes',
            'enable_notices' => 'yes',
            'notice_unlocked' => 'You unlocked Free Shipping!',
            'notice_next_discount' => 'Add {items_needed} more item(s) to unlock {label}.',
            'notice_next_free_shipping' => 'Add {items_needed} more item(s) to unlock Free Shipping.',
        );
    }

    public static function get_settings() {
        $saved = get_option(self::OPTION_KEY, array());
        $settings = wp_parse_args(is_array($saved) ? $saved : array(), self::defaults());

        if (!isset($settings['tiers']) || !is_array($settings['tiers'])) {
            $settings['tiers'] = self::defaults()['tiers'];
        }

        $settings['tiers'] = self::sanitize_tiers($settings['tiers']);

        return $settings;
    }

    private static function sanitize_tiers($tiers) {
        $clean = array();

        if (!is_array($tiers)) {
            return $clean;
        }

        foreach ($tiers as $tier) {
            if (!is_array($tier)) {
                continue;
            }

            $qty = isset($tier['qty']) ? absint($tier['qty']) : 0;
            $discount = isset($tier['discount']) ? (float) $tier['discount'] : 0;
            $label = isset($tier['label']) ? sanitize_text_field($tier['label']) : '';
            $enabled = isset($tier['enabled']) && $tier['enabled'] === 'yes' ? 'yes' : 'no';

            if ($qty <= 0 || $discount <= 0) {
                continue;
            }

            if ($discount > 100) {
                $discount = 100;
            }

            if ($label === '') {
                $label = sprintf('Buy %d Save %s%%', $qty, rtrim(rtrim(number_format($discount, 2, '.', ''), '0'), '.'));
            }

            $clean[] = array(
                'enabled' => $enabled,
                'qty' => $qty,
                'discount' => $discount,
                'label' => $label,
            );
        }

        usort($clean, function($a, $b) {
            return (int) $a['qty'] <=> (int) $b['qty'];
        });

        return $clean;
    }

    public function add_settings_link($links) {
        $url = admin_url('admin.php?page=bmsm-qdfs-settings');
        $links[] = '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'bmsm-qdfs') . '</a>';
        return $links;
    }

    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Buy More Save More', 'bmsm-qdfs'),
            __('Buy More Save More', 'bmsm-qdfs'),
            'manage_woocommerce',
            'bmsm-qdfs-settings',
            array($this, 'render_settings_page')
        );
    }

    public function enqueue_admin_assets($hook) {
        if ($hook !== 'woocommerce_page_bmsm-qdfs-settings') {
            return;
        }

        wp_enqueue_style('bmsm-qdfs-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', array(), self::VERSION);
        wp_enqueue_script('bmsm-qdfs-admin', plugin_dir_url(__FILE__) . 'assets/admin.js', array('jquery'), self::VERSION, true);
    }

    public function handle_save_settings() {
        if (!isset($_POST['bmsm_qdfs_save_settings'])) {
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        check_admin_referer('bmsm_qdfs_save_settings_action', 'bmsm_qdfs_nonce');

        $tiers = array();
        if (isset($_POST['tiers']) && is_array($_POST['tiers'])) {
            foreach ($_POST['tiers'] as $tier) {
                $tiers[] = array(
                    'enabled' => isset($tier['enabled']) ? 'yes' : 'no',
                    'qty' => isset($tier['qty']) ? absint($tier['qty']) : 0,
                    'discount' => isset($tier['discount']) ? (float) wc_clean(wp_unslash($tier['discount'])) : 0,
                    'label' => isset($tier['label']) ? sanitize_text_field(wp_unslash($tier['label'])) : '',
                );
            }
        }

        $settings = array(
            'enable_discounts' => isset($_POST['enable_discounts']) ? 'yes' : 'no',
            'tiers' => self::sanitize_tiers($tiers),
            'enable_free_shipping' => isset($_POST['enable_free_shipping']) ? 'yes' : 'no',
            'free_shipping_qty' => isset($_POST['free_shipping_qty']) ? max(1, absint($_POST['free_shipping_qty'])) : 4,
            'free_shipping_label' => isset($_POST['free_shipping_label']) ? sanitize_text_field(wp_unslash($_POST['free_shipping_label'])) : 'Free Shipping',
            'keep_other_shipping_methods' => isset($_POST['keep_other_shipping_methods']) ? 'yes' : 'no',
            'hide_existing_free_shipping_until_eligible' => isset($_POST['hide_existing_free_shipping_until_eligible']) ? 'yes' : 'no',
            'free_shipping_only_mode' => isset($_POST['free_shipping_only_mode']) ? 'yes' : 'no',
            'enable_notices' => isset($_POST['enable_notices']) ? 'yes' : 'no',
            'notice_unlocked' => isset($_POST['notice_unlocked']) ? sanitize_text_field(wp_unslash($_POST['notice_unlocked'])) : '',
            'notice_next_discount' => isset($_POST['notice_next_discount']) ? sanitize_text_field(wp_unslash($_POST['notice_next_discount'])) : '',
            'notice_next_free_shipping' => isset($_POST['notice_next_free_shipping']) ? sanitize_text_field(wp_unslash($_POST['notice_next_free_shipping'])) : '',
        );

        update_option(self::OPTION_KEY, $settings);

        if (function_exists('WC')) {
            WC()->shipping()->reset_shipping();
        }

        wp_safe_redirect(add_query_arg(array('page' => 'bmsm-qdfs-settings', 'settings-updated' => 'true'), admin_url('admin.php')));
        exit;
    }

    public function render_settings_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $settings = self::get_settings();
        ?>
        <div class="wrap bmsm-qdfs-wrap">
            <h1><?php esc_html_e('Buy More Save More', 'bmsm-qdfs'); ?></h1>

            <?php if (isset($_GET['settings-updated'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'bmsm-qdfs'); ?></p></div>
            <?php endif; ?>

            <form method="post" action="">
                <?php wp_nonce_field('bmsm_qdfs_save_settings_action', 'bmsm_qdfs_nonce'); ?>

                <div class="bmsm-card">
                    <h2><?php esc_html_e('Quantity Discount Tiers', 'bmsm-qdfs'); ?></h2>
                    <p><?php esc_html_e('The plugin applies the highest matching enabled tier.', 'bmsm-qdfs'); ?></p>

                    <label class="bmsm-checkbox">
                        <input type="checkbox" name="enable_discounts" value="yes" <?php checked($settings['enable_discounts'], 'yes'); ?>>
                        <?php esc_html_e('Enable quantity discounts', 'bmsm-qdfs'); ?>
                    </label>

                    <table class="widefat striped bmsm-tiers-table" id="bmsm-tiers-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Enabled', 'bmsm-qdfs'); ?></th>
                                <th><?php esc_html_e('Min Quantity', 'bmsm-qdfs'); ?></th>
                                <th><?php esc_html_e('Discount %', 'bmsm-qdfs'); ?></th>
                                <th><?php esc_html_e('Cart Label', 'bmsm-qdfs'); ?></th>
                                <th><?php esc_html_e('Action', 'bmsm-qdfs'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($settings['tiers'] as $index => $tier) : ?>
                                <?php $this->render_tier_row($index, $tier); ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <p>
                        <button type="button" class="button button-secondary" id="bmsm-add-tier"><?php esc_html_e('+ Add Tier', 'bmsm-qdfs'); ?></button>
                    </p>
                </div>

                <div class="bmsm-card">
                    <h2><?php esc_html_e('Free Shipping Rule', 'bmsm-qdfs'); ?></h2>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php esc_html_e('Enable Free Shipping', 'bmsm-qdfs'); ?></th>
                            <td><label><input type="checkbox" name="enable_free_shipping" value="yes" <?php checked($settings['enable_free_shipping'], 'yes'); ?>> <?php esc_html_e('Enable custom free shipping by quantity', 'bmsm-qdfs'); ?></label></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Free Shipping Quantity', 'bmsm-qdfs'); ?></th>
                            <td><input type="number" min="1" name="free_shipping_qty" value="<?php echo esc_attr($settings['free_shipping_qty']); ?>" class="small-text"> <?php esc_html_e('items', 'bmsm-qdfs'); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Free Shipping Label', 'bmsm-qdfs'); ?></th>
                            <td><input type="text" name="free_shipping_label" value="<?php echo esc_attr($settings['free_shipping_label']); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Keep Other Shipping Methods', 'bmsm-qdfs'); ?></th>
                            <td>
                                <label><input type="checkbox" name="keep_other_shipping_methods" value="yes" <?php checked($settings['keep_other_shipping_methods'], 'yes'); ?>> <?php esc_html_e('When eligible, keep Flat Rate / Express / other methods and add Free Shipping as an extra option.', 'bmsm-qdfs'); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Hide Existing Woo Free Shipping Until Eligible', 'bmsm-qdfs'); ?></th>
                            <td>
                                <label><input type="checkbox" name="hide_existing_free_shipping_until_eligible" value="yes" <?php checked($settings['hide_existing_free_shipping_until_eligible'], 'yes'); ?>> <?php esc_html_e('Hide WooCommerce Free Shipping methods until this plugin rule is eligible.', 'bmsm-qdfs'); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Free Shipping Only Mode', 'bmsm-qdfs'); ?></th>
                            <td>
                                <label><input type="checkbox" name="free_shipping_only_mode" value="yes" <?php checked($settings['free_shipping_only_mode'], 'yes'); ?>> <?php esc_html_e('If cart reaches the free shipping quantity, do not apply percentage discount.', 'bmsm-qdfs'); ?></label>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="bmsm-card">
                    <h2><?php esc_html_e('Cart / Checkout Notices', 'bmsm-qdfs'); ?></h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php esc_html_e('Enable Notices', 'bmsm-qdfs'); ?></th>
                            <td><label><input type="checkbox" name="enable_notices" value="yes" <?php checked($settings['enable_notices'], 'yes'); ?>> <?php esc_html_e('Show promotional notices on cart and checkout.', 'bmsm-qdfs'); ?></label></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Unlocked Message', 'bmsm-qdfs'); ?></th>
                            <td><input type="text" name="notice_unlocked" value="<?php echo esc_attr($settings['notice_unlocked']); ?>" class="large-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Next Discount Message', 'bmsm-qdfs'); ?></th>
                            <td>
                                <input type="text" name="notice_next_discount" value="<?php echo esc_attr($settings['notice_next_discount']); ?>" class="large-text">
                                <p class="description"><?php esc_html_e('Available placeholders: {items_needed}, {qty}, {discount}, {label}', 'bmsm-qdfs'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Next Free Shipping Message', 'bmsm-qdfs'); ?></th>
                            <td>
                                <input type="text" name="notice_next_free_shipping" value="<?php echo esc_attr($settings['notice_next_free_shipping']); ?>" class="large-text">
                                <p class="description"><?php esc_html_e('Available placeholders: {items_needed}, {qty}', 'bmsm-qdfs'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <p class="submit">
                    <button type="submit" name="bmsm_qdfs_save_settings" class="button button-primary"><?php esc_html_e('Save Changes', 'bmsm-qdfs'); ?></button>
                </p>
            </form>

            <script type="text/html" id="bmsm-tier-row-template">
                <?php $this->render_tier_row('__INDEX__', array('enabled' => 'yes', 'qty' => '', 'discount' => '', 'label' => '')); ?>
            </script>
        </div>
        <?php
    }

    private function render_tier_row($index, $tier) {
        ?>
        <tr class="bmsm-tier-row">
            <td><input type="checkbox" name="tiers[<?php echo esc_attr($index); ?>][enabled]" value="yes" <?php checked(isset($tier['enabled']) ? $tier['enabled'] : 'yes', 'yes'); ?>></td>
            <td><input type="number" min="1" step="1" name="tiers[<?php echo esc_attr($index); ?>][qty]" value="<?php echo esc_attr(isset($tier['qty']) ? $tier['qty'] : ''); ?>" class="small-text"></td>
            <td><input type="number" min="0" max="100" step="0.01" name="tiers[<?php echo esc_attr($index); ?>][discount]" value="<?php echo esc_attr(isset($tier['discount']) ? $tier['discount'] : ''); ?>" class="small-text"></td>
            <td><input type="text" name="tiers[<?php echo esc_attr($index); ?>][label]" value="<?php echo esc_attr(isset($tier['label']) ? $tier['label'] : ''); ?>" class="regular-text" placeholder="Buy 5 Save 10%"></td>
            <td><button type="button" class="button bmsm-remove-tier"><?php esc_html_e('Remove', 'bmsm-qdfs'); ?></button></td>
        </tr>
        <?php
    }

    private function get_cart_item_count() {
        if (!function_exists('WC') || !WC()->cart) {
            return 0;
        }

        $count = 0;
        foreach (WC()->cart->get_cart() as $cart_item) {
            if (!empty($cart_item['quantity'])) {
                $count += (int) $cart_item['quantity'];
            }
        }

        return $count;
    }

    private function is_free_shipping_eligible($item_count = null) {
        $settings = self::get_settings();
        $item_count = $item_count === null ? $this->get_cart_item_count() : (int) $item_count;

        return $settings['enable_free_shipping'] === 'yes' && $item_count >= max(1, (int) $settings['free_shipping_qty']);
    }

    private function get_best_discount_tier($item_count) {
        $settings = self::get_settings();

        if ($settings['enable_discounts'] !== 'yes') {
            return null;
        }

        if ($settings['free_shipping_only_mode'] === 'yes' && $this->is_free_shipping_eligible($item_count)) {
            return null;
        }

        $best = null;
        foreach ($settings['tiers'] as $tier) {
            if (!isset($tier['enabled']) || $tier['enabled'] !== 'yes') {
                continue;
            }

            if ($item_count >= (int) $tier['qty']) {
                $best = $tier;
            }
        }

        return $best;
    }

    public function apply_quantity_discount($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        if (!$cart || $cart->is_empty()) {
            return;
        }

        $item_count = $this->get_cart_item_count();
        $tier = $this->get_best_discount_tier($item_count);

        if (!$tier) {
            return;
        }

        $discount_rate = ((float) $tier['discount']) / 100;
        $discount_amount = round((float) $cart->get_subtotal() * $discount_rate, wc_get_price_decimals());

        if ($discount_amount > 0) {
            $cart->add_fee($tier['label'], -$discount_amount, false);
        }
    }

    public function control_shipping_rates($rates, $package) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return $rates;
        }

        if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
            return $rates;
        }

        $settings = self::get_settings();
        $item_count = $this->get_cart_item_count();
        $eligible = $this->is_free_shipping_eligible($item_count);

        if (!$eligible) {
            if ($settings['hide_existing_free_shipping_until_eligible'] === 'yes') {
                foreach ($rates as $rate_id => $rate) {
                    if (isset($rate->method_id) && in_array($rate->method_id, array('free_shipping', 'bmsm_qdfs_free_shipping'), true)) {
                        unset($rates[$rate_id]);
                    }
                }
            }
            return $rates;
        }

        $free_rate_id = 'bmsm_qdfs_free_shipping';
        $free_rate = new WC_Shipping_Rate(
            $free_rate_id,
            $settings['free_shipping_label'] ? $settings['free_shipping_label'] : __('Free Shipping', 'woocommerce'),
            0,
            array(),
            'bmsm_qdfs_free_shipping'
        );

        if ($settings['keep_other_shipping_methods'] === 'yes') {
            // Remove existing Woo free shipping methods to avoid duplicate free options.
            foreach ($rates as $rate_id => $rate) {
                if (isset($rate->method_id) && in_array($rate->method_id, array('free_shipping', 'bmsm_qdfs_free_shipping'), true)) {
                    unset($rates[$rate_id]);
                }
            }

            $rates[$free_rate_id] = $free_rate;
            return $rates;
        }

        return array($free_rate_id => $free_rate);
    }

    public function show_notice() {
        $settings = self::get_settings();

        if ($settings['enable_notices'] !== 'yes') {
            return;
        }

        if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
            return;
        }

        $item_count = $this->get_cart_item_count();

        if ($this->is_free_shipping_eligible($item_count)) {
            wc_print_notice(esc_html($settings['notice_unlocked']), 'success');
            return;
        }

        $next_discount = null;
        foreach ($settings['tiers'] as $tier) {
            if (isset($tier['enabled']) && $tier['enabled'] === 'yes' && $item_count < (int) $tier['qty']) {
                $next_discount = $tier;
                break;
            }
        }

        $free_qty = max(1, (int) $settings['free_shipping_qty']);
        $next_free_needed = $free_qty - $item_count;

        if ($next_discount) {
            $needed = max(1, (int) $next_discount['qty'] - $item_count);
            $message = str_replace(
                array('{items_needed}', '{qty}', '{discount}', '{label}'),
                array($needed, (int) $next_discount['qty'], rtrim(rtrim(number_format((float) $next_discount['discount'], 2, '.', ''), '0'), '.'), $next_discount['label']),
                $settings['notice_next_discount']
            );
            wc_print_notice(esc_html($message), 'notice');
            return;
        }

        if ($settings['enable_free_shipping'] === 'yes' && $next_free_needed > 0) {
            $message = str_replace(
                array('{items_needed}', '{qty}'),
                array($next_free_needed, $free_qty),
                $settings['notice_next_free_shipping']
            );
            wc_print_notice(esc_html($message), 'notice');
        }
    }
}

add_action('plugins_loaded', function() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>BMSM Quantity Discounts + Free Shipping</strong> requires WooCommerce to be installed and active.</p></div>';
        });
        return;
    }

    BMSM_QDFS_Plugin::instance();
});

register_activation_hook(__FILE__, function() {
    if (!get_option(BMSM_QDFS_Plugin::OPTION_KEY)) {
        update_option(BMSM_QDFS_Plugin::OPTION_KEY, BMSM_QDFS_Plugin::defaults());
    }
});
