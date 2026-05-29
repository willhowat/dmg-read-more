import type { SearchResult, PostTypeConfig } from '../../hooks/use-content-search';

export type { SearchResult, PostTypeConfig };

export type ContentSearchProps = {
	postTypes?: PostTypeConfig[];
	resultsPerPage?: number;
	selectedId?: number;
	onSelect: ( result: SearchResult ) => void;
};
