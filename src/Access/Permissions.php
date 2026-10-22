<?php
namespace VisWiz\Access;

use VisWiz\Database\DatasetRepository;

/**
 * Per-object edit ACL. WordPress primitive capabilities remain mandatory.
 * Public visualization rendering is intentionally unaffected.
 */
final class Permissions {
    private const DATASET_ACL_PREFIX = 'viswiz_dataset_editors_';
    private const VISUALIZATION_ACL_KEY = '_viswiz_editors';
    private const USER_DATASET_INDEX = '_viswiz_accessible_datasets';
    private const USER_VISUALIZATION_INDEX = '_viswiz_accessible_visualizations';

    public static function register(): void {
        add_filter( 'map_meta_cap', array( self::class, 'map_visualization_cap' ), 20, 4 );
        add_action( 'pre_get_posts', array( self::class, 'filter_admin_visualization_list' ) );
        add_filter( 'user_has_cap', array( self::class, 'inherit_post_creation_caps' ), 10, 1 );
    }

    /** Any WordPress role allowed to create posts may also create VisWiz objects. */
    public static function inherit_post_creation_caps( array $caps ): array {
        if ( ! empty( $caps['edit_posts'] ) ) {
            $caps['edit_viswiz_visualizations'] = true;
            $caps['edit_viswiz_datasets'] = true;
        }
        return $caps;
    }

    public static function manager( int $user_id ): bool {
        return user_can( $user_id, 'edit_others_viswiz_visualizations' ) && user_can( $user_id, 'edit_viswiz_datasets' );
    }

    public static function can_create_dataset( int $user_id = 0 ): bool {
        $user_id = $user_id ?: get_current_user_id();
        return $user_id > 0 && user_can( $user_id, 'edit_posts' ) && user_can( $user_id, 'edit_viswiz_datasets' );
    }

    public static function can_edit_dataset( int $id, int $user_id = 0 ): bool {
        $user_id = $user_id ?: get_current_user_id();
        if ( ! self::can_create_dataset( $user_id ) ) {
            return false;
        }
        $dataset = ( new DatasetRepository() )->get( $id );
        if ( ! $dataset ) {
            return false;
        }
        return self::manager( $user_id )
            || $user_id === (int) $dataset['created_by']
            || in_array( $user_id, self::dataset_editors( $id ), true );
    }

    public static function can_manage_dataset( int $id, int $user_id = 0 ): bool {
        $user_id = $user_id ?: get_current_user_id();
        if ( ! self::can_edit_dataset( $id, $user_id ) ) {
            return false;
        }
        $dataset = ( new DatasetRepository() )->get( $id );
        return self::manager( $user_id ) || ( $dataset && $user_id === (int) $dataset['created_by'] );
    }

    public static function dataset_editors( int $id ): array {
        return self::ids( get_option( self::DATASET_ACL_PREFIX . $id, array() ) );
    }

    public static function visualization_editors( int $id ): array {
        return self::ids( get_post_meta( $id, self::VISUALIZATION_ACL_KEY, true ) );
    }

    public static function assigned_dataset_ids( int $user_id ): array {
        return self::ids( get_user_meta( $user_id, self::USER_DATASET_INDEX, true ) );
    }

    public static function assigned_visualization_ids( int $user_id ): array {
        return self::ids( get_user_meta( $user_id, self::USER_VISUALIZATION_INDEX, true ) );
    }

    private static function sync_reverse_index( string $key, int $object_id, array $old, array $new ): void {
        foreach ( array_unique( array_merge( $old, $new ) ) as $user_id ) {
            $ids = self::ids( get_user_meta( $user_id, $key, true ) );
            $ids = array_values( array_diff( $ids, array( $object_id ) ) );
            if ( in_array( $user_id, $new, true ) ) {
                $ids[] = $object_id;
            }
            update_user_meta( $user_id, $key, self::ids( $ids ) );
        }
    }

