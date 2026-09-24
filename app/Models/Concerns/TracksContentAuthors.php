<?php

namespace App\Models\Concerns;

use App\Models\Household;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Keep field provenance on the server, including earlier contributors when an
 * edit may contain their text. No copies of previous content are retained.
 */
trait TracksContentAuthors
{
    protected ?int $contentAuthorId = null;

    protected bool $skipContentAttribution = false;

    protected bool $storedCopyIsCurrent = false;

    /** @return array<string, mixed> */
    abstract public function contentErasureDefaults(): array;

    public function initializeTracksContentAuthors(): void
    {
        $this->mergeCasts(['content_authors' => 'array', 'erasure_version' => 'integer']);
        $this->makeHidden(['content_authors', 'erasure_version']);
    }

    public static function bootTracksContentAuthors(): void
    {
        static::saving(function (self $model): void {
            if ($model->skipContentAttribution) {
                $model->skipContentAttribution = false;

                return;
            }
            $authorId = $model->contentAuthorId ?? Auth::id();
            $model->contentAuthorId = null;
            $loadedUnderLock = $model->storedCopyIsCurrent;
            $model->storedCopyIsCurrent = false;
            if ($authorId === null) {
                return;
            }
            if (! $model->exists && ! $model instanceof Household && $model->created_by_user_id === null) {
                $model->setAttribute('created_by_user_id', $authorId);
            }
            $fields = array_keys(array_intersect_key($model->getDirty(), $model->contentErasureDefaults()));
            if ($model->exists && $fields !== []) {
                // A row read while holding its household's lock is already the
                // stored copy: erasures take that lock before changing a row.
                $stored = $loadedUnderLock ? null : $model->newQueryWithoutScopes()->find($model->getKey());
                if ($stored !== null && (int) $stored->erasure_version > (int) $model->getOriginal('erasure_version')) {
                    abort(409, 'This content changed after an account deletion. Refresh it before editing.');
                }
                $authors = $model->content_authors ?? [];
                foreach ($stored?->content_authors ?? [] as $previousAuthor => $previousFields) {
                    $authors[$previousAuthor] = array_values(array_unique(array_merge($authors[$previousAuthor] ?? [], $previousFields)));
                }
                $model->setAttribute('content_authors', $authors ?: null);
            }
            if ($fields !== []) {
                $authors = $model->content_authors ?? [];
                $authorKey = 'user_'.$authorId;
                $authors[$authorKey] = array_values(array_unique(array_merge($authors[$authorKey] ?? [], $fields)));
                $model->setAttribute('content_authors', $authors);
            }
        });
    }

    public function attributeContentTo(int $userId): static
    {
        $this->contentAuthorId = $userId;

        return $this;
    }

    /**
     * Skip re-reading the stored row before saving: the caller loaded it while
     * holding the household lock, so no erasure can have changed it since.
     */
    public function loadedUnderHouseholdLock(): static
    {
        $this->storedCopyIsCurrent = true;

        return $this;
    }

    public function withoutContentAttribution(): static
    {
        $this->skipContentAttribution = true;

        return $this;
    }

    /** @param array<string, string> $fields destination field => source field */
    public function inheritContentAuthors(Model $source, array $fields): static
    {
        $authors = $this->content_authors ?? [];
        foreach ($source->content_authors ?? [] as $userId => $sourceFields) {
            foreach ($fields as $destination => $sourceField) {
                if (in_array($sourceField, $sourceFields, true)) {
                    $authors[$userId] = array_values(array_unique([...($authors[$userId] ?? []), $destination]));
                }
            }
        }
        $this->setAttribute('content_authors', $authors);

        return $this;
    }
}
