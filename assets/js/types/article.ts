export interface Article {
  id: number;
  title: string;
  content: string;
  createdAt: string;
}

export interface ArticleFormData {
  title: string;
  content: string;
}
