import React, { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { articleApi, Article } from '../services/api';

// Composant carte article
function ArticleCard({ article }: { article: Article }) {
  const [expanded, setExpanded] = useState(false);

  const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString('fr-FR', {
      day: '2-digit',
      month: 'long',
      year: 'numeric',
    });
  };

  return (
    <div className="bg-white rounded-xl border border-gray-200 p-6 hover:shadow-lg hover:border-blue-300 transition-all duration-300 group flex flex-col">
      {/* Header */}
      <div className="flex justify-between items-start mb-3">
        <h3 className="text-xl font-bold text-gray-900 group-hover:text-blue-600 transition-colors flex-1 pr-4">
          {article.title}
        </h3>
        <span className="text-xs font-semibold text-gray-400 bg-gray-100 px-2 py-1 rounded-md flex-shrink-0">
          #{article.id}
        </span>
      </div>

      {/* Contenu */}
      <p className={`text-gray-600 leading-relaxed flex-1 mb-4 ${expanded ? '' : 'line-clamp-3'}`}>
        {article.content}
      </p>

      {/* Bouton voir plus */}
      {article.content.length > 150 && (
        <button
          onClick={() => setExpanded(!expanded)}
          className="text-sm text-blue-600 hover:text-blue-700 font-medium mb-4 text-left"
        >
          {expanded ? '← Voir moins' : 'Voir plus →'}
        </button>
      )}

      {/* Footer */}
      <div className="flex items-center justify-between pt-4 border-t border-gray-100 mt-auto">
        <div className="flex items-center gap-2 text-sm text-gray-500">
          <span>📅</span>
          <span>{formatDate(article.createdAt)}</span>
        </div>
        
        <a
          href={`/fr/article/${article.id}`}
          className="inline-flex items-center gap-1 text-sm font-semibold text-white bg-blue-600 hover:bg-blue-700 px-4 py-2 rounded-lg transition-colors"
        >
          Voir
          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
          </svg>
        </a>
      </div>
    </div>
  );
}

// Composant skeleton loader
function SkeletonCard() {
  return (
    <div className="bg-white rounded-xl border border-gray-200 p-6 animate-pulse">
      <div className="flex justify-between items-start mb-3">
        <div className="h-6 bg-gray-200 rounded w-3/4"></div>
        <div className="h-6 bg-gray-200 rounded w-10"></div>
      </div>
      <div className="space-y-2 mb-4">
        <div className="h-4 bg-gray-200 rounded"></div>
        <div className="h-4 bg-gray-200 rounded w-5/6"></div>
        <div className="h-4 bg-gray-200 rounded w-4/6"></div>
      </div>
      <div className="flex justify-between items-center pt-4 border-t border-gray-100">
        <div className="h-4 bg-gray-200 rounded w-24"></div>
        <div className="h-8 bg-gray-200 rounded w-16"></div>
      </div>
    </div>
  );
}

// Composant principal
export default function ArticlesList() {
  const { data: articles, isLoading, error, refetch } = useQuery({
    queryKey: ['articles'],
    queryFn: articleApi.getAll,
  });

  return (
    <div className="min-h-screen bg-gray-50">
      <div className="max-w-6xl mx-auto px-4 py-12">

        {/* Header */}
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-10">
          <div>
            <h1 className="text-4xl font-bold text-gray-900 mb-2">
              📚 Articles
            </h1>
            <p className="text-gray-500">
              {isLoading
                ? 'Chargement...'
                : `${articles?.length || 0} article(s) trouvé(s)`
              }
            </p>
          </div>

          <div className="flex items-center gap-3">
            <button
              onClick={() => refetch()}
              className="inline-flex items-center gap-2 px-4 py-2.5 bg-white border border-gray-300 text-gray-700 text-sm font-semibold rounded-lg hover:bg-gray-50 transition-colors"
            >
              <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
              </svg>
              Actualiser
            </button>
            
            <a
              href="/fr/article/new"
              className="inline-flex items-center gap-2 px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold rounded-lg shadow-lg shadow-blue-600/25 transition-all hover:scale-105"
            >
              <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
              </svg>
              Nouvel article
            </a>
          </div>
        </div>

        {/* Erreur */}
        {error && (
          <div className="bg-red-50 border border-red-200 text-red-800 rounded-xl p-6 mb-8">
            <div className="flex items-center gap-3 mb-2">
              <span className="text-2xl">❌</span>
              <h3 className="font-bold text-lg">Erreur de chargement</h3>
            </div>
            <p className="text-sm">
              Impossible de charger les articles. Vérifiez que l'API fonctionne sur
              <a href="http://localhost:8000/api/articles" className="underline ml-1" target="_blank">
                /api/articles
              </a>
            </p>
            <button
              onClick={() => refetch()}
              className="mt-4 px-4 py-2 bg-red-600 text-white text-sm font-semibold rounded-lg hover:bg-red-700 transition-colors"
            >
              Réessayer
            </button>
          </div>
        )}

        {/* Loading */}
        {isLoading && (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            {[...Array(6)].map((_, i) => (
              <SkeletonCard key={i} />
            ))}
          </div>
        )}

        {/* État vide */}
        {!isLoading && !error && articles?.length === 0 && (
          <div className="bg-white rounded-2xl border border-gray-200 p-16 text-center">
            <div className="text-7xl mb-6">📭</div>
            <h2 className="text-2xl font-bold text-gray-900 mb-3">
              Aucun article pour le moment
            </h2>
            <p className="text-gray-500 mb-8">
              Commencez par créer votre premier article
            </p>
            
            <a
              href="/fr/article/new"
              className="inline-flex items-center gap-2 px-6 py-3 bg-blue-600 text-white font-semibold rounded-xl hover:bg-blue-700 transition-colors"
            >
              <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
              </svg>
              Créer mon premier article
            </a>
          </div>
        )}

        {/* Grille d'articles */}
        {!isLoading && !error && articles && articles.length > 0 && (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            {articles.map((article) => (
              <ArticleCard key={article.id} article={article} />
            ))}
          </div>
        )}

      </div>
    </div>
  );
}
