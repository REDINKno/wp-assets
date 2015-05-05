<?php

spl_autoload_register( function ( $class_name ) {

    if ( false !== mb_strpos( $class_name, "muplugins\\" ) ) {

        $class_name = mb_substr( $class_name, mb_strpos( $class_name, "muplugins\\" ) + 10 );
        $filename   = __DIR__ . DIRECTORY_SEPARATOR . ltrim( str_replace( "\\", DIRECTORY_SEPARATOR, $class_name), DIRECTORY_SEPARATOR ) . ".php";
        return include_once $filename;
    }

    return false;
});

