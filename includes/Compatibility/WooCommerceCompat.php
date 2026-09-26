<?php
/**
 * WooCommerce 兼容层。
 *
 * 计划书 §67 要求重点保证 商品页 / 商店页 / 分类页 / 购物车 / 结算 / 我的账户 /
 * 支付 / 订单 / 优惠券 的正确性。
 *
 * 策略分两类：
 * - **绝不缓存**：购物车、结算、我的账户、订单回执——这些页面天然因人而异，
 *   缓存它们会造成"看到别人购物车"这类严重事故。
 * - **允许缓存但必须及时失效**：商品页、商店页、分类页。它们对未登录访客是静态的，
 *   缓存收益极大；但库存变化（卖出最后一件）必须立刻反映到"售罄"标记上。
 *
 * @package AT8\SiteAccelerator\Compatibility
 */

namespace AT8\SiteAccelerator\Compatibility;

use AT8\SiteAccelerator\Purge\Purger;
use AT8\SiteAccelerator\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Class WooCommerceCompat
 */
final class WooCommerceCompat {

	/**
	 * 日志。
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * 失效器。
	 *
	 * @var Purger
	 */
	private $purger;

	/**
	 * 构造。
	 *
	 * @param Purger $purger 失效器。
	 * @param Logger $logger 日志。
	 */
	public function __construct( Purger $purger, Logger $logger ) {
		$this->purger = $purger;
		$this->logger = $logger;
	}

	/**
	 * WooCommerce 是否已激活。
	 *
	 * @return bool
	 */
	public function is_active() {
		return class_exists( 'WooCommerce' ) || defined( 'WC_VERSION' );
	}

	/**
	 * 挂载钩子。
	 *
	 * @return void
	 */
	public function boot() {
		if ( ! $this->is_active() ) {
			return;
		}

		// 动态页面显式标记为不可缓存，双保险（CacheEngine 里已有条件判断）。
		add_action( 'template_redirect', array( $this, 'mark_dynamic_pages' ), 0 );

		// 库存变化 → 商品页立即失效。
		add_action( 'woocommerce_product_set_stock', array( $this, 'on_stock_change' ), 10, 1 );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'on_stock_change' ), 10, 1 );

		// 订单状态变化 → 商店/分类页的销量排序与库存标记可能变化。
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status_change' ), 10, 1 );

		// 优惠券变更 → 影响前台展示（部分主题会显示券信息）。
		add_action( 'woocommerce_new_coupon', array( $this, 'purge_shop' ), 10, 0 );
		add_action( 'woocommerce_update_coupon', array( $this, 'purge_shop' ), 10, 0 );
		add_action( 'woocommerce_delete_coupon', array( $this, 'purge_shop' ), 10, 0 );
	}

	/**
	 * 把动态页面标记为不可缓存。
	 *
	 * @return void
	 */
	public function mark_dynamic_pages() {
		if ( $this->is_dynamic_request() ) {
			// 官方常量，整页缓存插件都认它。
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}

			if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
				define( 'DONOTCACHEOBJECT', true );
			}

			do_action( 'at8sa_bypass_wc_dynamic' );
		}
	}

	/**
	 * 当前请求是否为 WooCommerce 动态页面。
	 *
	 * @return bool
	 */
	private function is_dynamic_request() {
		if ( ! function_exists( 'is_cart' ) ) {
			return false;
		}

		if ( is_cart() ) {
			return true;
		}

		// 逐个函数判存在，而不是"赌 WooCommerce 一定把三个函数一起定义"。
		// 子插件 / 主题把某个函数 undeclare 掉时，单判 is_cart() 会直接白屏。
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return true;
		}

		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return true;
		}

		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url() ) {
			return true;
		}

		// 有购物车内容或有会话 cookie 的访客一律不缓存。
		if ( function_exists( 'WC' ) && WC()->cart && ! WC()->cart->is_empty() ) {
			return true;
		}

		return false;
	}

	/**
	 * 库存变化 → 失效该商品。
	 *
	 * @param \WC_Product $product 商品对象。
	 * @return void
	 */
	public function on_stock_change( $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return;
		}

		$product_id = (int) $product->get_id();
		$permalink  = get_permalink( $product_id );

		if ( $permalink ) {
			$this->purger->purge_url( $permalink );
		}

		$this->purge_shop();
	}

	/**
	 * 订单状态变化 → 失效商店页与分类页。
	 *
	 * @param int $order_id 订单 ID。
	 * @return void
	 */
	public function on_order_status_change( $order_id ) {
		unset( $order_id );

		$this->purge_shop();
	}

	/**
	 * 失效商店页与全部商品分类归档。
	 *
	 * @return void
	 */
	public function purge_shop() {
		$urls = array();

		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$shop = wc_get_page_permalink( 'shop' );

			if ( $shop ) {
				$urls[] = $shop;
			}
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'fields'     => 'ids',
				'number'     => 100,
			)
		);

		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term_id ) {
				$link = get_term_link( (int) $term_id, 'product_cat' );

				if ( ! is_wp_error( $link ) && $link ) {
					$urls[] = $link;
				}
			}
		}

		$this->purger->purge_urls( $urls );

		// 商店页/分类页的失效范围直接决定"库存改了但前台还是售罄"这类投诉的
		// 排查难度，记一条便于对账。
		$this->logger->debug( 'WooCommerce 商店页失效', array( 'urls' => count( $urls ) ) );
	}
}
