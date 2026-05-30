import type { SearchResult, PostTypeConfig } from '../types';

export type ResultsListProps = {
	results: SearchResult[];
	isLoading: boolean;
	error: string | null;
	page: number;
	totalPages: number;
	selectedId: number | undefined;
	debouncedQuery: string;
	postTypes: PostTypeConfig[];
	onSelect: ( result: SearchResult ) => void;
	onPageChange: ( page: number | ( ( prev: number ) => number ) ) => void;
};
