<?php

namespace muplugins\assets;

class Styles extends AbstractAssets
{
    protected $_option_key = '__assets_css';
    protected $_page_styles_key = null;
    protected $_styles = array();
    protected $_inline_scripts_styles = array();

    protected $_assets_files_to_move = array();

    protected $_miss_from_cache = false;
    protected $_miss_from_cache_items = array();

    public function register_hooks()
    {
        $this->_register_flush_hooks();

        if( ! is_admin() ) {

            add_action( 'wp_print_styles', function () {
                global $wp_styles;

                if ( empty( $wp_styles->queue ) ) {
                    return;
                }

                $wp_styles->all_deps( $wp_styles->queue );

                $handles = array();
                foreach ( $wp_styles->to_do as $key => $handle ) {

                    if ( empty( $wp_styles->registered[ $handle ]->src ) ) {
                        continue;
                    }

                    //don't store external styles
                    if ( $this->is_external_url( $wp_styles->registered[ $handle ]->src ) ) {
                        continue;
                    }

                    $handles[] = $handle;

                    $media = $wp_styles->registered[ $handle ]->args;
                    if ( empty( $media ) ) {
                        $media = 'all';
                    }

                    $attr = $wp_styles->registered[ $handle ]->extra;
                    if( ! empty( $attr[ 'conditional' ] ) ) {
                        $media .= '::' . $attr[ 'conditional' ];
                    }

                    if( ! empty( $attr[ 'after' ] ) && is_array( $attr[ 'after' ] ) ) {
                        $this->_inline_scripts_styles[ $media ] = array_merge( (array)$this->_inline_scripts_styles[ $media ], $attr[ 'after' ] );
                    }

                    $src = $wp_styles->registered[ $handle ]->src;
                    if( '/wp-' == mb_substr( $src, 0, 4 ) ) {
                        $src = rtrim( get_home_url(), '/' ) . $src;
                    }

                    $this->_styles[$media][] = $src;

                    $this->_miss_from_cache_items[] = array(
                        'handle' => $handle,
                        'key' => $key
                    );

                }

                $this->_page_styles_key = md5( implode( '-', $handles ) );

                $this->_update_styles();

                if( ! $this->_miss_from_cache ) {

                    foreach( $this->_miss_from_cache_items as $item ) {
                        $wp_styles->dequeue( $item[ 'handle' ] );
                        $wp_styles->done[] = $item[ 'handle' ];
                        unset( $wp_styles->to_do[ $item[ 'key' ] ] );
                    }
                }

            });

            add_action( 'wp_head', function() {
                if( ! $this->_miss_from_cache ) {
                    $this->print_styles();
                }
            });
        }

    }


    public function print_styles()
    {
        foreach( array_keys( $this->_styles ) as $media ) {
            if( $url = $this->get_style_url( $media ) ) {
                if( mb_strpos( $media, '::' ) !== false ) {
                    list ($m, $conditional ) = explode( '::', $media );
                    echo "<!--[if $conditional]>\n";
                    $this->_print_style( $url, $m, @$this->_inline_scripts_styles[ $media ]  );
                    echo "<![endif]-->\n";
                }
                else {
                    $this->_print_style( $url, $media, @$this->_inline_scripts_styles[ $media ] );
                }
            }
        }
    }

    private function _print_style($url, $media, $inline )
    {
        echo "<link rel=\"stylesheet\" href=\"$url\" type=\"text/css\" media=\"$media\" />\n";
        if( ! empty( $inline ) ) {
            $output = implode( "\n", $inline );
            echo "<style type=\"text/css\">$output</style>\n";
        }
    }

    public function get_style_url( $media )
    {
        $files = $this->_get_files();
        $key = $this->_get_style_key( $media );

        if ( ! array_key_exists( $key, $files ) ) {
            return false;
        }

        $url = $files[ $key ];

        if( function_exists( 'get_cloudfront_attachment_url' ) ) {
            $url = get_cloudfront_attachment_url( $url );
        }

        return $url;
    }


    protected function _replace_relative_assets_path( $data, $url )
    {
        $base = dirname( $url );

        $data = preg_replace_callback( '#(url\([\'"]?)(.*?)([\'"]?\))#', function( $string ) use( $base ) {

            if( ! isset( $string[2] ) ) {
                return reset( $string );
            }

            $url = trim( $string[2] );

            if( mb_strpos( $url, "/" ) === 0 ) {
                return reset( $string ); // WAT?
            }
            elseif( mb_strpos( $url, "./" ) === 0 ) {
                $url = mb_substr( $url, 1 );
            }
            elseif( mb_strpos( $url, ".." ) === 0 ) {
                $attachment_url_parts = array_filter( explode('..', $url) );
                for( $i = 0; $i < count( $attachment_url_parts ); $i++ ) {
                    $base = dirname( $base );
                }
                $url = end($attachment_url_parts);
            }
            else {
                return reset( $string ); // external url
            }

            $url = $this->_moved_assets_url( $base . $url );

            return "url($url)";
        }, $data );

        return $data;
    }

