import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, Placeholder } from '@wordpress/components';
import { applyFilters } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';
import { ContentSearch } from '../components/content-search';
import { SelectedPost } from '../components/selected-post';

/**
 * @param {Object}   props
 * @param {Object}   props.attributes
 * @param {Function} props.setAttributes
 */
export default function Edit( { attributes, setAttributes } ) {
	const { postId, postTitle, postType } = attributes;
	const blockProps = useBlockProps( { className: 'dmg-read-more' } );

	const removeSelectedPost = () => {
		setAttributes( {
			postId: undefined,
			postTitle: '',
			postType: undefined,
		} );
	};

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Post selection', 'dmg-read-more' ) }>
					<SelectedPost
						postId={ postId }
						postTitle={ postTitle }
						postType={ postType }
						onRemove={ removeSelectedPost }
					/>
					<ContentSearch
						selectedId={ postId }
						onSelect={ ( result ) =>
							setAttributes( {
								postId: result.id,
								postTitle: result.title,
								postType: result.postType,
							} )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<>
				{ ! postId ? (
					<Placeholder
						label={ __( 'DMG Read More', 'dmg-read-more' ) }
						instructions={ __(
							'Select a post to link to using the block settings panel.',
							'dmg-read-more'
						) }
					/>
				) : (
					<>
						<p { ...blockProps }>
							{ /* eslint-disable-next-line jsx-a11y/anchor-is-valid -- editor-only WYSIWYG preview; not an interactive link */ }
							<a>
								<span className="dmg-read-more__prefix">
									{ applyFilters(
										'dmg_read_more_prefix',
										__( 'Read More:', 'dmg-read-more' )
									) }
								</span>{ ' ' }
								<span className="dmg-read-more__title">
									{ postTitle }
								</span>
							</a>
						</p>
					</>
				) }
			</>
		</>
	);
}
