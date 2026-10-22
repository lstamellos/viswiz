<?php
namespace VisWiz;

use VisWiz\Database\DatasetRepository;

final class Access {
    private const EDITOR_META = '_viswiz_editor_access';
    private const CREATOR_META = '_viswiz_created_by';

    public static function register(): void {
        add_filter( 'map_meta_cap', array( self::class, 'map_visualization_edit' ), 10, 4 );
        add_action( 'wp_after_insert_post', array( self::class, 'remember_creator' ), 10, 2 );
        add_filter( 'posts_where', array( self::class, 'restrict_visualization_list' ), 10, 2 );
        add_action( 'admin_post_viswiz_dataset_access', array( self::class, 'save_dataset_access' ) );
        add_action( 'add_meta_boxes_viswiz_visualization', array( self::class, 'add_visualization_box' ), 30 );
        add_action( 'save_post_viswiz_visualization', array( self::class, 'save_visualization_access' ), 30, 2 );
    }

    public static function is_manager( int $user_id = 0 ): bool {
        $user_id = $user_id ?: get_current_user_id();
        return $user_id > 0 && user_can( $user_id, 'edit_others_posts' );
    }

    private static function can_work( int $user_id, string $cap ): bool {
        return $user_id > 0 && user_can( $user_id, 'edit_posts' ) && user_can( $user_id, $cap );
    }

