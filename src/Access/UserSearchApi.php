<?php
namespace VisWiz\Access;

use WP_REST_Request;
use WP_REST_Server;

/**
 * Authorized, bounded lookup for users who can be assigned access.
 * Never exposes user emails or user information to visitors without manage rights.
 */
final class UserSearchApi {
    public static function register(): void {
        add_action( 'rest_api_init', array( self::class, 'routes' ) );
    }

    public static function routes(): void {
        register_rest_route(
            'viswiz/v2',
            '/access/users',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( self::class, 'search' ),
                'permission_callback' => array( self::class, 'can_search' ),
                'args'                => array(
                    'kind'      => array( 'type' => 'string', 'required' => true, 'enum' => array( 'dataset', 'visualization' ) ),
                    'object_id' => array( 'type' => 'integer', 'required' => true, 'minimum' => 1, 'sanitize_callback' => 'absint' ),
                    'search'    => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
                ),
            )
        );
    }

    public static function can_search( WP_REST_Request $request ): bool {
        $id   = absint( $request->get_param( 'object_id' ) );
        $kind = (string) $request->get_param( 'kind' );

        if ( ! $id || ! is_user_logged_in() ) {
            return false;
        }
        if ( 'dataset' === $kind ) {
            return Permissions::can_manage_dataset( $id );
        }
        if ( 'visualization' === $kind ) {
            return Permissions::can_manage_visualization( $id );
        }
        return false;
    }

    public static function search( WP_REST_Request $request ) {
        $search = trim( sanitize_text_field( (string) $request->get_param( 'search' ) ) );
        $length = function_exists( 'mb_strlen' ) ? mb_strlen( $search, 'UTF-8' ) : strlen( $search );
        if ( $length < 2 || $length > 80 ) {
            return rest_ensure_response( array() );
        }

        // WordPress user search escapes the input for LIKE queries.
        // Restrict matching to usernames and public display names, never emails.
        $users = get_users(
            array(
                'search'         => '*' . $search . '*',
                'search_columns' => array( 'user_login', 'display_name' ),
                'capability'     => 'edit_posts',
                'number'         => 40,
                'orderby'        => 'display_name',
                'order'          => 'ASC',
            )
        );

        $kind    = (string) $request->get_param( 'kind' );
        $results = array();
        foreach ( $users as $user ) {
            $allowed = 'dataset' === $kind
                ? Permissions::can_create_dataset( (int) $user->ID )
                : Permissions::can_create_visualization( (int) $user->ID );
            if ( ! $allowed ) {
                continue;
            }
            $results[] = array(
                'id'       => (int) $user->ID,
                'name'     => (string) $user->display_name,
                'username' => (string) $user->user_login,
            );
            if ( count( $results ) >= 12 ) {
                break;
            }
        }
        return rest_ensure_response( $results );
    }
}
