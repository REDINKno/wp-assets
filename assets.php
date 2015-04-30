<?php

if( ! defined( 'DISABLE_ASSETS_MERGING' ) || DISABLE_ASSETS_MERGING == false ) {

    $styles = new muplugins\assets\Styles();
    $styles->register_hooks();

    $scripts = new muplugins\assets\Scripts();
    $scripts->register_hooks();


    if( class_exists( 'WP_CLI' ) ) {
        WP_CLI::add_command( 'assets', 'muplugins\assets\WPCLI' );
    }
}

