import { memo, useMemo, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { ResultItemProps } from './types';

import { resultItemStyles as S } from './styles';

function ResultItemComponent( {
	result,
	isSelected,
	postTypeLabel,
	onSelect,
}: ResultItemProps ) {
	const displayUrl = useMemo( () => {
		try {
			const { pathname } = new URL( result.url );
			return pathname.replace( /\/$/, '' ) || '/';
		} catch {
			// Malformed URL — fall back to the raw value.
			return result.url;
		}
	}, [ result.url ] );

	const handleClick = useCallback(
		() => onSelect( result ),
		[ onSelect, result ]
	);

	return (
		<button
			type="button"
			className={ `dmg-read-more__result${
				isSelected ? ' is-selected' : ''
			}` }
			onClick={ handleClick }
			aria-pressed={ isSelected }
			style={ isSelected ? S.buttonSelected : S.buttonDefault }
		>
			<span style={ S.meta }>
				<span style={ S.title }>
					{ result.title || __( '(no title)', 'dmg-read-more' ) }
				</span>
				<span style={ S.url }>
					{ displayUrl } (id: { result.id })
				</span>
			</span>
			<span style={ S.pill }>{ postTypeLabel }</span>
		</button>
	);
}

export const ResultItem = memo( ResultItemComponent );
