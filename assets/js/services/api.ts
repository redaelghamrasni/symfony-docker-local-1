import axios from 'axios';
import { Article, ArticleFormData } from '../types/article';

export type { Article, ArticleFormData } from '../types/article';

const API_BASE_URL = 'http://localhost:8000/api';

export const articleApi = {
  // Récupérer tous les articles
  getAll: async (): Promise<Article[]> => {
    const response = await axios.get(`${API_BASE_URL}/articles`);
    return response.data['hydra:member'];
  },

  // Récupérer un article par ID
  getOne: async (id: number): Promise<Article> => {
    const response = await axios.get(`${API_BASE_URL}/articles/${id}`);
    return response.data;
  },

  // Créer un article
  create: async (data: ArticleFormData): Promise<Article> => {
    const response = await axios.post(`${API_BASE_URL}/articles`, data);
    return response.data;
  },

  // Mettre à jour un article
  update: async (id: number, data: ArticleFormData): Promise<Article> => {
    const response = await axios.put(`${API_BASE_URL}/articles/${id}`, data);
    return response.data;
  },

  // Supprimer un article
  delete: async (id: number): Promise<void> => {
    await axios.delete(`${API_BASE_URL}/articles/${id}`);
  },
};
