import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { ResultItem } from '../result-item';
import type { SelectedPostProps } from './types';
import { selectedPostStyles as S } from './styles';

export function SelectedPost( { postId, postTitle, postType, onRemove }: SelectedPostProps ) {
	const { currentPostId, postStatus } = useSelect(
		( select ) => {
			// getEntityRecord resolves via REST API on first call, then serves from cache.
			// Light touch check for selected post status to avoid unnecessary calls,
			// but doesn't account for post status changing after selection.
			const record = postId && postType
				? select( 'core' ).getEntityRecord( 'postType', postType, postId )
				: null;
			return {
				currentPostId: select( 'core/editor' ).getCurrentPostId(),
				postStatus: record?.status ?? null,
			};
		},
		[ postId, postType ]
	);

	const isUnpublished = postStatus !== null && postStatus !== 'publish';

	return (
		<div style={ S.root }>
			<p style={ S.label }>
				{ postId
					? __( 'Selected post', 'dmg-read-more' )
					: __( 'No post selected', 'dmg-read-more' )
				}
			</p>
			{ postId ? (
				<>
					<div style={ S.itemWrapper }>
						<div style={ S.itemOverlay }>
							<ResultItem
								result={ {
									id: postId,
									title: postTitle,
									url: `?p=${ postId }`,
									postType: postType ?? 'post',
								} }
								isSelected={ true }
								postTypeLabel={ postType
									? postType.charAt( 0 ).toUpperCase() + postType.slice( 1 )
									: __( 'Post', 'dmg-read-more' )
								}
								onSelect={ () => {} }
							/>
						</div>
						<button
							type="button"
							onClick={ onRemove }
							aria-label={ __( 'Remove selected post', 'dmg-read-more' ) }
							style={ S.removeButton }
						>
							<span
								className="dashicons dashicons-no-alt"
								style={ S.removeIcon }
								aria-hidden="true"
							/>
						</button>
					</div>
					{ postId === currentPostId && (
						<p style={ S.warning }>
							<span
								className="dashicons dashicons-warning"
								style={ S.warningIcon }
								aria-hidden="true"
							/>
							{ __( 'This block links to the post it appears in.', 'dmg-read-more' ) }
						</p>
					) }
					{ isUnpublished && (
						<p style={ S.warning }>
							<span
								className="dashicons dashicons-warning"
								style={ S.warningIcon }
								aria-hidden="true"
							/>
							{ __( 'The selected post is not published and will not be displayed.', 'dmg-read-more' ) }
						</p>
					) }
				</>
			) : (
				<p style={ S.emptyHint }>
					{ __( 'Search below to select a post to link to.', 'dmg-read-more' ) }
				</p>
			) }
		</div>
	);
}