    public static function filter_admin_visualization_list( \WP_Query $query ): void {
        if ( ! is_admin() || ! $query->is_main_query() || 'viswiz_visualization' !== $query->get( 'post_type' )
            || self::manager( get_current_user_id() ) || ! self::can_create_visualization() ) {
            return;
        }
        $mine = get_posts( array( 'post_type' => 'viswiz_visualization', 'post_status' => 'any',
            'author' => get_current_user_id(), 'fields' => 'ids', 'posts_per_page' => -1, 'suppress_filters' => true ) );
        $visible = self::ids( array_merge( $mine, self::assigned_visualization_ids( get_current_user_id() ) ) );
        $query->set( 'post__in', $visible ?: array( 0 ) );
        $query->set( 'author', '' );
    }

    private static function ids( $value ): array {
        return array_values( array_unique( array_filter( array_map( 'absint', is_array( $value ) ? $value : array() ) ) ) );
    }

    public static function can_manage_visualization( int $post_id, int $user_id = 0 ): bool {
        $user_id = $user_id ?: get_current_user_id();
        $post = get_post( $post_id );
        return $post && 'viswiz_visualization' === $post->post_type
            && self::can_create_visualization( $user_id )
            && ( self::manager( $user_id ) || (int) $post->post_author === $user_id );
    }

    public static function can_create_visualization( int $user_id = 0 ): bool {
        $user_id = $user_id ?: get_current_user_id();
        return $user_id > 0 && user_can( $user_id, 'edit_posts' ) && user_can( $user_id, 'edit_viswiz_visualizations' );
    }

    public static function can_edit_visualization( int $post_id, int $user_id = 0 ): bool {
        $user_id = $user_id ?: get_current_user_id();
        $post = get_post( $post_id );
        return $post && 'viswiz_visualization' === $post->post_type
            && self::can_create_visualization( $user_id )
            && ( self::manager( $user_id ) || (int) $post->post_author === $user_id
                || in_array( $user_id, self::visualization_editors( $post_id ), true ) );
    }

    public static function map_visualization_cap( array $caps, string $cap, int $user_id, array $args ): array {
        if ( ! in_array( $cap, array( 'edit_post', 'edit_viswiz_visualization' ), true ) || empty( $args[0] ) ) {
            return $caps;
        }
        $post = get_post( absint( $args[0] ) );
        if ( ! $post || 'viswiz_visualization' !== $post->post_type ) {
            return $caps;
        }
        return self::can_edit_visualization( (int) $post->ID, $user_id )
            ? array( 'edit_viswiz_visualizations' )
            : array( 'do_not_allow' );
    }

    public static function save_dataset( int $id, array $editors, int $owner = 0 ): bool {
        if ( ! self::can_manage_dataset( $id ) ) {
            return false;
        }
        if ( $owner && ! self::manager( get_current_user_id() ) ) {
            return false;
        }
        $editors = array_values( array_filter( self::ids( $editors ), array( self::class, 'can_create_dataset' ) ) );
        if ( $owner && ! self::can_create_dataset( $owner ) ) {
            return false;
        }
        $previous = self::dataset_editors( $id );
        update_option( self::DATASET_ACL_PREFIX . $id, $editors, false );
        self::sync_reverse_index( self::USER_DATASET_INDEX, $id, $previous, $editors );
        if ( $owner ) {
            global $wpdb;
            $wpdb->update( \VisWiz\Support::table( 'datasets' ), array( 'created_by' => $owner ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
        }
        return true;
    }

    public static function save_visualization( int $id, array $editors, int $owner = 0 ): bool {
        if ( ! self::can_manage_visualization( $id ) ) {
            return false;
        }
        if ( $owner && ! self::manager( get_current_user_id() ) ) {
            return false;
        }
        $editors = array_values( array_filter( self::ids( $editors ), array( self::class, 'can_create_visualization' ) ) );
        if ( $owner && ! self::can_create_visualization( $owner ) ) {
            return false;
        }
        $previous = self::visualization_editors( $id );
        update_post_meta( $id, self::VISUALIZATION_ACL_KEY, $editors );
        self::sync_reverse_index( self::USER_VISUALIZATION_INDEX, $id, $previous, $editors );
        if ( $owner && (int) get_post_field( 'post_author', $id ) !== $owner ) {
            wp_update_post( array( 'ID' => $id, 'post_author' => $owner ) );
        }
        return true;
    }
}
