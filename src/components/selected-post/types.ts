export type SelectedPostProps = {
	postId: number | undefined;
	postTitle: string;
	postType: string | undefined;
	onRemove: () => void;
};
