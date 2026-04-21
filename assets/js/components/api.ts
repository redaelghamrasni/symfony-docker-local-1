import axios from 'axios';

const api = axios.create({
  baseURL: 'http://localhost:8000',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
});

export interface Article {
  id: number;
  title: string;
  content: string;
  createdAt: string;
}

export const articlesApi = {
  getAll: async (): Promise<Article[]> => {
    const response = await api.get('/api/articles');
    return response.data['hydra:member'] || response.data;
  },

  getById: async (id: number): Promise<Article> => {
    const response = await api.get(`/api/articles/${id}`);
    return response.data;
  },
};

export default api;
