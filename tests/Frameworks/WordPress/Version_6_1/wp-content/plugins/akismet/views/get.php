<?php

//phpcs:disable VariableAnalysis
// There are "undefined" variables here because they're defined in the code that includes this file as a template.
?>

<form name="akismet_activate" action="https://akismet.com/get/" method="POST" target="_blank">
	<input type="hidden" name="passback_url" value="<?php echo esc_url( Akismet_Admin::get_page_url() ); ?>"/>
	<input type="hidden" name="blog" value="<?php echo esc_url( get_option( 'home' ) ); ?>"/>
	<input type="hidden" name="redirect" value="<?php echo isset( $redirect ) ? $redirect : 'plugin-signup'; ?>"/>
	<button type="submit" class="<?php echo isset( $classes ) && count( $classes ) > 0 ? esc_attr( implode( ' ', $classes ) ) : 'akismet-button'; ?>" value="<?php echo esc_attr( $text ); ?>"><?php echo esc_attr( $text ) . '<span class="screen-reader-text">' . esc_html__( '(opens in a new tab)', 'akismet' ) . '</span>'; ?></button>
</form>
