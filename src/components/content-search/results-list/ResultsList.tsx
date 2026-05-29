import { __, sprintf } from '@wordpress/i18n';
import { Button, Spinner } from '@wordpress/components';
import { ResultItem } from '../../result-item';
import type { ResultsListProps } from './types';

import { resultsListStyles as S } from './styles';

export function ResultsList( {
	results,
	isLoading,
	error,
	page,
	totalPages,
	selectedId,
	debouncedQuery,
	postTypes,
	onSelect,
	onPageChange,
}: ResultsListProps ) {
	const trimmed = debouncedQuery.trim();
	const isIdMode = /^\d+$/.test( trimmed ) && parseInt( trimmed, 10 ) > 0;

	let statusLabel: string;
	if ( ! trimmed ) {
		statusLabel = __( 'Recent posts', 'dmg-read-more' );
	} else if ( isIdMode ) {
		statusLabel = sprintf(
			/* translators: %s: post ID */
			__( 'ID: %s', 'dmg-read-more' ),
			trimmed
		);
	} else {
		statusLabel = sprintf(
			/* translators: %s: search keyword */
			__( 'Keyword: "%s"', 'dmg-read-more' ),
			trimmed
		);
	}

	const postTypeLabel = ( slug: string ): string =>
		postTypes.find( ( pt ) => pt.slug === slug )?.label ?? slug;

	const hasResults = ! isLoading && ! error && results.length > 0;

	return (
		<div>
			<div role="status" aria-live="polite" aria-atomic="true">
				<div style={ S.statusRow }>
					<p style={ S.statusLabel }>{ statusLabel }</p>
					{ isLoading && (
						<Spinner
							aria-label={ __(
								'Loading posts…',
								'dmg-read-more'
							) }
						/>
					) }
				</div>

				{ ! isLoading && error && (
					<p style={ S.errorText }>{ error }</p>
				) }

				{ ! hasResults && (
					<p style={ S.emptyText }>
						{ __( 'No posts found.', 'dmg-read-more' ) }
					</p>
				) }
			</div>

			{ hasResults && (
				<ul style={ S.list } aria-label={ statusLabel }>
					{ results.map( ( result ) => (
						<li key={ result.id }>
							<ResultItem
								result={ result }
								isSelected={ result.id === selectedId }
								postTypeLabel={ postTypeLabel(
									result.postType
								) }
								onSelect={ () => onSelect( result ) }
							/>
						</li>
					) ) }
				</ul>
			) }

			{ ! isIdMode && totalPages > 1 && (
				<div style={ S.pagination }>
					<Button
						variant="tertiary"
						size="small"
						onClick={ () => onPageChange( ( p ) => p - 1 ) }
						disabled={ page <= 1 || isLoading }
						aria-label={ __( 'Previous page', 'dmg-read-more' ) }
					>
						{ __( '← Previous', 'dmg-read-more' ) }
					</Button>
					<span style={ S.pageIndicator }>
						{ sprintf(
							/* translators: 1: current page number, 2: total pages */
							__( '%1$d / %2$d', 'dmg-read-more' ),
							page,
							totalPages
						) }
					</span>
					<Button
						variant="tertiary"
						size="small"
						onClick={ () => onPageChange( ( p ) => p + 1 ) }
						disabled={ page >= totalPages || isLoading }
						aria-label={ __( 'Next page', 'dmg-read-more' ) }
					>
						{ __( 'Next →', 'dmg-read-more' ) }
					</Button>
				</div>
			) }
		</div>
	);
}