    public static function can_edit_dataset( int $id, int $user_id = 0 ): bool {
        $user_id = $user_id ?: get_current_user_id();
        if ( $id < 1 || ! self::can_work( $user_id, 'edit_viswiz_datasets' ) ) {
            return false;
        }
        $dataset = ( new DatasetRepository() )->get( $id );
        if ( ! $dataset ) {
            return false;
        }
        if ( self::is_manager( $user_id ) ||
             $user_id === (int) $dataset['created_by'] ||
             $user_id === (int) ( $dataset['owner_id'] ?? 0 ) ) {
            return true;
        }
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            'SELECT 1 FROM ' . Support::table( 'dataset_editors' ) . ' WHERE dataset_id=%d AND user_id=%d',
            $id, $user_id
        ) );
    }

    public static function can_manage_dataset( int $id, int $user_id = 0 ): bool {
        $user_id = $user_id ?: get_current_user_id();
        if ( ! self::can_work( $user_id, 'edit_viswiz_datasets' ) ) {
            return false;
        }
        $dataset = ( new DatasetRepository() )->get( $id );
        return (bool) $dataset && (
            self::is_manager( $user_id ) ||
            $user_id === (int) $dataset['created_by'] ||
            $user_id === (int) ( $dataset['owner_id'] ?? 0 )
        );
    }

    public static function can_edit_visualization( int $id, int $user_id = 0 ): bool {
        $user_id = $user_id ?: get_current_user_id();
        $post = get_post( $id );
        if ( ! $post || 'viswiz_visualization' !== $post->post_type ||
             ! self::can_work( $user_id, 'edit_viswiz_visualizations' ) ) {
            return false;
        }
        return self::is_manager( $user_id ) ||
            $user_id === (int) $post->post_author ||
            $user_id === self::visualization_creator( $post ) ||
            in_array( $user_id, array_map( 'intval', get_post_meta( $id, self::EDITOR_META, false ) ), true );
    }

    public static function can_manage_visualization( int $id, int $user_id = 0 ): bool {
        $user_id = $user_id ?: get_current_user_id();
        $post = get_post( $id );
        return $post && 'viswiz_visualization' === $post->post_type &&
            self::can_work( $user_id, 'edit_viswiz_visualizations' ) &&
            ( self::is_manager( $user_id ) ||
              $user_id === (int) $post->post_author ||
              $user_id === self::visualization_creator( $post ) );
    }

    private static function visualization_creator( \WP_Post $post ): int {
        $creator = (int) get_post_meta( $post->ID, self::CREATOR_META, true );
        return $creator ?: (int) $post->post_author;
    }

    public static function remember_creator( int $post_id, \WP_Post $post ): void {
        if ( 'viswiz_visualization' === $post->post_type &&
             ! metadata_exists( 'post', $post_id, self::CREATOR_META ) ) {
            add_post_meta( $post_id, self::CREATOR_META, (int) $post->post_author, true );
        }
    }

    public static function map_visualization_edit( array $caps, string $cap, int $user_id, array $args ): array {
        if ( 'edit_post' !== $cap || empty( $args[0] ) ) {
            return $caps;
        }
        $post = get_post( (int) $args[0] );
        if ( ! $post || 'viswiz_visualization' !== $post->post_type ) {
            return $caps;
        }
        return self::can_edit_visualization( $post->ID, $user_id )
            ? array( 'edit_viswiz_visualizations' )
            : array( 'do_not_allow' );
    }

    public static function restrict_visualization_list( string $where, \WP_Query $query ): string {
        global $wpdb, $pagenow;
        if ( ! is_admin() || 'edit.php' !== $pagenow || ! $query->is_main_query() ||
             'viswiz_visualization' !== $query->get( 'post_type' ) || self::is_manager() ) {
            return $where;
        }
        $uid = get_current_user_id();
        if ( ! self::can_work( $uid, 'edit_viswiz_visualizations' ) ) {
            return $where . ' AND 1=0';
        }
        return $where . $wpdb->prepare(
            " AND ( {$wpdb->posts}.post_author=%d OR EXISTS (
                SELECT 1 FROM {$wpdb->postmeta} vw_access
                WHERE vw_access.post_id={$wpdb->posts}.ID
                AND ( (vw_access.meta_key=%s OR vw_access.meta_key=%s) AND vw_access.meta_value=%s )
            ) )",
            $uid, self::CREATOR_META, self::EDITOR_META, (string) $uid
        );
    }

    public static function dataset_editors( int $id ): array {
        global $wpdb;
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            'SELECT user_id FROM ' . Support::table( 'dataset_editors' ) . ' WHERE dataset_id=%d ORDER BY user_id',
            $id
        ) ) ?: array() );
    }

    private static function username( int $uid ): string {
        $user = get_userdata( $uid );
        return $user ? (string) $user->user_login : '#' . $uid;
    }

    private static function resolve_user( string $login ): int {
        $user = get_user_by( 'login', trim( $login ) );
        return $user && self::can_work( (int) $user->ID, 'edit_viswiz_datasets' ) ? (int) $user->ID : 0;
    }

    private static function require_target( string $login, string $cap ) {
        $user = get_user_by( 'login', trim( $login ) );
        if ( ! $user || ! self::can_work( (int) $user->ID, $cap ) ) {
            wp_die( esc_html__( 'User not found or lacks WordPress editing privileges.', 'viswiz' ), '', array( 'response' => 400 ) );
        }
        return (int) $user->ID;
    }

    public static function dataset_box( array $dataset ): void {
        $id = (int) $dataset['id'];
        if ( ! self::can_manage_dataset( $id ) ) {
            return;
        }
        $owner = (int) ( $dataset['owner_id'] ?: $dataset['created_by'] );
        echo '<section class="viswiz-card"><h2>' . esc_html__( 'Access & ownership', 'viswiz' ) . '</h2>';
        echo '<p>' . esc_html__( 'Creator:', 'viswiz' ) . ' <strong>' . esc_html( self::username( (int) $dataset['created_by'] ) ) . '</strong></p>';
        echo '<p>' . esc_html__( 'Owner:', 'viswiz' ) . ' <strong>' . esc_html( self::username( $owner ) ) . '</strong></p>';
        echo '<p>' . esc_html__( 'Additional editors:', 'viswiz' ) . ' ';
        $editors = self::dataset_editors( $id );
        echo esc_html( $editors ? implode( ', ', array_map( array( self::class, 'username' ), $editors ) ) : __( 'None', 'viswiz' ) ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="viswiz_dataset_access"><input type="hidden" name="dataset_id" value="' . esc_attr( (string) $id ) . '">';
        wp_nonce_field( 'viswiz_dataset_access_' . $id );
        echo '<label class="viswiz-field"><span>' . esc_html__( 'WordPress username', 'viswiz' ) . '</span><input type="text" required name="username" autocomplete="off"></label>';
        echo '<label class="viswiz-field"><span>' . esc_html__( 'Operation', 'viswiz' ) . '</span><select name="operation">';
        echo '<option value="add">' . esc_html__( 'Grant edit access', 'viswiz' ) . '</option><option value="remove">' . esc_html__( 'Revoke edit access', 'viswiz' ) . '</option>';
        if ( self::is_manager() ) {
            echo '<option value="transfer">' . esc_html__( 'Transfer ownership', 'viswiz' ) . '</option>';
        }
        echo '</select></label><p><button class="button button-primary">' . esc_html__( 'Apply', 'viswiz' ) . '</button></p></form></section>';
    }

    public static function save_dataset_access(): void {
        $id = absint( $_POST['dataset_id'] ?? 0 );
        if ( ! self::can_manage_dataset( $id ) ) {
            wp_die( esc_html__( 'Permission denied.', 'viswiz' ), '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'viswiz_dataset_access_' . $id );
        $operation = sanitize_key( wp_unslash( $_POST['operation'] ?? '' ) );
        $username = sanitize_user( wp_unslash( $_POST['username'] ?? '' ) );
        $uid = self::require_target( $username, 'edit_viswiz_datasets' );
        global $wpdb;
        $table = Support::table( 'dataset_editors' );
        if ( 'add' === $operation ) {
            $wpdb->replace( $table, array( 'dataset_id' => $id, 'user_id' => $uid ), array( '%d', '%d' ) );
        } elseif ( 'remove' === $operation ) {
            $wpdb->delete( $table, array( 'dataset_id' => $id, 'user_id' => $uid ), array( '%d', '%d' ) );
        } elseif ( 'transfer' === $operation && self::is_manager() ) {
            $wpdb->update( Support::table( 'datasets' ), array( 'owner_id' => $uid ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
        } else {
            wp_die( esc_html__( 'Invalid access operation.', 'viswiz' ), '', array( 'response' => 400 ) );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=viswiz-datasets&dataset_id=' . $id . '&access_updated=1' ) );
        exit;
    }

    public static function add_visualization_box( \WP_Post $post ): void {
        if ( 'auto-draft' === $post->post_status || ! self::can_manage_visualization( $post->ID ) ) {
            return;
        }
        add_meta_box( 'viswiz-access', __( 'Access & ownership', 'viswiz' ), array( self::class, 'visualization_box' ), 'viswiz_visualization', 'side', 'default' );
    }

    public static function visualization_box( \WP_Post $post ): void {
        wp_nonce_field( 'viswiz_access_' . $post->ID, 'viswiz_access_nonce' );
        echo '<p>' . esc_html__( 'Creator:', 'viswiz' ) . ' <strong>' . esc_html( self::username( self::visualization_creator( $post ) ) ) . '</strong></p>';
        echo '<p>' . esc_html__( 'Owner:', 'viswiz' ) . ' <strong>' . esc_html( self::username( (int) $post->post_author ) ) . '</strong></p>';
        $editors = array_map( 'intval', get_post_meta( $post->ID, self::EDITOR_META, false ) );
        echo '<p>' . esc_html__( 'Additional editors:', 'viswiz' ) . ' ' . esc_html( $editors ? implode( ', ', array_map( array( self::class, 'username' ), $editors ) ) : __( 'None', 'viswiz' ) ) . '</p>';
        echo '<p><label>' . esc_html__( 'Grant edit access (username)', 'viswiz' ) . '<input type="text" class="widefat" name="viswiz_access_add"></label></p>';
        echo '<p><label>' . esc_html__( 'Revoke edit access (username)', 'viswiz' ) . '<input type="text" class="widefat" name="viswiz_access_remove"></label></p>';
        if ( self::is_manager() ) {
            echo '<p><label>' . esc_html__( 'Transfer ownership (username)', 'viswiz' ) . '<input type="text" class="widefat" name="viswiz_access_owner"></label></p>';
        }
        echo '<p class="description">' . esc_html__( 'Changes take effect when you update the visualization. The creator always retains edit access.', 'viswiz' ) . '</p>';
    }

    public static function save_visualization_access( int $post_id, \WP_Post $post ): void {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ||
             ! isset( $_POST['viswiz_access_nonce'] ) ||
             ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['viswiz_access_nonce'] ) ), 'viswiz_access_' . $post_id ) ||
             ! self::can_manage_visualization( $post_id ) ) {
            return;
        }
        foreach ( array( 'add', 'remove' ) as $operation ) {
            $key = 'viswiz_access_' . $operation;
            $login = sanitize_user( wp_unslash( $_POST[ $key ] ?? '' ) );
            if ( '' === $login ) {
                continue;
            }
            $uid = self::require_target( $login, 'edit_viswiz_visualizations' );
            if ( 'add' === $operation ) {
                if ( ! in_array( $uid, array_map( 'intval', get_post_meta( $post_id, self::EDITOR_META, false ) ), true ) ) {
                    add_post_meta( $post_id, self::EDITOR_META, $uid );
                }
            } else {
                delete_post_meta( $post_id, self::EDITOR_META, $uid );
            }
        }
        if ( self::is_manager() && ! empty( $_POST['viswiz_access_owner'] ) ) {
            $uid = self::require_target( sanitize_user( wp_unslash( $_POST['viswiz_access_owner'] ) ), 'edit_viswiz_visualizations' );
            if ( ! metadata_exists( 'post', $post_id, self::CREATOR_META ) ) {
                add_post_meta( $post_id, self::CREATOR_META, (int) $post->post_author, true );
            }
            // Avoid re-entering the save_post hook.
            remove_action( 'save_post_viswiz_visualization', array( self::class, 'save_visualization_access' ), 30 );
            wp_update_post( array( 'ID' => $post_id, 'post_author' => $uid ) );
            add_action( 'save_post_viswiz_visualization', array( self::class, 'save_visualization_access' ), 30, 2 );
        }
    }
}
