import type { SearchResult } from '../../hooks/use-content-search';

export type ResultItemProps = {
	result: SearchResult;
	isSelected: boolean;
	postTypeLabel: string;
	onSelect: ( result: SearchResult ) => void;
};
