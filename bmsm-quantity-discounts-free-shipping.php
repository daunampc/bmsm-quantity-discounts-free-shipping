<?php
/**
 * Plugin Name: BMSM Quantity Discounts + Free Shipping
 * Plugin URI: https://github.com/daunampc/bmsm-quantity-discounts-free-shipping
 * Description: Dynamic WooCommerce quantity discount tiers, promotional offer boxes, free-shipping rule, and GitHub updates.
 * Version: 1.0.0
 * Author: toshstack
 * License: GPL-2.0-or-later
 * Text Domain: bmsm-qdfs
 * Requires Plugins: woocommerce
 * Update URI: https://github.com/daunampc/bmsm-quantity-discounts-free-shipping
 */

if (!defined('ABSPATH')) {
    exit;
}

final class BMSM_QDFS_Plugin {
    const OPTION_KEY = 'bmsm_qdfs_settings';
    const VERSION = '1.0.0';
    const GITHUB_OWNER = 'daunampc';
    const GITHUB_REPO = 'bmsm-quantity-discounts-free-shipping';

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

        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('woocommerce_cart_calculate_fees', array($this, 'apply_quantity_discount'), 20, 1);
        add_filter('woocommerce_package_rates', array($this, 'control_shipping_rates'), 9999, 2);
        add_action('woocommerce_after_add_to_cart_form', array($this, 'render_offer_boxes'), 20);
        add_action('woocommerce_before_cart', array($this, 'show_notice'));
        add_action('woocommerce_before_checkout_form', array($this, 'show_notice'));

        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_github_update'));
        add_filter('plugins_api', array($this, 'github_plugin_info'), 20, 3);
    }

    public static function defaults() {
        return array(
            'enable_discounts' => 'yes',
            'tiers' => array(
                array('enabled' => 'yes', 'qty' => 2, 'discount' => 5, 'label' => 'Buy 2 Save 5%'),
                array('enabled' => 'yes', 'qty' => 3, 'discount' => 7, 'label' => 'Buy 3 Save 7%'),
                array('enabled' => 'yes', 'qty' => 4, 'discount' => 10, 'label' => 'Buy 4 Save 10%'),
                array('enabled' => 'yes', 'qty' => 5, 'discount' => 15, 'label' => 'Buy 5 Save 15%'),
            ),
            'enable_offer_boxes' => 'yes',
            'offer_box_title' => 'Available offers',
            'coupon_offers' => array(
                array('enabled' => 'yes', 'code' => 'GET10', 'description' => 'Discount 10% for order of 2 items', 'icon' => 'tag'),
                array('enabled' => 'yes', 'code' => 'FREESHIPPING3', 'description' => 'Free shipping for order of 3 items', 'icon' => 'truck'),
            ),
            'enable_free_shipping' => 'yes',
            'free_shipping_qty' => 3,
            'free_shipping_label' => 'Free Shipping',
            'keep_other_shipping_methods' => 'yes',
            'hide_existing_free_shipping_until_eligible' => 'yes',
            'free_shipping_only_mode' => 'no',
            'enable_notices' => 'yes',
            'notice_unlocked' => 'You unlocked Free Shipping!',
            'notice_next_discount' => 'Add {items_needed} more item(s) to unlock {label}.',
            'notice_next_free_shipping' => 'Add {items_needed} more item(s) to unlock Free Shipping.',
        );
    }

    public static function get_settings() {
        $saved = get_option(self::OPTION_KEY, array());
        $settings = wp_parse_args(is_array($saved) ? $saved : array(), self::defaults());
        $settings['tiers'] = self::sanitize_tiers(isset($settings['tiers']) ? $settings['tiers'] : array());
        $settings['coupon_offers'] = self::sanitize_coupon_offers(isset($settings['coupon_offers']) ? $settings['coupon_offers'] : self::defaults()['coupon_offers']);
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
            $discount = isset($tier['discount']) ? min(100, (float) $tier['discount']) : 0;
            $label = isset($tier['label']) ? sanitize_text_field($tier['label']) : '';
            if ($qty <= 0 || $discount <= 0) {
                continue;
            }
            if ($label === '') {
                $label = sprintf('Buy %d Save %s%%', $qty, self::format_number($discount));
            }
            $clean[] = array('enabled' => isset($tier['enabled']) && $tier['enabled'] === 'yes' ? 'yes' : 'no', 'qty' => $qty, 'discount' => $discount, 'label' => $label);
        }
        usort($clean, function($a, $b) { return (int) $a['qty'] <=> (int) $b['qty']; });
        return $clean;
    }

    private static function sanitize_coupon_offers($offers) {
        $clean = array();
        if (!is_array($offers)) {
            return $clean;
        }
        foreach ($offers as $offer) {
            if (!is_array($offer)) {
                continue;
            }
            $code = isset($offer['code']) ? sanitize_text_field($offer['code']) : '';
            $description = isset($offer['description']) ? sanitize_text_field($offer['description']) : '';
            if ($code === '' || $description === '') {
                continue;
            }
            $clean[] = array('enabled' => isset($offer['enabled']) && $offer['enabled'] === 'yes' ? 'yes' : 'no', 'code' => $code, 'description' => $description, 'icon' => isset($offer['icon']) && $offer['icon'] === 'truck' ? 'truck' : 'tag');
        }
        return $clean;
    }

    private static function format_number($value) {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    public function add_settings_link($links) {
        $links[] = '<a href="' . esc_url(admin_url('admin.php?page=bmsm-qdfs-settings')) . '">' . esc_html__('Settings', 'bmsm-qdfs') . '</a>';
        return $links;
    }

    public function add_admin_menu() {
        add_submenu_page('woocommerce', __('Buy More Save More', 'bmsm-qdfs'), __('Buy More Save More', 'bmsm-qdfs'), 'manage_woocommerce', 'bmsm-qdfs-settings', array($this, 'render_settings_page'));
    }

    public function enqueue_admin_assets($hook) {
        if ($hook !== 'woocommerce_page_bmsm-qdfs-settings') {
            return;
        }
        wp_enqueue_style('bmsm-qdfs-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', array(), self::VERSION);
        wp_enqueue_script('bmsm-qdfs-admin', plugin_dir_url(__FILE__) . 'assets/admin.js', array('jquery'), self::VERSION, true);
    }

    public function enqueue_frontend_assets() {
        if (!function_exists('is_product') || (!is_product() && !is_cart() && !is_checkout())) {
            return;
        }
        wp_enqueue_style('bmsm-qdfs-frontend', plugin_dir_url(__FILE__) . 'assets/frontend.css', array(), self::VERSION);
        wp_enqueue_script('bmsm-qdfs-frontend', plugin_dir_url(__FILE__) . 'assets/frontend.js', array(), self::VERSION, true);
    }

    public function handle_save_settings() {
        if (!isset($_POST['bmsm_qdfs_save_settings']) || !current_user_can('manage_woocommerce')) {
            return;
        }
        check_admin_referer('bmsm_qdfs_save_settings_action', 'bmsm_qdfs_nonce');

        $tiers = array();
        if (isset($_POST['tiers']) && is_array($_POST['tiers'])) {
            foreach ($_POST['tiers'] as $tier) {
                $tiers[] = array('enabled' => isset($tier['enabled']) ? 'yes' : 'no', 'qty' => isset($tier['qty']) ? absint($tier['qty']) : 0, 'discount' => isset($tier['discount']) ? (float) wc_clean(wp_unslash($tier['discount'])) : 0, 'label' => isset($tier['label']) ? sanitize_text_field(wp_unslash($tier['label'])) : '');
            }
        }

        $offers = array();
        if (isset($_POST['coupon_offers']) && is_array($_POST['coupon_offers'])) {
            foreach ($_POST['coupon_offers'] as $offer) {
                $offers[] = array('enabled' => isset($offer['enabled']) ? 'yes' : 'no', 'code' => isset($offer['code']) ? sanitize_text_field(wp_unslash($offer['code'])) : '', 'description' => isset($offer['description']) ? sanitize_text_field(wp_unslash($offer['description'])) : '', 'icon' => isset($offer['icon']) && $offer['icon'] === 'truck' ? 'truck' : 'tag');
            }
        }

        update_option(self::OPTION_KEY, array(
            'enable_discounts' => isset($_POST['enable_discounts']) ? 'yes' : 'no',
            'tiers' => self::sanitize_tiers($tiers),
            'enable_offer_boxes' => isset($_POST['enable_offer_boxes']) ? 'yes' : 'no',
            'offer_box_title' => isset($_POST['offer_box_title']) ? sanitize_text_field(wp_unslash($_POST['offer_box_title'])) : '',
            'coupon_offers' => self::sanitize_coupon_offers($offers),
            'enable_free_shipping' => isset($_POST['enable_free_shipping']) ? 'yes' : 'no',
            'free_shipping_qty' => isset($_POST['free_shipping_qty']) ? max(1, absint($_POST['free_shipping_qty'])) : 3,
            'free_shipping_label' => isset($_POST['free_shipping_label']) ? sanitize_text_field(wp_unslash($_POST['free_shipping_label'])) : 'Free Shipping',
            'keep_other_shipping_methods' => isset($_POST['keep_other_shipping_methods']) ? 'yes' : 'no',
            'hide_existing_free_shipping_until_eligible' => isset($_POST['hide_existing_free_shipping_until_eligible']) ? 'yes' : 'no',
            'free_shipping_only_mode' => isset($_POST['free_shipping_only_mode']) ? 'yes' : 'no',
            'enable_notices' => isset($_POST['enable_notices']) ? 'yes' : 'no',
            'notice_unlocked' => isset($_POST['notice_unlocked']) ? sanitize_text_field(wp_unslash($_POST['notice_unlocked'])) : '',
            'notice_next_discount' => isset($_POST['notice_next_discount']) ? sanitize_text_field(wp_unslash($_POST['notice_next_discount'])) : '',
            'notice_next_free_shipping' => isset($_POST['notice_next_free_shipping']) ? sanitize_text_field(wp_unslash($_POST['notice_next_free_shipping'])) : '',
        ));

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
            <h1><?php esc_html_e('Buy More Save More', 'bmsm-qdfs'); ?> <span class="bmsm-version">v<?php echo esc_html(self::VERSION); ?></span></h1>
            <?php if (isset($_GET['settings-updated'])) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'bmsm-qdfs'); ?></p></div><?php endif; ?>
            <form method="post" action="">
                <?php wp_nonce_field('bmsm_qdfs_save_settings_action', 'bmsm_qdfs_nonce'); ?>
                <div class="bmsm-card"><h2><?php esc_html_e('Quantity Discount Tiers', 'bmsm-qdfs'); ?></h2><label class="bmsm-checkbox"><input type="checkbox" name="enable_discounts" value="yes" <?php checked($settings['enable_discounts'], 'yes'); ?>> <?php esc_html_e('Enable quantity discounts', 'bmsm-qdfs'); ?></label><table class="widefat striped bmsm-tiers-table" id="bmsm-tiers-table"><thead><tr><th><?php esc_html_e('Enabled', 'bmsm-qdfs'); ?></th><th><?php esc_html_e('Min Quantity', 'bmsm-qdfs'); ?></th><th><?php esc_html_e('Discount %', 'bmsm-qdfs'); ?></th><th><?php esc_html_e('Cart Label', 'bmsm-qdfs'); ?></th><th><?php esc_html_e('Action', 'bmsm-qdfs'); ?></th></tr></thead><tbody><?php foreach ($settings['tiers'] as $index => $tier) : $this->render_tier_row($index, $tier); endforeach; ?></tbody></table><p><button type="button" class="button button-secondary" id="bmsm-add-tier"><?php esc_html_e('+ Add Tier', 'bmsm-qdfs'); ?></button></p></div>
                <div class="bmsm-card"><h2><?php esc_html_e('Frontend Offer Boxes', 'bmsm-qdfs'); ?></h2><label class="bmsm-checkbox"><input type="checkbox" name="enable_offer_boxes" value="yes" <?php checked($settings['enable_offer_boxes'], 'yes'); ?>> <?php esc_html_e('Show discount and coupon list box on single product pages', 'bmsm-qdfs'); ?></label><p><label><?php esc_html_e('Title:', 'bmsm-qdfs'); ?> <input type="text" name="offer_box_title" value="<?php echo esc_attr($settings['offer_box_title']); ?>" class="regular-text"></label></p><table class="widefat striped bmsm-offers-table" id="bmsm-offers-table"><thead><tr><th><?php esc_html_e('Enabled', 'bmsm-qdfs'); ?></th><th><?php esc_html_e('Code', 'bmsm-qdfs'); ?></th><th><?php esc_html_e('Description', 'bmsm-qdfs'); ?></th><th><?php esc_html_e('Icon', 'bmsm-qdfs'); ?></th><th><?php esc_html_e('Action', 'bmsm-qdfs'); ?></th></tr></thead><tbody><?php foreach ($settings['coupon_offers'] as $index => $offer) : $this->render_offer_row($index, $offer); endforeach; ?></tbody></table><p><button type="button" class="button button-secondary" id="bmsm-add-offer"><?php esc_html_e('+ Add Offer', 'bmsm-qdfs'); ?></button></p></div>
                <div class="bmsm-card"><h2><?php esc_html_e('Free Shipping Rule', 'bmsm-qdfs'); ?></h2><table class="form-table" role="presentation"><tr><th scope="row"><?php esc_html_e('Enable Free Shipping', 'bmsm-qdfs'); ?></th><td><label><input type="checkbox" name="enable_free_shipping" value="yes" <?php checked($settings['enable_free_shipping'], 'yes'); ?>> <?php esc_html_e('Enable custom free shipping by quantity', 'bmsm-qdfs'); ?></label></td></tr><tr><th scope="row"><?php esc_html_e('Free Shipping Quantity', 'bmsm-qdfs'); ?></th><td><input type="number" min="1" name="free_shipping_qty" value="<?php echo esc_attr($settings['free_shipping_qty']); ?>" class="small-text"> <?php esc_html_e('items', 'bmsm-qdfs'); ?></td></tr><tr><th scope="row"><?php esc_html_e('Free Shipping Label', 'bmsm-qdfs'); ?></th><td><input type="text" name="free_shipping_label" value="<?php echo esc_attr($settings['free_shipping_label']); ?>" class="regular-text"></td></tr><tr><th scope="row"><?php esc_html_e('Keep Other Shipping Methods', 'bmsm-qdfs'); ?></th><td><label><input type="checkbox" name="keep_other_shipping_methods" value="yes" <?php checked($settings['keep_other_shipping_methods'], 'yes'); ?>> <?php esc_html_e('Keep paid shipping methods and add Free Shipping as an extra option.', 'bmsm-qdfs'); ?></label></td></tr><tr><th scope="row"><?php esc_html_e('Hide Existing Woo Free Shipping Until Eligible', 'bmsm-qdfs'); ?></th><td><label><input type="checkbox" name="hide_existing_free_shipping_until_eligible" value="yes" <?php checked($settings['hide_existing_free_shipping_until_eligible'], 'yes'); ?>> <?php esc_html_e('Hide WooCommerce Free Shipping methods until this rule is eligible.', 'bmsm-qdfs'); ?></label></td></tr><tr><th scope="row"><?php esc_html_e('Free Shipping Only Mode', 'bmsm-qdfs'); ?></th><td><label><input type="checkbox" name="free_shipping_only_mode" value="yes" <?php checked($settings['free_shipping_only_mode'], 'yes'); ?>> <?php esc_html_e('If free shipping is unlocked, do not apply percentage discount.', 'bmsm-qdfs'); ?></label></td></tr></table></div>
                <div class="bmsm-card"><h2><?php esc_html_e('Cart / Checkout Notices', 'bmsm-qdfs'); ?></h2><table class="form-table" role="presentation"><tr><th scope="row"><?php esc_html_e('Enable Notices', 'bmsm-qdfs'); ?></th><td><label><input type="checkbox" name="enable_notices" value="yes" <?php checked($settings['enable_notices'], 'yes'); ?>> <?php esc_html_e('Show promotional notices on cart and checkout.', 'bmsm-qdfs'); ?></label></td></tr><tr><th scope="row"><?php esc_html_e('Unlocked Message', 'bmsm-qdfs'); ?></th><td><input type="text" name="notice_unlocked" value="<?php echo esc_attr($settings['notice_unlocked']); ?>" class="large-text"></td></tr><tr><th scope="row"><?php esc_html_e('Next Discount Message', 'bmsm-qdfs'); ?></th><td><input type="text" name="notice_next_discount" value="<?php echo esc_attr($settings['notice_next_discount']); ?>" class="large-text"><p class="description"><?php esc_html_e('Available placeholders: {items_needed}, {qty}, {discount}, {label}', 'bmsm-qdfs'); ?></p></td></tr><tr><th scope="row"><?php esc_html_e('Next Free Shipping Message', 'bmsm-qdfs'); ?></th><td><input type="text" name="notice_next_free_shipping" value="<?php echo esc_attr($settings['notice_next_free_shipping']); ?>" class="large-text"><p class="description"><?php esc_html_e('Available placeholders: {items_needed}, {qty}', 'bmsm-qdfs'); ?></p></td></tr></table></div>
                <p class="submit"><button type="submit" name="bmsm_qdfs_save_settings" class="button button-primary"><?php esc_html_e('Save Changes', 'bmsm-qdfs'); ?></button></p>
            </form>
            <script type="text/html" id="bmsm-tier-row-template"><?php $this->render_tier_row('__INDEX__', array('enabled' => 'yes', 'qty' => '', 'discount' => '', 'label' => '')); ?></script>
            <script type="text/html" id="bmsm-offer-row-template"><?php $this->render_offer_row('__INDEX__', array('enabled' => 'yes', 'code' => '', 'description' => '', 'icon' => 'tag')); ?></script>
        </div>
        <?php
    }

    private function render_tier_row($index, $tier) { ?>
        <tr class="bmsm-tier-row"><td><input type="checkbox" name="tiers[<?php echo esc_attr($index); ?>][enabled]" value="yes" <?php checked(isset($tier['enabled']) ? $tier['enabled'] : 'yes', 'yes'); ?>></td><td><input type="number" min="1" step="1" name="tiers[<?php echo esc_attr($index); ?>][qty]" value="<?php echo esc_attr(isset($tier['qty']) ? $tier['qty'] : ''); ?>" class="small-text"></td><td><input type="number" min="0" max="100" step="0.01" name="tiers[<?php echo esc_attr($index); ?>][discount]" value="<?php echo esc_attr(isset($tier['discount']) ? $tier['discount'] : ''); ?>" class="small-text"></td><td><input type="text" name="tiers[<?php echo esc_attr($index); ?>][label]" value="<?php echo esc_attr(isset($tier['label']) ? $tier['label'] : ''); ?>" class="regular-text" placeholder="Buy 5 Save 10%"></td><td><button type="button" class="button bmsm-remove-tier"><?php esc_html_e('Remove', 'bmsm-qdfs'); ?></button></td></tr>
    <?php }

    private function render_offer_row($index, $offer) { ?>
        <tr class="bmsm-offer-row"><td><input type="checkbox" name="coupon_offers[<?php echo esc_attr($index); ?>][enabled]" value="yes" <?php checked(isset($offer['enabled']) ? $offer['enabled'] : 'yes', 'yes'); ?>></td><td><input type="text" name="coupon_offers[<?php echo esc_attr($index); ?>][code]" value="<?php echo esc_attr(isset($offer['code']) ? $offer['code'] : ''); ?>" class="regular-text" placeholder="GET10"></td><td><input type="text" name="coupon_offers[<?php echo esc_attr($index); ?>][description]" value="<?php echo esc_attr(isset($offer['description']) ? $offer['description'] : ''); ?>" class="large-text" placeholder="Discount 10% for order of 2 items"></td><td><select name="coupon_offers[<?php echo esc_attr($index); ?>][icon]"><option value="tag" <?php selected(isset($offer['icon']) ? $offer['icon'] : 'tag', 'tag'); ?>>Tag</option><option value="truck" <?php selected(isset($offer['icon']) ? $offer['icon'] : 'tag', 'truck'); ?>>Truck</option></select></td><td><button type="button" class="button bmsm-remove-offer"><?php esc_html_e('Remove', 'bmsm-qdfs'); ?></button></td></tr>
    <?php }

    private function get_cart_item_count() {
        if (!function_exists('WC') || !WC()->cart) {
            return 0;
        }
        $count = 0;
        foreach (WC()->cart->get_cart() as $cart_item) {
            $count += !empty($cart_item['quantity']) ? (int) $cart_item['quantity'] : 0;
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
            if (isset($tier['enabled']) && $tier['enabled'] === 'yes' && $item_count >= (int) $tier['qty']) {
                $best = $tier;
            }
        }
        return $best;
    }

    public function render_offer_boxes() {
        if (!is_product()) {
            return;
        }
        $settings = self::get_settings();
        if ($settings['enable_offer_boxes'] !== 'yes') {
            return;
        }
        $has_tiers = $settings['enable_discounts'] === 'yes' && !empty($settings['tiers']);
        $has_offers = !empty($settings['coupon_offers']);
        if (!$has_tiers && !$has_offers) {
            return;
        }
        $item_count = $this->get_cart_item_count();
        echo '<section class="bmsm-qdfs-offers" aria-label="' . esc_attr__('Available offers', 'bmsm-qdfs') . '">';
        if (!empty($settings['offer_box_title'])) {
            echo '<h3 class="bmsm-qdfs-title">' . esc_html($settings['offer_box_title']) . '</h3>';
        }
        if ($has_tiers) {
            echo '<div class="bmsm-qdfs-tier-list">';
            foreach ($settings['tiers'] as $tier) {
                if (!isset($tier['enabled']) || $tier['enabled'] !== 'yes') {
                    continue;
                }
                $active = $item_count >= (int) $tier['qty'] ? ' is-active' : '';
                echo '<div class="bmsm-qdfs-tier' . esc_attr($active) . '">';
                echo '<span class="bmsm-qdfs-badge">' . esc_html(self::format_number($tier['discount'])) . '% OFF</span>';
                echo '<span class="bmsm-qdfs-tier-text"><strong>' . esc_html(sprintf(_n('%d item gets', '%d items get', (int) $tier['qty'], 'bmsm-qdfs'), (int) $tier['qty'])) . '</strong> <em>' . esc_html(self::format_number($tier['discount'])) . '% OFF</em> ' . esc_html__('on cart total', 'bmsm-qdfs') . '</span>';
                echo '<button type="button" class="bmsm-qdfs-buy" data-bmsm-qty="' . esc_attr($tier['qty']) . '">' . esc_html(sprintf(__('Buy %d', 'bmsm-qdfs'), (int) $tier['qty'])) . '</button>';
                echo '</div>';
            }
            echo '</div>';
        }
        if ($has_offers) {
            echo '<div class="bmsm-qdfs-coupon-list">';
            foreach ($settings['coupon_offers'] as $offer) {
                if (!isset($offer['enabled']) || $offer['enabled'] !== 'yes') {
                    continue;
                }
                $icon = $offer['icon'] === 'truck' ? '🚚' : '🏷️';
                echo '<div class="bmsm-qdfs-coupon">';
                echo '<span class="bmsm-qdfs-coupon-icon" aria-hidden="true">' . esc_html($icon) . '</span>';
                echo '<div class="bmsm-qdfs-coupon-body"><strong>' . esc_html($offer['code']) . '</strong><span>' . esc_html($offer['description']) . '</span></div>';
                echo '<button type="button" class="bmsm-qdfs-copy" data-bmsm-code="' . esc_attr($offer['code']) . '">' . esc_html__('Copy code', 'bmsm-qdfs') . ' <span aria-hidden="true">▣</span></button>';
                echo '</div>';
            }
            echo '</div>';
        }
        echo '</section>';
    }

    public function apply_quantity_discount($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        if (!$cart || $cart->is_empty()) {
            return;
        }
        $tier = $this->get_best_discount_tier($this->get_cart_item_count());
        if (!$tier) {
            return;
        }
        $discount_amount = round((float) $cart->get_subtotal() * (((float) $tier['discount']) / 100), wc_get_price_decimals());
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
        $eligible = $this->is_free_shipping_eligible($this->get_cart_item_count());
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
        $free_rate = new WC_Shipping_Rate($free_rate_id, $settings['free_shipping_label'] ? $settings['free_shipping_label'] : __('Free Shipping', 'woocommerce'), 0, array(), 'bmsm_qdfs_free_shipping');
        if ($settings['keep_other_shipping_methods'] === 'yes') {
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
        if ($settings['enable_notices'] !== 'yes' || !function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
            return;
        }
        $item_count = $this->get_cart_item_count();
        if ($this->is_free_shipping_eligible($item_count)) {
            wc_print_notice(esc_html($settings['notice_unlocked']), 'success');
            return;
        }
        foreach ($settings['tiers'] as $tier) {
            if (isset($tier['enabled']) && $tier['enabled'] === 'yes' && $item_count < (int) $tier['qty']) {
                $needed = max(1, (int) $tier['qty'] - $item_count);
                $message = str_replace(array('{items_needed}', '{qty}', '{discount}', '{label}'), array($needed, (int) $tier['qty'], self::format_number($tier['discount']), $tier['label']), $settings['notice_next_discount']);
                wc_print_notice(esc_html($message), 'notice');
                return;
            }
        }
        $free_qty = max(1, (int) $settings['free_shipping_qty']);
        if ($settings['enable_free_shipping'] === 'yes' && $item_count < $free_qty) {
            $message = str_replace(array('{items_needed}', '{qty}'), array($free_qty - $item_count, $free_qty), $settings['notice_next_free_shipping']);
            wc_print_notice(esc_html($message), 'notice');
        }
    }

    private function get_latest_github_release() {
        $url = sprintf('https://api.github.com/repos/%s/%s/releases/latest', self::GITHUB_OWNER, self::GITHUB_REPO);
        $response = wp_remote_get($url, array('timeout' => 10, 'headers' => array('Accept' => 'application/vnd.github+json')));
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        $release = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($release) ? $release : null;
    }

    public function check_github_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }
        $release = $this->get_latest_github_release();
        if (!$release || empty($release['tag_name'])) {
            return $transient;
        }
        $latest_version = ltrim($release['tag_name'], 'vV');
        if (!version_compare($latest_version, self::VERSION, '>')) {
            return $transient;
        }
        $plugin_file = plugin_basename(__FILE__);
        $transient->response[$plugin_file] = (object) array('slug' => dirname($plugin_file), 'plugin' => $plugin_file, 'new_version' => $latest_version, 'url' => 'https://github.com/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO, 'package' => !empty($release['zipball_url']) ? $release['zipball_url'] : '', 'tested' => '9.0', 'requires_php' => '7.4');
        return $transient;
    }

    public function github_plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== dirname(plugin_basename(__FILE__))) {
            return $result;
        }
        $release = $this->get_latest_github_release();
        $version = !empty($release['tag_name']) ? ltrim($release['tag_name'], 'vV') : self::VERSION;
        return (object) array('name' => 'BMSM Quantity Discounts + Free Shipping', 'slug' => dirname(plugin_basename(__FILE__)), 'version' => $version, 'author' => '<a href="https://github.com/toshstack">toshstack</a>', 'homepage' => 'https://github.com/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO, 'sections' => array('description' => 'WooCommerce quantity discount, free-shipping, and frontend offer box plugin.', 'changelog' => !empty($release['body']) ? wp_kses_post($release['body']) : 'Initial v1.0.0 release.'), 'download_link' => !empty($release['zipball_url']) ? $release['zipball_url'] : '', 'requires_php' => '7.4');
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
