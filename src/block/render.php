<?php
/**
 * Render callback for the dmg/read-more block.
 *
 * @package dmg-read-more
 */

if ( empty( $attributes['postId'] ) ) {
	return;
}

$read_more_post = get_post( (int) $attributes['postId'] );
if ( ! $read_more_post || 'publish' !== $read_more_post->post_status ) {
	echo '<!-- dmg-read-more: post unavailable -->';
	return;
}

/**
 * Filters the "Read More" label prepended to the linked post title.
 * Themes and plugins can override this without needing a translation file.
 *
 * @param string $prefix Translatable default label.
 */
$read_more_prefix = apply_filters( 'dmg_read_more_prefix', __( 'Read More:', 'dmg-read-more' ) );

printf(
	'<p %s><a href="%s"><span class="dmg-read-more__prefix">%s</span> <span class="dmg-read-more__title">%s</span></a></p>',
	get_block_wrapper_attributes( [ 'class' => 'dmg-read-more' ] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() returns sanitized HTML attributes.
	esc_url( get_permalink( $read_more_post ) ),
	esc_html( $read_more_prefix ),
	esc_html( get_the_title( $read_more_post ) )
);
