<?php
if ( empty( $attributes['postId'] ) ) {
	return;
}

$post = get_post( (int) $attributes['postId'] );
if ( ! $post || $post->post_status !== 'publish' ) {
	echo '<!-- dmg-read-more: post unavailable -->';
	return;
}

$title              = esc_html( get_the_title( $post ) );
$url                = esc_url( get_permalink( $post ) );
$wrapper_attributes = get_block_wrapper_attributes( [ 'class' => 'dmg-read-more' ] );

/**
 * Filters the "Read More" label prepended to the linked post title.
 * Themes and plugins can override this without needing a translation file.
 *
 * @param string $prefix Translatable default label.
 */
$prefix = esc_html( apply_filters( 'dmg_read_more_prefix', __( 'Read More', 'dmg-read-more' ) ) );

printf(
	'<p %s><a href="%s"><span class="dmg-read-more__prefix">%s:</span> <span class="dmg-read-more__title">%s</span></a></p>',
	$wrapper_attributes,
	$url,
	$prefix,
	$title
);
