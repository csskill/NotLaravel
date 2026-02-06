<?php

namespace Nraa\Services;

use Nraa\Models\Blog\Article;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

class BlogService
{

    /**
     * Return all articles
     * @return array
     */
    public function getArticles(int $skip = 0, int $limit = 10): array
    {
        return Article::find([], ['skip' => $skip, 'limit' => $limit, 'sort' => ['published_at' => -1]])->toArray();
    }

    /**
     * Return an article by ID
     * @param string $id
     * @return array|object|null
     */
    public function getArticleById(string $id): array|object|null
    {
        return Article::findOne(['_id' => new ObjectId($id)]);
    }

    /**
     * Return an article by slug
     * @param string $slug
     * @return array|object|null
     */
    public function getArticleBySlug(string $slug): array|object|null
    {
        return Article::findOne(['slug' => $slug]);
    }

    /**
     * Return the newest article
     * @return array|object|null
     */
    public function getNewestArticle(): array|object|null
    {
        return Article::findOne(['status' => 'published'], ['sort' => ['published_at' => -1]]);
    }

    /**
     * Return the newest article
     * @return array|object|null
     */
    public function getNewestFeaturedArticle(): array|object|null
    {
        return Article::findOne(['status' => 'published'], ['sort' => ['published_at' => -1]]);
    }

    /**
     * Create a new article
     * @param string $title
     * @param string $content
     * @param string $excerpt
     * @param string $author
     * @param string $slug
     * @param bool $featured
     * @param string $image
     * @param string $image_alt
     * @param string $image_caption
     * @param string $image_credit
     * @param string $image_credit_url
     * @param string $tags
     * @param string $category
     * @param string $status
     * @param string $visibility
     * @param string $seo_title
     * @param string $seo_description
     * @param string $seo_keywords
     * @param string $seo_image
     * @param string $seo_image_alt
     * @param string $seo_image_caption
     * @return array|object|null
     */
    public function createArticle(
        string $title,
        string $content,
        string $excerpt,
        string $author,
        string $slug = '',
        bool $featured = false,
        string $image = '',
        string $image_alt = '',
        string $image_caption = '',
        string $image_credit = '',
        string $image_credit_url = '',
        string $tags = '',
        string $category = 'news',
        string $status = 'published',
        string $visibility = 'public',
        string $seo_title = '',
        string $seo_description = '',
        string $seo_keywords = '',
        string $seo_image = '',
        string $seo_image_alt = '',
        string $seo_image_caption = '',
        int $read_time = 0
    ): array|object|null {
        $article = Article::create([
            'title' => $title,
            'content' => $content,
            'excerpt' => $excerpt,
            'author' => $author,
            'slug' => $this->slugify($title),
            'featured' => $featured,
            'image' => $image,
            'image_alt' => $image_alt,
            'image_caption' => $image_caption,
            'image_credit' => $image_credit,
            'image_credit_url' => $image_credit_url,
            'tags' => $tags,
            'category' => $category,
            'status' => $status,
            'visibility' => $visibility,
            'seo_title' => $seo_title ?: $title,
            'seo_description' => $seo_description ?: $excerpt,
            'seo_keywords' => $seo_keywords,
            'seo_image' => $seo_image ?: $image,
            'seo_image_alt' => $seo_image_alt ?: $image_alt,
            'seo_image_caption' => $seo_image_caption ?: $image_caption,
            'published_at' => new UTCDateTime(),
            'read_time' => $read_time
        ]);
        $article->save();
        return $article;
    }

    /**
     * Delete an article
     * @param array|object|null $article
     * @return void
     */
    public function deleteArticleById(string $id): void
    {
        $article = $this->getArticleById($id);
        $article->delete();
    }

    /**
     * Save an article
     * @param string $id
     * @param string $title
     * @param string $content
     * @param string $excerpt
     * @param string $author
     * @param string $slug
     * @param string $image
     * @param string $image_alt
     * @param string $image_caption
     * @param string $image_credit
     * @param string $image_credit_url
     * @param string $tags
     * @param string $category
     * @param string $status
     * @param string $visibility
     * @param string $seo_title
     * @param string $seo_description
     * @param string $seo_keywords
     * @param string $seo_image
     * @param string $seo_image_alt
     * @param string $seo_image_caption
     * @param int $read_time
     * @return void
     */
    public function updateArticleById(
        string $id,
        string $title,
        string $content,
        string $excerpt,
        string $author,
        string $slug = '',
        bool $featured = false,
        string $image = '',
        string $image_alt = '',
        string $image_caption = '',
        string $image_credit = '',
        string $image_credit_url = '',
        string $tags = '',
        string $category = '',
        string $status = '',
        string $visibility = '',
        string $seo_title = '',
        string $seo_description = '',
        string $seo_keywords = '',
        string $seo_image = '',
        string $seo_image_alt = '',
        string $seo_image_caption = '',
        int $read_time = 0
    ): array|object|null {
        $article = $this->getArticleById($id);
        $article->title = $title;
        $article->content = $content;
        $article->author = $author;
        $article->excerpt = $excerpt;
        $article->slug = $slug;
        $article->featured = $featured;
        $article->image = $image;
        $article->image_alt = $image_alt;
        $article->image_caption = $image_caption;
        $article->image_credit = $image_credit;
        $article->tags = $tags;
        $article->category = $category;
        $article->status = $status;
        $article->visibility = $visibility;
        $article->seo_title = $seo_title;
        $article->seo_description = $seo_description;
        $article->seo_keywords = $seo_keywords;
        $article->seo_image = $seo_image;
        $article->seo_image_alt = $seo_image_alt;
        $article->seo_image_caption = $seo_image_caption;
        $article->read_time = $read_time;
        $article->save();
        return $article;
    }

    /**
     * Update an article
     * @param array|object|null $article
     * @return void
     */
    public function updateArticle(array|object|null $article): void
    {
        $article->update();
    }

    /**
     * Slugify a string
     * @param string $title
     * @return string
     */
    private function slugify(string $title): string
    {
        // Convert to UTF-8, remove accents
        $slug = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);

        // Lowercase
        $slug = strtolower($slug);

        // Replace non letter or digits by hyphen
        $slug = preg_replace('~[^a-z0-9]+~', '-', $slug);

        // Trim hyphens from beginning and end
        $slug = trim($slug, '-');

        // Remove duplicate hyphens
        $slug = preg_replace('~-+~', '-', $slug);

        return $slug ?: 'n-a';
    }
}
