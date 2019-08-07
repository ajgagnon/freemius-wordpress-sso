<?php
    /**
     * Plugin Name: Freemius SSO (Single Sign-On)
     * Plugin URI:  https://freemius.com/
     * Description: SSO for Freemius powered shops.
     * Version:     1.0.0
     * Author:      Freemius
     * Author URI:  https://freemius.com
     * License:     MIT
     */

    /**
     * @package     Freemius SSO
     * @copyright   Copyright (c) 2018, Freemius, Inc.
     * @license     https://opensource.org/licenses/mit MIT License
     * @since       1.0.0
     */

    if ( ! defined( 'ABSPATH' ) ) {
        exit;
    }

    class FS_SSO {
        #region Properties

        /**
         * @var number
         */
        private $_store_id;
        /**
         * @var number
         */
        private $_developer_id;
        /**
         * @var string
         */
        private $_developer_secret_key;
        /**
         * @var bool
         */
        private $_use_localhost_api;

        #endregion

        #region Singleton

        /**
         * @var FS_SSO
         */
        private static $instance;

        /**
         * @return FS_SSO
         */
        static function instance() {
            if ( ! isset( self::$instance ) ) {
                self::$instance = new self();
            }

            return self::$instance;
        }

        #endregion
        private function __construct() {
            add_filter( 'authenticate', array( &$this, 'authenticate' ), 30, 3 );

            // Clean up the stored access token upon logout.
            add_action( 'clear_auth_cookie', array( &$this, 'clear_access_token' ), 30, 3 );
        }

        /**
         * @param number $store_id
         * @param number $developer_id
         * @param string $developer_secret_key
         * @param bool $use_localhost_api
         */
        public function init(
            $store_id,
            $developer_id,
            $developer_secret_key,
            $use_localhost_api = false
        ) {
            $this->_store_id             = $store_id;
            $this->_developer_id         = $developer_id;
            $this->_developer_secret_key = $developer_secret_key;
            $this->_use_localhost_api    = $use_localhost_api;
        }

        /**
         * This logic assumes that if a user exists in WP, there's a matching user (based on email) in Freemius.
         *
         * @param WP_User|null|WP_Error $user
         * @param string                $username Username or email.
         * @param string                $password Plain text password.
         *
         * @return WP_User|null|WP_Error
         */
        public function authenticate( $user, $username, $password ) {
            $is_login_by_email = strpos( $username, '@' );
            $wp_user_found     = ( $user instanceof WP_User );

            /**
             * If there's no matching user in WP and the login is by a username and not an email address, there's no way for us to fetch an access token for the user.
             */
            if ( ! $wp_user_found &&
                 ! $is_login_by_email
            ) {
                return $user;
            }

            if ( is_wp_error( $user ) &&
                 ! in_array( $user->get_error_code(), array(
                     'authentication_failed',
                     'invalid_email',
                     'invalid_password',
                 ) )
            ) {
                return $user;
            }


            $email = $is_login_by_email ?
                $username :
                $user->user_email;

            /**
             *
             */
            $fs_user_token = null;
            $fs_user_id    = null;

            $fetch_access_token = true;
            if ( $wp_user_found ) {
                $fs_user_id = get_user_meta( $user->ID, 'fs_user_id', true );

                if ( is_numeric( $fs_user_id ) ) {
                    $fs_user_token = get_user_meta( $user->ID, 'fs_token', true );

                    if ( ! empty( $fs_user_token ) ) {
                        // Validate access token didn't yet to expire.
                        if ( $fs_user_token->expires > time() ) {
                            // No need to get a new access token for now, we can use the cached token.
                            $fetch_access_token = false;
                        }
                    }
                }
            }

            if ( $fetch_access_token ) {
                // Fetch user's info and access token from Freemius.
                $result = $this->fetch_user_access_token(
                    $email,
                    ( $wp_user_found ? '' : $password )
                );

                if ( is_wp_error( $result ) ) {
                    return $user;
                }

                $result = json_decode( $result['body'] );

                if ( isset( $result->error ) ) {
                    if ( $wp_user_found ) {
                        return $user;
                    } else {
                        return new WP_Error( $result->error->code, __( '<strong>ERROR</strong>: ' . $result->error->message ) );
                    }
                }

                $fs_user       = $result->user_token->person;
                $fs_user_id    = $fs_user->id;
                $fs_user_token = $result->user_token->token;

                if ( ! $wp_user_found ) {
                    // Check if there's a user with a matching email address.
                    $user_by_email = get_user_by( 'email', $email );

                    if ( is_object( $user_by_email ) ) {
                        $user = $user_by_email;
                    } else {
                        /**
                         * No user in WP with a matching email address. Therefore, create the user.
                         */
                        $username = strtolower( $fs_user->first . ( empty( $fs_user->last ) ? '' : '.' . $fs_user->last ) );

                        if ( empty( $username ) ) {
                            $username = substr( $fs_user->email, 0, strpos( $fs_user->email, '@' ) );
                        }

                        $username = $this->generate_unique_username( $username );

                        $user_id = wp_create_user( $username, $password, $email );

                        if ( is_wp_error( $user_id ) ) {
                            return $user;
                        }

                        $user = get_user_by( 'ID', $user_id );

                        $user->set_role( 'subscriber' );
                        $user->add_role( 'edd_subscriber' );
                    }
                }

                /**
                 * Store the token and user ID locally.
                 */
                update_user_meta( $user->ID, 'fs_token', $fs_user_token );
                update_user_meta( $user->ID, 'fs_user_id', $fs_user_id );
            }

            return $user;
        }

        /**
         * Clean up the stored user access token.
         */
        public function clear_access_token() {
            delete_user_meta( get_current_user_id(), 'fs_token' );
        }

        /**
         * Current logged in user's Freemius user ID.
         *
         * @return number
         */
        public function get_freemius_user_id() {
            return get_user_meta( get_current_user_id(), 'fs_user_id', true );
        }

        /**
         * Current logged in user's Freemius access token.
         *
         * @return object
         */
        public function get_freemius_access_token() {
            return get_user_meta( get_current_user_id(), 'fs_token', true );
        }

        #region Helper Methods

        /**
         * @param string $email
         * @param string $password
         *
         * @return array|WP_Error
         */
        private function fetch_user_access_token( $email, $password = '' ) {
            $api_root = $this->_use_localhost_api ?
                'http://api.freemius-local.com:8080' :
                'https://fast-api.freemius.com';

            // Fetch user's info and access token from Freemius.
            return wp_remote_post(
                "{$api_root}/v1/users/login.json",
                array(
                    'method'   => 'POST',
                    'blocking' => true,
                    'body'     => array(
                        'email'                => $email,
                        'password'             => $password,
                        'store_id'             => $this->_store_id,
                        'developer_id'         => $this->_developer_id,
                        'developer_secret_key' => $this->_developer_secret_key,
                    )
                )
            );
        }

        /**
         * @param string $base_username
         *
         * @return string
         */
        private function generate_unique_username( $base_username ) {
            // Sanitize.
            $base_username = sanitize_user( $base_username );

            $numeric_suffix = 0;

            do {
                $username = ( 0 == $numeric_suffix ) ?
                    $base_username :
                    sprintf( '%s%s', $base_username, $numeric_suffix );

                $numeric_suffix ++;
            } while ( username_exists( $username ) );

            return $username;
        }

        #endregion
    }

    FS_SSO::instance()->init(
        '<STORE_ID>',
        '<DEVELOPER_ID>',
        '<DEVELOPER_SECRET_KEY>'
    );