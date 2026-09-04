<?php
namespace VisWiz\Admin;

use VisWiz\Domain\Registry;
use VisWiz\Frontend\Frontend;
use VisWiz\Support;

final class VisualizationPresets {
    private const META_KEY = 'viswiz_visualization_presets_v1';
    private const MAX_PRESETS = 50;

    public static function register(): void {
        add_action( 'add_meta_boxes_viswiz_visualization', array( self::class, 'meta_box' ), 30 );
        add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ), 95 );
        add_action( 'wp_ajax_viswiz_visualization_preset_save', array( self::class, 'ajax_save' ) );
        add_action( 'wp_ajax_viswiz_visualization_preset_delete', array( self::class, 'ajax_delete' ) );
    }

    public static function meta_box(): void {
        if ( ! current_user_can( 'edit_viswiz_visualizations' ) ) {
            return;
        }

        add_meta_box(
            'viswiz-display-presets',
            __( 'Display presets', 'viswiz' ),
            array( self::class, 'render' ),
            'viswiz_visualization',
            'side',
            'default'
        );
    }

    public static function assets(): void {
        $screen = get_current_screen();
        if ( ! $screen || 'viswiz_visualization' !== $screen->post_type || ! current_user_can( 'edit_viswiz_visualizations' ) ) {
            return;
        }

        wp_enqueue_script(
            'viswiz-visualization-presets',
            VISWIZ_URL . 'assets/viswiz-visualization-presets.js',
            array( 'viswiz-visualization-preview' ),
            VISWIZ_VERSION,
            true
        );
        wp_localize_script(
            'viswiz-visualization-presets',
            'VisWizVisualizationPresets',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'viswiz_visualization_presets' ),
                'presets' => self::presets(),
                'i18n'    => array(
                    'selectPreset'   => __( 'Select preset', 'viswiz' ),
                    'saved'          => __( 'Display preset saved.', 'viswiz' ),
                    'applied'        => __( 'Preset applied to unsaved display settings.', 'viswiz' ),
                    'nothingApplied' => __( 'This preset has no settings supported by the current renderer.', 'viswiz' ),
                    'deleted'        => __( 'Display preset deleted.', 'viswiz' ),
                    'saving'         => __( 'Saving preset…', 'viswiz' ),
                    'deleting'       => __( 'Deleting preset…', 'viswiz' ),
                    'nameRequired'   => __( 'Enter a preset name.', 'viswiz' ),
                    'requestError'   => __( 'The display preset change could not be saved.', 'viswiz' ),
                    'confirmDelete'  => __( 'Delete this display preset?', 'viswiz' ),
                ),
            )
        );
        wp_add_inline_style(
            'viswiz-admin-v2',
            '.viswiz-preset-controls{display:grid;gap:8px}.viswiz-preset-actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap}.viswiz-preset-status{margin:8px 0 0}.viswiz-preset-status.is-error{color:#b32d2e}'
        );
    }

    public static function render(): void {
        $presets = self::presets();
        ?>
        <div class="viswiz-preset-controls" data-viswiz-display-presets>
            <p class="description"><?php esc_html_e( 'Presets store display settings only. The renderer, data source, dataset and WooCommerce query stay unchanged.', 'viswiz' ); ?></p>
            <label>
                <span class="screen-reader-text"><?php esc_html_e( 'Saved display preset', 'viswiz' ); ?></span>
                <select data-viswiz-preset-select>
                    <option value=""><?php esc_html_e( 'Select preset', 'viswiz' ); ?></option>
                    <?php foreach ( $presets as $preset ) : ?>
                        <option value="<?php echo esc_attr( $preset['id'] ); ?>"><?php echo esc_html( self::preset_option_label( $preset ) ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="viswiz-preset-actions">
                <button type="button" class="button" data-viswiz-preset-apply disabled><?php esc_html_e( 'Apply', 'viswiz' ); ?></button>
                <button type="button" class="button-link-delete" data-viswiz-preset-delete disabled><?php esc_html_e( 'Delete', 'viswiz' ); ?></button>
            </div>
            <label>
                <span><?php esc_html_e( 'New preset name', 'viswiz' ); ?></span>
                <input type="text" maxlength="80" data-viswiz-preset-name>
            </label>
            <button type="button" class="button" data-viswiz-preset-save><?php esc_html_e( 'Save current display as preset', 'viswiz' ); ?></button>
            <p class="description viswiz-preset-status" data-viswiz-preset-status aria-live="polite"></p>
        </div>
        <?php
    }

    public static function ajax_save(): void {
        self::require_ajax_access();

        $renderer = sanitize_key( (string) wp_unslash( $_POST['renderer'] ?? '' ) );
        if ( ! Registry::renderer_exists( $renderer ) ) {
            wp_send_json_error( array( 'message' => __( 'Select a valid renderer before saving a preset.', 'viswiz' ) ), 400 );
        }

        $name = sanitize_text_field( (string) wp_unslash( $_POST['name'] ?? '' ) );
        if ( '' === $name ) {
            wp_send_json_error( array( 'message' => __( 'Enter a preset name.', 'viswiz' ) ), 400 );
        }
        $name_length = function_exists( 'mb_strlen' ) ? mb_strlen( $name ) : strlen( $name );
        if ( $name_length > 80 ) {
            wp_send_json_error( array( 'message' => __( 'Preset names can contain at most 80 characters.', 'viswiz' ) ), 400 );
        }

        $presets = self::presets();
        if ( count( $presets ) >= self::MAX_PRESETS ) {
            wp_send_json_error( array( 'message' => __( 'Delete an existing preset before saving another one.', 'viswiz' ) ), 409 );
        }

        $settings = self::preset_settings( wp_unslash( $_POST['settings'] ?? '' ), $renderer );
        $preset = array(
            'id'       => wp_generate_uuid4(),
            'name'     => $name,
            'renderer' => $renderer,
            'settings' => $settings,
        );
        $presets[] = $preset;
        update_user_meta( get_current_user_id(), self::META_KEY, $presets );

        wp_send_json_success(
            array(
                'preset_id' => $preset['id'],
                'presets'   => self::presets(),
            )
        );
    }

    public static function ajax_delete(): void {
        self::require_ajax_access();

        $id = sanitize_text_field( (string) wp_unslash( $_POST['preset_id'] ?? '' ) );
        if ( '' === $id ) {
            wp_send_json_error( array( 'message' => __( 'Select a display preset to delete.', 'viswiz' ) ), 400 );
        }

        $presets = self::presets();
        $filtered = array_values(
            array_filter(
                $presets,
                static fn( array $preset ): bool => $preset['id'] !== $id
            )
        );
        if ( count( $filtered ) === count( $presets ) ) {
            wp_send_json_error( array( 'message' => __( 'The selected display preset no longer exists.', 'viswiz' ) ), 404 );
        }

        update_user_meta( get_current_user_id(), self::META_KEY, $filtered );
        wp_send_json_success( array( 'presets' => self::presets() ) );
    }

    private static function require_ajax_access(): void {
        if ( ! current_user_can( 'edit_viswiz_visualizations' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'viswiz' ) ), 403 );
        }
        check_ajax_referer( 'viswiz_visualization_presets', 'nonce' );
    }

    private static function presets(): array {
        $saved = get_user_meta( get_current_user_id(), self::META_KEY, true );
        if ( ! is_array( $saved ) ) {
            return array();
        }

        $presets = array();
        foreach ( array_slice( array_values( $saved ), 0, self::MAX_PRESETS ) as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $renderer = sanitize_key( (string) ( $item['renderer'] ?? '' ) );
            $id       = sanitize_text_field( (string) ( $item['id'] ?? '' ) );
            $name     = sanitize_text_field( (string) ( $item['name'] ?? '' ) );
            if ( '' === $id || '' === $name || ! Registry::renderer_exists( $renderer ) ) {
                continue;
            }
            $presets[] = array(
                'id'       => $id,
                'name'     => $name,
                'renderer' => $renderer,
                'settings' => self::preset_settings( $item['settings'] ?? array(), $renderer ),
            );
        }

        return $presets;
    }

    private static function preset_settings( mixed $value, string $renderer ): array {
        $raw          = Support::json_decode_array( $value );
        $sanitized    = Frontend::sanitize_settings( $raw, $renderer );
        $allowed      = array_values( array_diff( Registry::renderer_settings( $renderer ), array( 'title' ) ) );
        $allowed_keys = array_fill_keys( $allowed, true );
        $present_keys = array_fill_keys( array_keys( $raw ), true );

        return array_intersect_key( $sanitized, $allowed_keys, $present_keys );
    }

    private static function preset_option_label( array $preset ): string {
        $renderers = Registry::renderers();
        $renderer_label = (string) ( $renderers[ $preset['renderer'] ]['label'] ?? $preset['renderer'] );
        return $preset['name'] . ' — ' . $renderer_label;
    }
}
