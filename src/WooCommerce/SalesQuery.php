<?php
namespace VisWiz\WooCommerce;

use DateTimeImmutable;
use DateTimeZone;
use VisWiz\Support;
use WP_Error;

final class SalesQuery {
    private const PAGE_SIZE = 200;
    private const DEFAULT_CACHE_TTL = 120;

    public function query( array $raw_config, bool $use_cache = true ) {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return new WP_Error( 'viswiz_no_woocommerce', __( 'WooCommerce is not active.', 'viswiz' ), array( 'status' => 503 ) );
        }

        $config = $this->sanitize_config( $raw_config );
        $epoch  = (int) get_option( 'viswiz_woo_cache_epoch', 1 );
        $key    = 'viswiz_woo_' . md5( wp_json_encode( array( $epoch, $config ) ) );
        $ttl    = $this->cache_ttl();
        if ( $use_cache ) {
            $cached = get_transient( $key );
            if ( is_array( $cached ) ) {
                $cached['meta']['cached'] = true;
                return $cached;
            }
        }

        [ $start, $end ] = $this->resolve_window( $config );
        $order_ids       = $this->order_ids( $config, $start, $end );
        $series          = array();
        $matched_orders  = array();
        $category_ids    = $this->category_ids_with_children( $config['category_ids'] );

        foreach ( $order_ids as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                continue;
            }
            $items = $this->matching_items( $order, $config['product_ids'], $category_ids );
            $has_item_filter = $config['product_ids'] || $category_ids;
            if ( $has_item_filter && ! $items ) {
                continue;
            }
            $matched_orders[ $order->get_id() ] = true;

            if ( 'orders' === $config['metric'] ) {
                if ( 'product' === $config['group_by'] ) {
                    $seen = array();
                    foreach ( $items as $item ) {
                        $product_id = $this->item_product_id( $item );
                        if ( $product_id && ! isset( $seen[ $product_id ] ) ) {
                            $seen[ $product_id ] = true;
                            $series[ (string) $product_id ] = ( $series[ (string) $product_id ] ?? 0 ) + 1;
                        }
                    }
                } else {
                    $key_name = $this->group_key( $config['group_by'], $order, null, $start, $config['date_basis'] );
                    $series[ $key_name ] = ( $series[ $key_name ] ?? 0 ) + 1;
                }
                continue;
            }

            if ( 'quantity' === $config['metric'] ) {
                foreach ( $items as $item ) {
                    $key_name = $this->group_key( $config['group_by'], $order, $item, $start, $config['date_basis'] );
                    $quantity = (float) $item->get_quantity();
                    if ( $config['deduct_refunds'] && method_exists( $order, 'get_qty_refunded_for_item' ) ) {
                        $quantity = max( 0.0, $quantity - abs( (float) $order->get_qty_refunded_for_item( $item->get_id() ) ) );
                    }
                    $series[ $key_name ] = ( $series[ $key_name ] ?? 0 ) + $quantity;
                }
                continue;
            }

            if ( ! $has_item_filter && 'product' !== $config['group_by'] ) {
                $key_name = $this->group_key( $config['group_by'], $order, null, $start, $config['date_basis'] );
                $series[ $key_name ] = ( $series[ $key_name ] ?? 0 ) + $this->order_revenue( $order, $config );
                continue;
            }

