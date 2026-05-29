import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { SearchControl } from '@wordpress/components';
import { ResultsList } from './results-list';
import type { ContentSearchProps } from './types';
import { useContentSearch } from '../../hooks/use-content-search';
import { usePostTypes } from '../../hooks/use-post-types';

import { contentSearchStyles as S } from './styles';

export function ContentSearch( {
	postTypes: postTypesProp,
	resultsPerPage = 5,
	selectedId,
	onSelect,
}: ContentSearchProps ) {
	// Discover post types from the REST API, applying PHP and JS filters.
	// An explicit postTypes prop (e.g. in tests or a parent override) takes
	// precedence; otherwise the dynamically resolved list is used.
	const discoveredTypes = usePostTypes();
	const postTypes =
		postTypesProp !== undefined ? postTypesProp : discoveredTypes;
	const skipEmptyQuery = selectedId !== undefined;

	const {
		query,
		setQuery,
		debouncedQuery,
		results,
		isLoading,
		error,
		page,
		setPage,
		totalPages,
	} = useContentSearch( postTypes, resultsPerPage, skipEmptyQuery );

	useEffect( () => {
		if ( selectedId !== undefined ) {
			setQuery( '' );
		}
	}, [ selectedId, setQuery ] );

	const isIdle = skipEmptyQuery && ! query.trim();
	const displayResults =
		selectedId !== undefined
			? results.filter( ( r ) => r.id !== selectedId )
			: results;

	return (
		<div style={ S.root }>
			<SearchControl
				label={ __( 'Search posts', 'dmg-read-more' ) }
				value={ query }
				onChange={ setQuery }
				placeholder={ __(
					'Search by keyword or post ID…',
					'dmg-read-more'
				) }
				__nextHasNoMarginBottom
			/>
			{ isIdle ? (
				<p style={ S.idleHint }>
					{ __(
						'Type to search for a different post.',
						'dmg-read-more'
					) }
				</p>
			) : (
				<ResultsList
					results={ displayResults }
					isLoading={ isLoading }
					error={ error }
					page={ page }
					totalPages={ totalPages }
					selectedId={ selectedId }
					debouncedQuery={ debouncedQuery }
					postTypes={ postTypes }
					onSelect={ onSelect }
					onPageChange={ setPage }
				/>
			) }
		</div>
	);
}
