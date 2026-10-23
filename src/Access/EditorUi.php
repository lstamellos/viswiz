<?php
namespace VisWiz\Access;

final class EditorUi {
    public static function register(): void {
        add_action( 'add_meta_boxes_viswiz_visualization', array( self::class, 'visualization_box' ) );
        add_action( 'save_post_viswiz_visualization', array( self::class, 'save_visualization' ), 20 );
        add_action( 'admin_post_viswiz_dataset_access', array( self::class, 'save_dataset' ) );
        add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
    }

    public static function assets(): void {
        $screen = get_current_screen();
        $page = sanitize_key( (string) ( $_GET['page'] ?? '' ) );
        $dataset_id = absint( $_GET['dataset_id'] ?? 0 );
        if ( ! ( $screen && 'viswiz_visualization' === $screen->post_type )
            && ! ( 'viswiz-datasets' === $page && $dataset_id ) ) {
            return;
        }

        wp_enqueue_script(
            'viswiz-access-control',
            VISWIZ_URL . 'assets/viswiz-access-control.js',
            array( 'wp-i18n' ),
            VISWIZ_VERSION,
            true
        );
        wp_localize_script(
            'viswiz-access-control',
            'VisWizAccessControl',
            array(
                'endpoint' => esc_url_raw( rest_url( 'viswiz/v2/access/users' ) ),
                'nonce'    => wp_create_nonce( 'wp_rest' ),
            )
        );
        wp_set_script_translations( 'viswiz-access-control', 'viswiz', VISWIZ_DIR . 'languages' );
        wp_enqueue_style(
            'viswiz-access-control',
            VISWIZ_URL . 'assets/viswiz-access-control.css',
            array( 'viswiz-admin-v2' ),
            VISWIZ_VERSION
        );
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
        self::fields( (int) $post->post_author, Permissions::visualization_editors( $post->ID ), 'visualization', $post->ID );
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
                <?php self::fields( $owner, Permissions::dataset_editors( $id ), 'dataset', $id ); ?>
                <p><button class="button button-primary"><?php esc_html_e( 'Save access', 'viswiz' ); ?></button></p>
            </form>
        </section>
        <?php
    }

    /**
     * Visible display names/usernames; only selected IDs are submitted to WordPress.
     */
    private static function fields( int $owner, array $editors, string $kind, int $object_id ): void {
        $user = get_userdata( $owner );
        if ( Permissions::manager( get_current_user_id() ) ) {
            echo '<p><strong>' . esc_html__( 'Owner', 'viswiz' ) . '</strong></p>';
            self::picker( $kind, $object_id, 'owner', $user ? array( $user ) : array() );
        } else {
            echo '<p><strong>' . esc_html__( 'Owner', 'viswiz' ) . ':</strong> ' .
                esc_html( $user ? $user->display_name . ' (@' . $user->user_login . ')' : __( 'Unassigned', 'viswiz' ) ) . '</p>';
        }

        $selected = array();
        foreach ( $editors as $user_id ) {
            $editor = get_userdata( absint( $user_id ) );
            if ( $editor ) {
                $selected[] = $editor;
            }
        }
        echo '<p><strong>' . esc_html__( 'Additional editors', 'viswiz' ) . '</strong></p>';
        self::picker( $kind, $object_id, 'editors', $selected );
    }

    private static function picker( string $kind, int $object_id, string $mode, array $users ): void {
        $input_id = 'viswiz-user-' . $kind . '-' . $object_id . '-' . $mode;
        $list_id  = $input_id . '-suggestions';
        $label    = 'owner' === $mode
            ? __( 'Find owner by name or username', 'viswiz' )
            : __( 'Find collaborators by name or username', 'viswiz' );
        ?>
        <div class="viswiz-access-picker" data-viswiz-user-picker
             data-kind="<?php echo esc_attr( $kind ); ?>"
             data-object-id="<?php echo esc_attr( (string) $object_id ); ?>"
             data-mode="<?php echo esc_attr( $mode ); ?>">
            <ul class="viswiz-access-selected" data-viswiz-selected>
                <?php foreach ( $users as $user ) : ?>
                    <li data-user-id="<?php echo esc_attr( (string) $user->ID ); ?>">
                        <span><?php echo esc_html( $user->display_name ); ?> <small>@<?php echo esc_html( $user->user_login ); ?></small></span>
                        <input type="hidden" name="viswiz_access[<?php echo esc_attr( $mode ); ?>]<?php echo 'editors' === $mode ? '[]' : ''; ?>"
                               value="<?php echo esc_attr( (string) $user->ID ); ?>">
                        <button type="button" class="button-link" data-viswiz-remove-user
                                aria-label="<?php echo esc_attr( sprintf( __( 'Remove %s', 'viswiz' ), $user->display_name ) ); ?>">&times;</button>
                    </li>
                <?php endforeach; ?>
            </ul>
            <label for="<?php echo esc_attr( $input_id ); ?>" class="screen-reader-text"><?php echo esc_html( $label ); ?></label>
            <input type="search" id="<?php echo esc_attr( $input_id ); ?>"
                   class="widefat" data-viswiz-user-search
                   autocomplete="off" placeholder="<?php echo esc_attr( $label ); ?>"
                   role="combobox" aria-autocomplete="list" aria-expanded="false"
                   aria-controls="<?php echo esc_attr( $list_id ); ?>">
            <ul id="<?php echo esc_attr( $list_id ); ?>" role="listbox"
                class="viswiz-access-suggestions" data-viswiz-user-results hidden></ul>
            <p class="description viswiz-access-status" data-viswiz-user-status role="status" aria-live="polite"></p>
        </div>
        <?php
    }

    private static function data(): array {
        $raw = isset( $_POST['viswiz_access'] ) && is_array( $_POST['viswiz_access'] )
            ? wp_unslash( $_POST['viswiz_access'] ) : array();
        $editors = $raw['editors'] ?? array();
        if ( ! is_array( $editors ) ) {
            // Compatibility with previously rendered comma-separated ID forms.
            $editors = explode( ',', (string) $editors );
        }
        return array(
            'editors' => array_values( array_filter( array_map( 'absint', $editors ) ) ),
            'owner'   => absint( $raw['owner'] ?? 0 ),
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
