<?php

namespace muplugins\assets;

abstract class AbstractAssets
{
    protected $_option_key = null;

    private $_assets_url = null;
    private $_assets_dir = null;

    abstract public function flush();

    protected function _get_files()
    {
        return get_option( $this->_option_key, array() );
    }

    protected function _set_files( $files )
    {
        return update_option( $this->_option_key, $files );
    }

    protected function _set_file( $key, $file )
    {
        $files = $this->_get_files();
        $files[ $key ] = $file;
        $this->_set_files( $files );
    }

    public function get_assets_url()
    {
        if( null == $this->_assets_url ) {
            $upload_dir = wp_upload_dir();
            $this->_assets_url = $upload_dir[ 'baseurl' ] . '/assets';
        }
        return $this->_assets_url;
    }

    public function get_assets_dir()
    {
        if( null == $this->_assets_dir ) {
            $upload_dir = wp_upload_dir();
            $this->_assets_dir = $upload_dir[ 'basedir' ] . DIRECTORY_SEPARATOR . 'assets';
        }
        return $this->_assets_dir;
    }

    protected function _register_flush_hooks()
    {
        add_action( 'activate_plugin', array( $this, 'flush' ) );
        add_action( 'activated_plugin', array( $this, 'flush' ) );
        add_action( 'switch_theme', array( $this, 'flush' ) );
        add_action( 'assets_flush', array( $this, 'flush' )  );
    }

    public function is_external_url( $url )
    {
        $url_host = parse_url( $url, PHP_URL_HOST );
        $base_host = parse_url( home_url() , PHP_URL_HOST );
        return $url_host != $base_host && $url_host;
    }

    protected function _url_to_path( $url )
    {
        $url = set_url_scheme( $url );

        $file = false;

        if( false !== mb_strpos( $url, '/wp-content/' ) ) {
            $file = WP_CONTENT_DIR . mb_substr( $url, mb_strpos( $url, '/wp-content/' ) + 11 );
        }
        elseif( false !== mb_strpos( $url, '/wp-includes/' ) ) {
            $file = ABSPATH . WPINC . mb_substr( $url, mb_strpos( $url, '/wp-includes/' ) + 12 );
        }
        if( false !== mb_strpos( $url, '/wp-admin/' ) ) {
            $file = ABSPATH . mb_substr( $url, mb_strpos( $url, '/wp-admin/' ) + 1 );
        }

        if( $file && ! file_exists( $file ) ) {
            $file = false;
        }

        return $file;
    }

    public function minify( $content )
    {
        return $content; //str_replace( array("\r\n", "\r", "\n", "\t", '  ', '    ', '    '), '', preg_replace( '!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $content ) );
    }

    protected function _is_locked( $key )
    {
        $key = '__lock' . $this->_option_key . '-' . sanitize_key( $key );
        return get_transient( $key ) != false;
    }

    protected function _lock( $key )
    {
        $key = '__lock' . $this->_option_key . '-' . sanitize_key( $key );
        set_transient( $key, 'yes', 20 );
    }

    protected function _unlock( $key )
    {
        $key = '__lock' . $this->_option_key . '-' . sanitize_key( $key );
        delete_transient( $key );
    }

}

