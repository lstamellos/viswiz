<?php
namespace VisWiz;

use VisWiz\Admin\Admin;
use VisWiz\Database\Migrator;
use VisWiz\Frontend\Frontend;
use VisWiz\Rest\Api;
use VisWiz\Update\GitHubUpdater;
use VisWiz\WooCommerce\Compatibility;

final class Plugin {
    private const CAPABILITIES_VERSION = 1;

    public static function register(): void {
        register_activation_hook( VISWIZ_FILE, array( self::class, 'activate' ) );
        add_action( 'plugins_loaded', array( self::class, 'boot' ), 5 );
    }

    public static function activate(): void {
        Migrator::install();
        self::grant_capabilities();
        update_option( 'viswiz_capabilities_version', self::CAPABILITIES_VERSION, false );
        self::register_post_type();
        flush_rewrite_rules();
    }

    public static function boot(): void {
        load_plugin_textdomain( 'viswiz', false, dirname( plugin_basename( VISWIZ_FILE ) ) . '/languages' );

        add_action( 'init', array( self::class, 'register_post_type' ) );
        add_action( 'admin_init', array( Migrator::class, 'maybe_upgrade' ), 5 );
        add_action( 'admin_init', array( self::class, 'maybe_upgrade_capabilities' ), 6 );

        Admin::register();
        Api::register();
        Frontend::register();
        GitHubUpdater::register();
        Compatibility::register();
    }


    public static function maybe_upgrade_capabilities(): void {
        if ( (int) get_option( 'viswiz_capabilities_version', 0 ) >= self::CAPABILITIES_VERSION ) {
            return;
        }
        self::grant_capabilities();
        update_option( 'viswiz_capabilities_version', self::CAPABILITIES_VERSION, false );
    }

    public static function register_post_type(): void {
        register_post_type(
            'viswiz_visualization',
            array(
                'labels' => array(
                    'name'          => __( 'Visualizations', 'viswiz' ),
                    'singular_name' => __( 'Visualization', 'viswiz' ),
                    'add_new_item'  => __( 'Add visualization', 'viswiz' ),
                    'edit_item'     => __( 'Edit visualization', 'viswiz' ),
                ),
                'public'              => false,
                'publicly_queryable'  => false,
                'show_ui'             => true,
                'show_in_menu'        => 'viswiz',
                'show_in_rest'        => false,
                'supports'            => array( 'title' ),
                'capability_type'     => array( 'viswiz_visualization', 'viswiz_visualizations' ),
                'map_meta_cap'        => true,
                'capabilities'        => array(
                    'edit_post'              => 'edit_viswiz_visualization',
                    'read_post'              => 'read_viswiz_visualization',
                    'delete_post'            => 'delete_viswiz_visualization',
                    'edit_posts'             => 'edit_viswiz_visualizations',
                    'edit_others_posts'      => 'edit_others_viswiz_visualizations',
                    'publish_posts'          => 'publish_viswiz_visualizations',
                    'read_private_posts'     => 'read_private_viswiz_visualizations',
                    'delete_posts'           => 'delete_viswiz_visualizations',
                    'delete_private_posts'   => 'delete_private_viswiz_visualizations',
                    'delete_published_posts' => 'delete_published_viswiz_visualizations',
                    'delete_others_posts'    => 'delete_others_viswiz_visualizations',
                    'edit_private_posts'     => 'edit_private_viswiz_visualizations',
                    'edit_published_posts'   => 'edit_published_viswiz_visualizations',
                    'create_posts'           => 'edit_viswiz_visualizations',
                ),
            )
        );
    }

    private static function grant_capabilities(): void {
        $editor_caps = array(
            'edit_viswiz_visualization',
            'read_viswiz_visualization',
            'delete_viswiz_visualization',
            'edit_viswiz_visualizations',
            'edit_others_viswiz_visualizations',
            'publish_viswiz_visualizations',
            'read_private_viswiz_visualizations',
            'delete_viswiz_visualizations',
            'delete_private_viswiz_visualizations',
            'delete_published_viswiz_visualizations',
            'delete_others_viswiz_visualizations',
            'edit_private_viswiz_visualizations',
            'edit_published_viswiz_visualizations',
            'edit_viswiz_datasets',
        );

        foreach ( array( 'administrator', 'editor' ) as $role_name ) {
            $role = get_role( $role_name );
            if ( ! $role ) {
                continue;
            }
            foreach ( $editor_caps as $cap ) {
                $role->add_cap( $cap );
            }
        }

        $admin = get_role( 'administrator' );
        if ( $admin ) {
            foreach ( array( 'manage_viswiz_schema', 'manage_viswiz_settings', 'manage_viswiz_updates' ) as $cap ) {
                $admin->add_cap( $cap );
            }
        }
    }
}
