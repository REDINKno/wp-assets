<?php

namespace muplugins\assets;

use WP_CLI_Command;

if( class_exists( 'WP_CLI_Command' ) ):

class WPCLI extends WP_CLI_Command
{
    public function flush($args, $assoc_args)
    {
        if( ! empty( $assoc_args[ 'network' ] ) ) {
            $this->_flush_network();
        }
        else {
            $this->_flush_single( get_current_blog_id() );
        }
    }

    private function _flush_network()
    {
        foreach( wp_get_sites() as $site ) {
            $this->_flush_single( $site['blog_id'] );
        }
    }

    private function _flush_single( $blog_id )
    {
        switch_to_blog( $blog_id );

        if (wp_using_ext_object_cache()) {
            wp_cache_flush();
        }

        do_action( 'assets_flush' );

        restore_current_blog();
    }
}

endif;