    protected function _get_minimized_styles( $media )
    {
        if( empty( $this->_styles[ $media ] ) ) {
            return null;
        }

        $content = '';
        foreach( $this->_styles[ $media ] as $src ) {

            if( $path = $this->_url_to_path( $src ) ) {
                $body = file_get_contents( $path );
                $content .= $this->_replace_relative_assets_path( $body, $src )  . "\n\n";
            }
            else {
                $response = wp_remote_get( add_query_arg( array( 'time' => time() ), $src ) );
                if( $body = wp_remote_retrieve_body( $response ) ) {
                    $content .= $this->_replace_relative_assets_path( $body, $src ) . "\n\n";
                }
            }
        }

        $content = $this->minify( $content );

        return "/* Generated at ". current_time( 'mysql' ) . " */\n\n" . $content;
    }

    protected function _update_styles()
    {
        $lock_key = 'update_styles';

        $files = $this->_get_files();
        foreach ( array_keys( $this->_styles ) as $media ) {
            $key = $this->_get_style_key( $media );
            if ( ! array_key_exists( $key, $files ) ) {

                if( $this->_is_locked( $lock_key ) ) {
                    $this->_miss_from_cache = true;
                    return;
                }
                $this->_lock( $lock_key );

                if( $content = $this->_get_minimized_styles( $media ) ) {

                    $filename = $key . '.css';
                    $file_path = $this->get_assets_dir() . DIRECTORY_SEPARATOR . 'stylesheets' . DIRECTORY_SEPARATOR . $filename;
                    $file_url = $this->get_assets_url() . '/stylesheets/' . $filename . '?' . time();

                    $dir = dirname($file_path);

                    if (!is_dir($dir)) {
                        wp_mkdir_p($dir);
                    }

                    if( defined( 'AWS_CLOUDFRONT_DOMAIN' ) ) {
                        $content = gzencode( $content, 9 );
                    }

                    if ( file_put_contents( $file_path, $content ) ) {
                        $this->_set_file( $key, $file_url );
                    }
                    else {
                        $this->_miss_from_cache = true;
                    }
                }

                $this->_unlock( $lock_key );
            }
        }
    }

    protected function _get_style_key( $media )
    {
        $key = sanitize_key( $media ) . '-' . $this->_page_styles_key;
        if( defined( 'AWS_CLOUDFRONT_DOMAIN' ) ) {
            $key .= '.gz';
        }
        return $key;
    }

    protected function _moved_assets_url( $url )
    {
        if( ! array_key_exists( $url, $this->_assets_files_to_move ) ) {

            $key = mb_substr( md5( rand(1, 100) ), - 5 ) . '-' . basename( $url );

            $key = strtok( $key, '?' ); // clear url params
            $key = strtok( $key, '#' );

            $file_path = $this->get_assets_dir() . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . $key;

            $dir = dirname( $file_path );

            if( ! is_dir( $dir ) ) {
                wp_mkdir_p( $dir );
            }

            if( ! ( $content = file_get_contents( $url ) ) || ! file_put_contents( $file_path, $content ) ) {
                return $url;
            }

            $this->_assets_files_to_move[ $url ] = $this->get_assets_url() . '/files/' . $key;

            if( function_exists( 'get_cloudfront_attachment_url' ) ) {
                $this->_assets_files_to_move[ $url ] = get_cloudfront_attachment_url( $this->_assets_files_to_move[ $url ] );
            }
        }

        return $this->_assets_files_to_move[ $url ];
    }


    public function flush()
    {
        if( delete_option( $this->_option_key ) ) {

            $dir = $this->get_assets_dir() . DIRECTORY_SEPARATOR . 'files';
            if (is_dir($dir)) {
                array_map('unlink', glob($dir . DIRECTORY_SEPARATOR . '*'));
            }

            $dir = $this->get_assets_dir() . DIRECTORY_SEPARATOR . 'stylesheets';
            if (is_dir($dir)) {
                array_map('unlink', glob($dir . DIRECTORY_SEPARATOR . '*'));
            }
        }
    }
}