            foreach ( $items as $item ) {
                $key_name = $this->group_key( $config['group_by'], $order, $item, $start, $config['date_basis'] );
                $series[ $key_name ] = ( $series[ $key_name ] ?? 0 ) + $this->item_revenue( $item, $config, $order );
            }
        }

        if ( 'month' === $config['group_by'] ) {
            ksort( $series, SORT_STRING );
        } elseif ( 'product' === $config['group_by'] ) {
            arsort( $series, SORT_NUMERIC );
        }

        $rows = array();
        foreach ( $series as $key_name => $value ) {
            $rows[] = array(
                'uuid'    => wp_generate_uuid4(),
                'row_key' => sanitize_key( (string) $key_name ),
                'label'   => $this->group_label( $config['group_by'], (string) $key_name ),
                'value'   => round( (float) $value, 2 ),
                'x_value' => 'month' === $config['group_by'] ? (string) $key_name : '',
                'color'   => '',
                'meta'    => array( 'source' => 'woocommerce' ),
            );
        }

        if ( 'total' === $config['group_by'] && ! $rows ) {
            $rows[] = array(
                'uuid' => wp_generate_uuid4(), 'row_key' => 'total', 'label' => __( 'Total', 'viswiz' ),
                'value' => 0.0, 'x_value' => '', 'color' => '', 'meta' => array( 'source' => 'woocommerce' ),
            );
        }

        $result = array(
            'rows' => $rows,
            'meta' => array(
                'cached'          => false,
                'cache_ttl'       => $ttl,
                'metric'          => $config['metric'],
                'group_by'        => $config['group_by'],
                'matched_orders'  => count( $matched_orders ),
                'queried_orders'  => count( $order_ids ),
                'start'           => $start->format( DATE_ATOM ),
                'end'             => $end->format( DATE_ATOM ),
                'currency'        => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
            ),
        );
        set_transient( $key, $result, $ttl );
        return $result;
    }

    public function sanitize_config( array $config ): array {
        $metric = sanitize_key( (string) ( $config['metric'] ?? 'revenue' ) );
        if ( ! in_array( $metric, array( 'revenue', 'orders', 'quantity' ), true ) ) {
            $metric = 'revenue';
        }
        $group = sanitize_key( (string) ( $config['group_by'] ?? 'total' ) );
        if ( ! in_array( $group, array( 'total', 'month', 'product', 'status' ), true ) ) {
            $group = 'total';
        }
        $period_mode = sanitize_key( (string) ( $config['period_mode'] ?? 'relative' ) );
        if ( ! in_array( $period_mode, array( 'relative', 'fixed' ), true ) ) {
            $period_mode = 'relative';
        }
        $unit = sanitize_key( (string) ( $config['period_unit'] ?? 'days' ) );
        if ( ! in_array( $unit, array( 'days', 'weeks', 'months', 'years' ), true ) ) {
            $unit = 'days';
        }
        $date_basis = sanitize_key( (string) ( $config['date_basis'] ?? 'created' ) );
        if ( ! in_array( $date_basis, array( 'created', 'paid', 'completed' ), true ) ) {
            $date_basis = 'created';
        }
        $revenue_basis = sanitize_key( (string) ( $config['revenue_basis'] ?? 'gross' ) );
        if ( ! in_array( $revenue_basis, array( 'gross', 'net_items', 'gross_items' ), true ) ) {
            $revenue_basis = 'gross';
        }
        return array(
            'metric'          => $metric,
            'group_by'        => $group,
            'period_mode'     => $period_mode,
            'period_value'    => $this->bounded_period_value( absint( $config['period_value'] ?? 30 ), $unit ),
            'period_unit'     => $unit,
            'period_start'    => sanitize_text_field( (string) ( $config['period_start'] ?? '' ) ),
            'period_end'      => sanitize_text_field( (string) ( $config['period_end'] ?? '' ) ),
            'date_basis'      => $date_basis,
            'revenue_basis'   => $revenue_basis,
            'deduct_refunds'  => ! isset( $config['deduct_refunds'] ) || Support::bool( $config['deduct_refunds'] ),
            'product_ids'     => Support::int_list( $config['product_ids'] ?? array() ),
            'category_ids'    => Support::int_list( $config['category_ids'] ?? array() ),
        );
    }

    private function resolve_window( array $config ): array {
        $tz  = wp_timezone();
        $now = new DateTimeImmutable( 'now', $tz );
        if ( 'fixed' === $config['period_mode'] ) {
            $start = $this->safe_date( $config['period_start'], $tz ) ?: $now->modify( '-30 days' );
            $end   = $this->safe_date( $config['period_end'], $tz ) ?: $now;
            if ( $end < $start ) {
                [ $start, $end ] = array( $end, $start );
            }
            $five_years_ago = $now->modify( '-5 years' );
            $start = max( $five_years_ago, min( $start, $now ) );
            $end   = max( $five_years_ago, min( $end, $now ) );
            if ( $end < $start ) {
                $start = $end;
            }
            return array( $start->setTime( 0, 0, 0 ), $end->setTime( 23, 59, 59 ) );
        }
        $value = $config['period_value'];
        $unit  = $config['period_unit'];
        $map   = array( 'days' => 'day', 'weeks' => 'week', 'months' => 'month', 'years' => 'year' );
        return array( $now->modify( sprintf( '-%d %s', $value, $map[ $unit ] ) ), $now );
    }

    private function safe_date( string $value, DateTimeZone $tz ): ?DateTimeImmutable {
        if ( '' === trim( $value ) ) {
            return null;
        }
        try {
            return new DateTimeImmutable( $value, $tz );
        } catch ( \Throwable ) {
            return null;
        }
    }

    private function order_ids( array $config, DateTimeImmutable $start, DateTimeImmutable $end ): array {
        $page     = 1;
        $ids      = array();
        $statuses = function_exists( 'wc_get_is_paid_statuses' ) ? wc_get_is_paid_statuses() : array( 'processing', 'completed' );
        $date_key = 'date_' . $config['date_basis'];
        do {
            $result = wc_get_orders(
                array(
                    'status'       => $statuses,
                    $date_key      => $start->format( 'Y-m-d H:i:s' ) . '...' . $end->format( 'Y-m-d H:i:s' ),
                    'limit'        => self::PAGE_SIZE,
                    'page'         => $page,
                    'paginate'     => true,
                    'return'       => 'ids',
                    'orderby'      => 'date',
                    'order'        => 'ASC',
                )
            );
            if ( ! is_object( $result ) || ! isset( $result->orders ) ) {
                break;
            }
            foreach ( $result->orders as $order_id ) {
                $ids[] = absint( $order_id );
            }
            $page++;
        } while ( $page <= (int) $result->max_num_pages );
        return array_values( array_unique( $ids ) );
    }

    private function matching_items( $order, array $product_ids, array $category_ids ): array {
        $matches = array();
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            $product_id = $this->item_product_id( $item );
            $parent_id  = (int) $item->get_product_id();
            if ( $product_ids && ! in_array( $product_id, $product_ids, true ) && ! in_array( $parent_id, $product_ids, true ) ) {
                continue;
            }
            if ( $category_ids ) {
                $terms = wp_get_post_terms( $parent_id, 'product_cat', array( 'fields' => 'ids' ) );
                if ( is_wp_error( $terms ) || ! array_intersect( array_map( 'absint', $terms ), $category_ids ) ) {
                    continue;
                }
            }
            $matches[] = $item;
        }
        return $matches;
    }

    private function category_ids_with_children( array $ids ): array {
        $all = $ids;
        foreach ( $ids as $id ) {
            $children = get_term_children( $id, 'product_cat' );
            if ( ! is_wp_error( $children ) ) {
                $all = array_merge( $all, array_map( 'absint', $children ) );
            }
        }
        return array_values( array_unique( array_filter( $all ) ) );
    }

    private function item_product_id( $item ): int {
        $variation_id = method_exists( $item, 'get_variation_id' ) ? (int) $item->get_variation_id() : 0;
        return $variation_id ?: (int) $item->get_product_id();
    }

    private function order_revenue( $order, array $config ): float {
        if ( 'net_items' === $config['revenue_basis'] || 'gross_items' === $config['revenue_basis'] ) {
            $total = 0.0;
            foreach ( $order->get_items( 'line_item' ) as $item ) {
                $total += $this->item_revenue( $item, $config, $order );
            }
            return $total;
        }
        $total = (float) $order->get_total();
        if ( $config['deduct_refunds'] && method_exists( $order, 'get_total_refunded' ) ) {
            $total -= (float) $order->get_total_refunded();
        }
        return max( 0.0, $total );
    }

    private function item_revenue( $item, array $config, $order = null ): float {
        $total = (float) $item->get_total();
        if ( 'gross_items' === $config['revenue_basis'] ) {
            $total += (float) $item->get_total_tax();
        }
        if ( $config['deduct_refunds'] && $order && method_exists( $order, 'get_total_refunded_for_item' ) ) {
            $total -= (float) $order->get_total_refunded_for_item( $item->get_id() );
            if ( 'gross_items' === $config['revenue_basis'] && method_exists( $order, 'get_tax_refunded_for_item' ) ) {
                $taxes = $item->get_taxes();
                foreach ( array_keys( (array) ( $taxes['total'] ?? array() ) ) as $tax_id ) {
                    $total -= (float) $order->get_tax_refunded_for_item( $item->get_id(), (int) $tax_id );
                }
            }
        }
        return max( 0.0, $total );
    }

    private function group_key( string $group, $order, $item, DateTimeImmutable $start, string $date_basis ): string {
        if ( 'month' === $group ) {
            $getter = 'get_date_' . $date_basis;
            $date   = method_exists( $order, $getter ) ? $order->{$getter}() : $order->get_date_created();
            return $date ? $date->date_i18n( 'Y-m' ) : $start->format( 'Y-m' );
        }
        if ( 'product' === $group && $item ) {
            return (string) $this->item_product_id( $item );
        }
        if ( 'status' === $group ) {
            return sanitize_key( (string) $order->get_status() );
        }
        return 'total';
    }

    private function bounded_period_value( int $value, string $unit ): int {
        $caps = array( 'days' => 1825, 'weeks' => 260, 'months' => 60, 'years' => 5 );
        return min( $caps[ $unit ] ?? 365, max( 1, $value ) );
    }

    private function cache_ttl(): int {
        $settings = get_option( 'viswiz_settings_v2', array() );
        return min( 1800, max( 60, absint( is_array( $settings ) ? ( $settings['cache_seconds'] ?? self::DEFAULT_CACHE_TTL ) : self::DEFAULT_CACHE_TTL ) ) );
    }

    private function group_label( string $group, string $key ): string {
        if ( 'product' === $group ) {
            return get_the_title( absint( $key ) ) ?: sprintf( __( 'Product #%d', 'viswiz' ), absint( $key ) );
        }
        if ( 'status' === $group ) {
            return function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $key ) : ucfirst( $key );
        }
        if ( 'month' === $group ) {
            $timestamp = strtotime( $key . '-01' );
            return $timestamp ? wp_date( 'F Y', $timestamp ) : $key;
        }
        return __( 'Total', 'viswiz' );
    }
}
