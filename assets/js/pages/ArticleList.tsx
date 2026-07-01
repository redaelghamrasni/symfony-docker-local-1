import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { articleApi } from '../services/api';
import { Article } from '../types/article';

export default function ArticleList() {
  const queryClient = useQueryClient();

  // Récupérer les articles avec React Query
  const { data: articles, isLoading, error } = useQuery({
    queryKey: ['articles'],
    queryFn: articleApi.getAll,
  });

  // Mutation pour supprimer un article
  const deleteMutation = useMutation({
    mutationFn: articleApi.delete,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['articles'] });
    },
  });

  const handleDelete = (id: number) => {
    if (confirm('Êtes-vous sûr de vouloir supprimer cet article ?')) {
      deleteMutation.mutate(id);
    }
  };

  if (isLoading) {
    return (
      <div className="flex items-center justify-center min-h-screen">
        <div className="text-xl text-gray-600">Chargement...</div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="flex items-center justify-center min-h-screen">
        <div className="text-xl text-red-600">Erreur: {(error as Error).message}</div>
      </div>
    );
  }

  return (
    <div className="container mx-auto px-4 py-8">
      {/* Header */}
      <div className="flex justify-between items-center mb-8">
        <div>
          <h1 className="text-3xl font-bold text-gray-900">📚 Mes Articles</h1>
          <p className="text-gray-600 mt-2">Gérez et consultez tous vos articles</p>
        </div>
        <Link
          to="/articles/new"
          className="px-6 py-3 bg-blue-600 text-white rounded-lg font-semibold hover:bg-blue-700 transition-all shadow-lg hover:shadow-xl"
        >
          + Créer un article
        </Link>
      </div>

      {/* Stats */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
        <div className="bg-white rounded-xl border border-gray-200 p-6">
          <div className="text-sm font-medium text-gray-600 mb-2">Total</div>
          <div className="text-3xl font-bold text-gray-900">{articles?.length || 0}</div>
        </div>
        <div className="bg-white rounded-xl border border-gray-200 p-6">
          <div className="text-sm font-medium text-gray-600 mb-2">Cette semaine</div>
          <div className="text-3xl font-bold text-gray-900">
            {articles?.filter((a: Article) => {
              const weekAgo = new Date();
              weekAgo.setDate(weekAgo.getDate() - 7);
              return new Date(a.createdAt) > weekAgo;
            }).length || 0}
          </div>
        </div>
      </div>

      {/* Articles Grid */}
      {articles && articles.length === 0 ? (
        <div className="text-center py-20 bg-white rounded-xl border border-gray-200">
          <div className="text-6xl mb-4">📭</div>
          <h2 className="text-2xl font-semibold text-gray-900 mb-2">Aucun article</h2>
          <p className="text-gray-600 mb-6">Commencez par créer votre premier article</p>
          <Link
            to="/articles/new"
            className="inline-block px-6 py-3 bg-blue-600 text-white rounded-lg font-semibold hover:bg-blue-700 transition-all"
          >
            Créer un article
          </Link>
        </div>
      ) : (
        <div className="grid gap-6">
          {articles?.map((article: Article) => (
            <div
              key={article.id}
              className="bg-white rounded-xl border border-gray-200 p-6 hover:shadow-lg transition-all"
            >
              <div className="flex justify-between items-start mb-4">
                <h2 className="text-xl font-bold text-gray-900 flex-1">{article.title}</h2>
                <span className="px-3 py-1 bg-gray-100 text-gray-600 rounded-full text-sm font-medium">
                  #{article.id}
                </span>
              </div>

              <p className="text-gray-600 mb-4 line-clamp-2">{article.content}</p>

              <div className="flex justify-between items-center pt-4 border-t border-gray-200">
                <div className="text-sm text-gray-500">
                  📅 {new Date(article.createdAt).toLocaleDateString('fr-FR')}
                </div>

                <div className="flex gap-2">
                  <Link
                    to={`/articles/${article.id}`}
                    className="px-4 py-2 text-blue-600 border border-blue-200 rounded-lg hover:bg-blue-50 transition-all text-sm font-medium"
                  >
                    Voir
                  </Link>
                  <Link
                    to={`/articles/${article.id}/edit`}
                    className="px-4 py-2 text-amber-600 border border-amber-200 rounded-lg hover:bg-amber-50 transition-all text-sm font-medium"
                  >
                    Modifier
                  </Link>
                  <button
                    onClick={() => handleDelete(article.id)}
                    className="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-all text-sm font-medium"
                  >
                    Supprimer
                  </button>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
