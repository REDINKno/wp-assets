<?php

namespace muplugins\assets;

class Scripts extends AbstractAssets
{
    protected $_option_key = '__assets_js';

    protected $_page_scripts_key = null;
    protected $_inline_scripts = array( 'header' => array(), 'footer' => array() );
    protected $_scripts = array( 'header' => array(), 'footer' => array() );

    protected $_miss_from_cache = false;
    protected $_miss_from_cache_items = array();

    public function register_hooks()
    {
        $this->_register_flush_hooks();

        if( ! is_admin() ) {

            add_action('wp_print_scripts', function() {
                global $wp_scripts;

                if (empty($wp_scripts->queue)){
                    return;
                }

                $wp_scripts->all_deps($wp_scripts->queue);

                $handles = array();
                foreach($wp_scripts->to_do as $key => $handle) {

                    if( empty( $wp_scripts->registered[ $handle ]->src ) ) {
                        continue;
                    }

                    //don't store external scripts
                    if( $this->is_external_url( $wp_scripts->registered[ $handle ]->src ) ) {
                        continue;
                    }

                    $handles[] = $handle;

                    if ( empty( $wp_scripts->registered[ $handle ]->extra ) && empty ( $wp_scripts->registered[ $handle ]->args ) ) {
                        $where = 'header';
                    }
                    else {
                        $where = 'footer';
                    }

                    $src = $wp_scripts->registered[ $handle ]->src;
                    if( '/wp-' == mb_substr( $src, 0, 4 ) ) {
                        $src = rtrim( get_home_url(), '/' ) . $src;
                    }

                    $this->_scripts[ $where ][] = $src;

                    if( isset( $wp_scripts->registered[ $handle ]->extra[ 'data' ] ) ) {
                        $this->_inline_scripts[ $where ][] = $wp_scripts->registered[ $handle ]->extra[ 'data' ];
                    }

                    $this->_miss_from_cache_items[] = array(
                        'handle' => $handle,
                        'key' => $key
                    );
                }

                $this->_page_scripts_key = md5( implode( '-', $handles ) );

                $this->_update_scripts();

                if( ! $this->_miss_from_cache ) {

                    foreach( $this->_miss_from_cache_items as $item ) {
                        $wp_scripts->dequeue( $item[ 'handle' ] );
                        $wp_scripts->done[] = $item[ 'handle' ];
                        unset( $wp_scripts->to_do[ $item[ 'key' ] ] );
                    }
                }

            });

            add_action( 'wp_head', function() {
                if( ! $this->_miss_from_cache ) {
                    $this->print_scripts('header');
                }
            });

            add_action( 'wp_footer', function() {
                if( ! $this->_miss_from_cache ) {
                    $this->print_scripts('footer');
                }
            });

        }

    }

    public function print_scripts( $where )
    {
        if( ! empty( $this->_inline_scripts[ $where ] ) ) {
            $source = implode( "\n", $this->_inline_scripts[ $where ] );
            print "<script>$source</script>\n";
        }

        if( $url = $this->get_script_url( $where ) ) {
            print "<script src=\"$url\"></script>\n";
        }
    }

    public function get_script_url( $where )
    {
        $files = $this->_get_files();
        $key = $this->_get_script_key( $where );

        if ( ! array_key_exists( $key, $files ) ) {
            return false;
        }

        $url = $files[ $key ];

        if( function_exists( 'get_cloudfront_attachment_url' ) ) {
            $url = get_cloudfront_attachment_url( $url );
        }

        return $url;
    }

    protected function _get_script_key( $where )
    {
        $key = sanitize_key( $where ) . '-' . $this->_page_scripts_key;
        if( defined( 'AWS_CLOUDFRONT_DOMAIN' ) ) {
            $key .= '.gz';
        }
        return $key;
    }


    protected function _update_scripts()
    {
        $lock_key = 'update_scripts';

        $files = $this->_get_files();

        foreach ( array_keys( $this->_scripts ) as $where ) {
            $key = $this->_get_script_key( $where );
            if ( ! array_key_exists( $key, $files ) ) {

                if( $this->_is_locked( $lock_key ) ) {
                    $this->_miss_from_cache = true;
                    return;
                }

                $this->_lock( $lock_key );

                if( $content = $this->_get_minimized_scripts( $where ) ) {

                    $filename = $key . '.js';
                    $file_path = $this->get_assets_dir() . DIRECTORY_SEPARATOR . 'javascript' . DIRECTORY_SEPARATOR . $filename;
                    $file_url = $this->get_assets_url() . '/javascript/' . $filename . '?' . time();

                    $dir = dirname($file_path);

                    if (!is_dir($dir)) {
                        wp_mkdir_p($dir);
                    }

                    if( defined( 'AWS_CLOUDFRONT_DOMAIN' ) ) {
                        $content = gzencode( $content, 9 );
                    }

                    if( file_put_contents($file_path, $content) ) {
                        $this->_set_file($key, $file_url);
                    }
                    else {
                        $this->_miss_from_cache = true;
                    }
                }

                $this->_unlock( $lock_key );
            }
        }

    }

    protected function _get_minimized_scripts( $where )
    {
        if( empty( $this->_scripts[ $where ] ) ) {
            return null;
        }

        $content = '';
        foreach( $this->_scripts[ $where ] as $src ) {

            if( $path = $this->_url_to_path( $src ) ) {
                $body = file_get_contents( $path );
                $content .= $body . "\n\n";
            }
            else {
                $response = wp_remote_get( add_query_arg( array( 'time' => time() ), $src ) );
                if( $body = wp_remote_retrieve_body( $response ) ) {
                    $content .= $body . "\n\n";
                }
            }

        }

        $content = $this->minify( $content );

        return "/* Generated at " . current_time( 'mysql' ) . " */\n\n" . $content;
    }

    public function flush()
    {
        if( delete_option( $this->_option_key ) ) {

            $dir = $this->get_assets_dir() . DIRECTORY_SEPARATOR . 'javascript';
            if (is_dir($dir)) {
                array_map('unlink', glob($dir . DIRECTORY_SEPARATOR . '*'));
            }
        }
    }
}