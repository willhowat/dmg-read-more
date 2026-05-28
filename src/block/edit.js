import { __ } from '@wordpress/i18n';
import { useBlockProps } from '@wordpress/block-editor';
import { Placeholder } from '@wordpress/components';

/**
 * @param {Object} props
 * @param {Object} props.attributes
 * @param {Function} props.setAttributes
 */
export default function Edit( { attributes, setAttributes } ) {
	return (
		<div { ...useBlockProps() }>
			<Placeholder
				label={ __( 'DMG Read More', 'dmg-read-more' ) }
				instructions={ __( 'Select a post to link to using the block settings panel.', 'dmg-read-more' ) }
			/>
		</div>
	);
}
