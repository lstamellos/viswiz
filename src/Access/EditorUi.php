<?php
namespace VisWiz\Access;

final class EditorUi {
    public static function register(): void {
        add_action( 'add_meta_boxes_viswiz_visualization', array( self::class, 'visualization_box' ) );
        add_action( 'save_post_viswiz_visualization', array( self::class, 'save_visualization' ), 20 );
        add_action( 'admin_post_viswiz_dataset_access', array( self::class, 'save_dataset' ) );
    }

    public static function visualization_box(): void {
        add_meta_box( 'viswiz-access', __( 'Access permissions', 'viswiz' ), array( self::class, 'visualization_fields' ), 'viswiz_visualization', 'side' );
    }

    public static function visualization_fields( \WP_Post $post ): void {
        if ( ! Permissions::can_manage_visualization( $post->ID ) ) {
            echo '<p>' . esc_html__( 'Only the owner or an editor can manage access.', 'viswiz' ) . '</p>';
            return;
        }
        wp_nonce_field( 'viswiz_visualization_access_' . $post->ID, 'viswiz_access_nonce' );
        self::fields( (int) $post->post_author, Permissions::visualization_editors( $post->ID ), 'viswiz_access' );
    }

    public static function dataset_form( int $id, int $owner ): void {
        if ( ! Permissions::can_manage_dataset( $id ) ) {
            return;
        }
        ?>
        <section class="viswiz-card">
            <h2><?php esc_html_e( 'Access permissions', 'viswiz' ); ?></h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="viswiz_dataset_access">
                <input type="hidden" name="dataset_id" value="<?php echo esc_attr( (string) $id ); ?>">
                <?php wp_nonce_field( 'viswiz_dataset_access_' . $id ); ?>
                <?php self::fields( $owner, Permissions::dataset_editors( $id ), 'viswiz_access' ); ?>
                <p><button class="button button-primary"><?php esc_html_e( 'Save access', 'viswiz' ); ?></button></p>
            </form>
        </section>
        <?php
    }

    private static function fields( int $owner, array $editors, string $prefix ): void {
        $user = get_userdata( $owner );
        echo '<p><strong>' . esc_html__( 'Owner', 'viswiz' ) . ':</strong> ' . esc_html( $user ? $user->display_name : __( 'Unassigned', 'viswiz' ) ) . '</p>';
        echo '<p><label for="viswiz_access_editors">' . esc_html__( 'Additional editors (WordPress user IDs, separated by commas)', 'viswiz' ) . '</label>';
        echo '<input class="widefat" id="viswiz_access_editors" name="' . esc_attr( $prefix ) . '[editors]" value="' . esc_attr( implode( ',', $editors ) ) . '"></p>';
        if ( Permissions::manager( get_current_user_id() ) ) {
            echo '<p><label for="viswiz_access_owner">' . esc_html__( 'Change owner (WordPress user ID)', 'viswiz' ) . '</label>';
            echo '<input class="widefat" type="number" min="1" id="viswiz_access_owner" name="' . esc_attr( $prefix ) . '[owner]" value="' . esc_attr( (string) $owner ) . '"></p>';
        }
    }

    private static function data(): array {
        $raw = isset( $_POST['viswiz_access'] ) && is_array( $_POST['viswiz_access'] ) ? wp_unslash( $_POST['viswiz_access'] ) : array();
        return array(
            'editors' => array_filter( array_map( 'absint', explode( ',', (string) ( $raw['editors'] ?? '' ) ) ) ),
            'owner' => absint( $raw['owner'] ?? 0 ),
        );
    }

    public static function save_dataset(): void {
        $id = absint( $_POST['dataset_id'] ?? 0 );
        check_admin_referer( 'viswiz_dataset_access_' . $id );
        $data = self::data();
        if ( ! Permissions::save_dataset( $id, $data['editors'], $data['owner'] ) ) {
            wp_die( esc_html__( 'Permission denied or invalid owner.', 'viswiz' ), '', array( 'response' => 403 ) );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=viswiz-datasets&dataset_id=' . $id . '&access_saved=1' ) );
        exit;
    }

    public static function save_visualization( int $post_id ): void {
        if ( ! isset( $_POST['viswiz_access_nonce'] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['viswiz_access_nonce'] ) ), 'viswiz_visualization_access_' . $post_id )
            || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
            || wp_is_post_revision( $post_id ) ) {
            return;
        }
        $data = self::data();
        Permissions::save_visualization( $post_id, $data['editors'], $data['owner'] );
    }
}
